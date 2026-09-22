"""Business operations. Anything touching money or stock is atomic here."""
from __future__ import annotations

import secrets
import sqlite3
from datetime import datetime, timedelta, timezone

from . import db
from .security import clean_text


class ShopError(Exception):
    """A failure the user is allowed to see."""


def now() -> datetime:
    return datetime.now(timezone.utc)


def utc(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        return datetime.fromisoformat(value).replace(tzinfo=timezone.utc)
    except ValueError:
        return None


def money(minor: int) -> str:
    return f"{minor / 100:.2f}"


def parse_money(text: str) -> int:
    """'12.34' -> 1234. Raises ShopError on anything else."""
    cleaned = (text or "").strip().replace(",", ".").replace(" ", "")
    if not cleaned:
        raise ShopError("empty amount")
    try:
        amount = float(cleaned)
    except ValueError as exc:
        raise ShopError("bad amount") from exc
    # inf and nan slip past float() and would blow up round()
    if amount != amount or amount in (float("inf"), float("-inf")):
        raise ShopError("bad amount")
    value = round(amount * 100)
    if value <= 0 or value > 100_000_000:
        raise ShopError("amount out of range")
    return int(value)


# ── users ─────────────────────────────────────────────────────────────────
def get_user(user_id: int) -> sqlite3.Row | None:
    return db.query_one("SELECT * FROM users WHERE id = ?", (user_id,))


def touch_user(user_id: int, username: str | None, first_name: str | None,
               referrer_id: int | None = None) -> sqlite3.Row:
    username = clean_text(username, 64)
    first_name = clean_text(first_name, 64)
    with db.tx() as c:
        row = c.execute("SELECT id FROM users WHERE id = ?", (user_id,)).fetchone()
        if row is None:
            # A referrer only counts at sign-up, and never yourself.
            valid_ref = None
            if referrer_id and referrer_id != user_id:
                if c.execute("SELECT 1 FROM users WHERE id = ?", (referrer_id,)).fetchone():
                    valid_ref = referrer_id
            c.execute(
                "INSERT INTO users(id, username, first_name, referred_by) VALUES(?,?,?,?)",
                (user_id, username, first_name, valid_ref),
            )
            if valid_ref:
                c.execute(
                    "UPDATE users SET referral_count = referral_count + 1 WHERE id = ?",
                    (valid_ref,),
                )
        else:
            c.execute(
                "UPDATE users SET username = ?, first_name = ?, "
                "last_seen_at = datetime('now') WHERE id = ?",
                (username, first_name, user_id),
            )
    return get_user(user_id)  # type: ignore[return-value]


def discount_percent(purchases: int) -> int:
    best = 0
    for chunk in db.get_setting("discount_tiers").split(","):
        if ":" not in chunk:
            continue
        threshold, percent = chunk.split(":", 1)
        try:
            if purchases >= int(threshold):
                best = max(best, int(percent))
        except ValueError:
            continue
    return min(best, 90)


# ── catalog ───────────────────────────────────────────────────────────────
def active_cities() -> list[sqlite3.Row]:
    return db.query(
        "SELECT c.*, ("
        "  SELECT COUNT(*) FROM stock_items s"
        "   JOIN products p ON p.id = s.product_id"
        "  WHERE p.city_id = c.id AND p.is_active = 1 AND s.status = 'available'"
        ") AS in_stock "
        "FROM cities c WHERE c.is_active = 1 ORDER BY c.position, c.name"
    )


def city_products(city_id: int) -> list[sqlite3.Row]:
    return db.query(
        "SELECT p.*, ("
        "  SELECT COUNT(*) FROM stock_items s"
        "  WHERE s.product_id = p.id AND s.status = 'available'"
        ") AS in_stock, ("
        "  SELECT d.name FROM districts d WHERE d.id = p.district_id"
        ") AS district_name "
        "FROM products p WHERE p.city_id = ? AND p.is_active = 1 "
        "ORDER BY p.position, p.name",
        (city_id,),
    )


def get_product(product_id: int) -> sqlite3.Row | None:
    return db.query_one(
        "SELECT p.*, c.name AS city_name, ("
        "  SELECT COUNT(*) FROM stock_items s"
        "  WHERE s.product_id = p.id AND s.status = 'available'"
        ") AS in_stock, ("
        "  SELECT d.name FROM districts d WHERE d.id = p.district_id"
        ") AS district_name "
        "FROM products p JOIN cities c ON c.id = p.city_id WHERE p.id = ?",
        (product_id,),
    )


def stock_summary() -> list[sqlite3.Row]:
    return db.query(
        "SELECT c.name AS city, p.name AS product, p.price, ("
        "  SELECT COUNT(*) FROM stock_items s"
        "  WHERE s.product_id = p.id AND s.status = 'available'"
        ") AS in_stock "
        "FROM products p JOIN cities c ON c.id = p.city_id "
        "WHERE p.is_active = 1 AND c.is_active = 1 "
        "ORDER BY c.position, c.name, p.position, p.name"
    )


# ── purchase ──────────────────────────────────────────────────────────────
def purchase(user_id: int, product_id: int) -> dict:
    """Debit the balance and hand over exactly one stock item.

    The whole thing is one BEGIN IMMEDIATE transaction, so concurrent buyers
    are serialised: the balance check, the stock claim and the ledger entry
    either all happen or none do.
    """
    window_hours = max(1, int(db.get_setting("dispute_window_hours", "3") or 3))
    with db.tx() as c:
        user = c.execute("SELECT * FROM users WHERE id = ?", (user_id,)).fetchone()
        if user is None:
            raise ShopError("no_user")
        if user["is_banned"]:
            raise ShopError("banned")

        product = c.execute(
            "SELECT * FROM products WHERE id = ? AND is_active = 1", (product_id,)
        ).fetchone()
        if product is None:
            raise ShopError("no_product")

        price = int(product["price"])
        percent = discount_percent(int(user["purchases"]))
        discount = price * percent // 100
        payable = price - discount
        if int(user["balance"]) < payable:
            raise ShopError("insufficient")

        # Claim one unit. The UPDATE ... WHERE status='available' is the real
        # guard: if another transaction took it first, rowcount is 0.
        item = c.execute(
            "SELECT id FROM stock_items WHERE product_id = ? AND status = 'available' "
            "ORDER BY id LIMIT 1",
            (product_id,),
        ).fetchone()
        if item is None:
            raise ShopError("out_of_stock")
        claimed = c.execute(
            "UPDATE stock_items SET status = 'sold', sold_at = datetime('now') "
            "WHERE id = ? AND status = 'available'",
            (item["id"],),
        )
        if claimed.rowcount != 1:
            raise ShopError("out_of_stock")

        new_balance = int(user["balance"]) - payable
        moved = c.execute(
            "UPDATE users SET balance = ?, purchases = purchases + 1 "
            "WHERE id = ? AND balance = ?",
            (new_balance, user_id, user["balance"]),
        )
        if moved.rowcount != 1:  # pragma: no cover - lock makes this unreachable
            raise ShopError("conflict")

        dispute_until = (now() + timedelta(hours=window_hours)).strftime("%Y-%m-%d %H:%M:%S")
        cur = c.execute(
            "INSERT INTO orders(user_id, product_id, stock_item_id, price, discount, "
            "paid, dispute_until) VALUES(?,?,?,?,?,?,?)",
            (user_id, product_id, item["id"], price, discount, payable, dispute_until),
        )
        order_id = int(cur.lastrowid)
        c.execute("UPDATE stock_items SET order_id = ? WHERE id = ?", (order_id, item["id"]))
        c.execute(
            "INSERT INTO ledger(user_id, amount, balance_after, kind, ref, note) "
            "VALUES(?,?,?,'purchase',?,?)",
            (user_id, -payable, new_balance, str(order_id), product["name"]),
        )
        payload = c.execute(
            "SELECT payload, photo FROM stock_items WHERE id = ?", (item["id"],)
        ).fetchone()

    return {
        "order_id": order_id,
        "price": price,
        "discount": discount,
        "paid": payable,
        "balance": new_balance,
        "payload": payload["payload"],
        "photo": payload["photo"],
        "product": dict(product),
        "dispute_until": dispute_until,
    }


def user_orders(user_id: int, limit: int = 10) -> list[sqlite3.Row]:
    return db.query(
        "SELECT o.*, p.name AS product_name, c.name AS city_name, "
        "       s.payload, s.photo, "
        "       (SELECT 1 FROM reviews r WHERE r.order_id = o.id) AS has_review, "
        "       (SELECT d.status FROM disputes d WHERE d.order_id = o.id) AS dispute_status "
        "FROM orders o "
        "JOIN products p ON p.id = o.product_id "
        "JOIN cities c ON c.id = p.city_id "
        "JOIN stock_items s ON s.id = o.stock_item_id "
        "WHERE o.user_id = ? ORDER BY o.id DESC LIMIT ?",
        (user_id, limit),
    )


def get_order(order_id: int, user_id: int | None = None) -> sqlite3.Row | None:
    sql = (
        "SELECT o.*, p.name AS product_name, c.name AS city_name, s.payload, s.photo, "
        "       (SELECT 1 FROM reviews r WHERE r.order_id = o.id) AS has_review, "
        "       (SELECT d.status FROM disputes d WHERE d.order_id = o.id) AS dispute_status "
        "FROM orders o "
        "JOIN products p ON p.id = o.product_id "
        "JOIN cities c ON c.id = p.city_id "
        "JOIN stock_items s ON s.id = o.stock_item_id "
        "WHERE o.id = ?"
    )
    params: tuple = (order_id,)
    if user_id is not None:
        sql += " AND o.user_id = ?"
        params += (user_id,)
    return db.query_one(sql, params)


def dispute_open_allowed(order: sqlite3.Row) -> bool:
    deadline = utc(order["dispute_until"])
    return bool(deadline and now() <= deadline)


# ── disputes ──────────────────────────────────────────────────────────────
def open_dispute(order_id: int, user_id: int, message: str, photo: str | None = None) -> int:
    message = clean_text(message, 2000)
    with db.tx() as c:
        order = c.execute(
            "SELECT * FROM orders WHERE id = ? AND user_id = ?", (order_id, user_id)
        ).fetchone()
        if order is None:
            raise ShopError("no_order")
        if order["status"] == "refunded":
            raise ShopError("already_refunded")
        deadline = utc(order["dispute_until"])
        if not deadline or now() > deadline:
            raise ShopError("window_closed")
        if c.execute("SELECT 1 FROM disputes WHERE order_id = ?", (order_id,)).fetchone():
            raise ShopError("already_open")
        cur = c.execute(
            "INSERT INTO disputes(order_id, user_id, message, photo) VALUES(?,?,?,?)",
            (order_id, user_id, message, photo),
        )
        c.execute("UPDATE orders SET status = 'disputed' WHERE id = ?", (order_id,))
        return int(cur.lastrowid)


def resolve_dispute(dispute_id: int, approve: bool, resolution: str, actor: str) -> dict:
    """Approve a dispute (refund the order) or reject it."""
    resolution = clean_text(resolution, 1000)
    with db.tx() as c:
        dispute = c.execute("SELECT * FROM disputes WHERE id = ?", (dispute_id,)).fetchone()
        if dispute is None:
            raise ShopError("no_dispute")
        if dispute["status"] != "open":
            raise ShopError("already_closed")
        order = c.execute("SELECT * FROM orders WHERE id = ?", (dispute["order_id"],)).fetchone()
        refunded = 0
        if approve:
            if order["status"] == "refunded":
                raise ShopError("already_refunded")
            user = c.execute(
                "SELECT balance FROM users WHERE id = ?", (order["user_id"],)
            ).fetchone()
            refunded = int(order["paid"])
            new_balance = int(user["balance"]) + refunded
            c.execute(
                "UPDATE users SET balance = ?, purchases = MAX(purchases - 1, 0) WHERE id = ?",
                (new_balance, order["user_id"]),
            )
            c.execute(
                "INSERT INTO ledger(user_id, amount, balance_after, kind, ref, note) "
                "VALUES(?,?,?,'refund',?,?)",
                (order["user_id"], refunded, new_balance, str(order["id"]), "dispute refund"),
            )
            c.execute("UPDATE orders SET status = 'refunded' WHERE id = ?", (order["id"],))
            # The unit is not resold: it clearly was not where it should be.
            c.execute(
                "UPDATE stock_items SET status = 'withdrawn' WHERE id = ?",
                (order["stock_item_id"],),
            )
        else:
            c.execute("UPDATE orders SET status = 'completed' WHERE id = ?", (order["id"],))
        c.execute(
            "UPDATE disputes SET status = ?, resolution = ?, closed_at = datetime('now') "
            "WHERE id = ?",
            ("resolved" if approve else "rejected", resolution, dispute_id),
        )
        c.execute(
            "INSERT INTO admin_log(actor, action, detail) VALUES(?,?,?)",
            (actor, "dispute." + ("approve" if approve else "reject"),
             f"dispute={dispute_id} order={order['id']}"),
        )
    return {"user_id": int(order["user_id"]), "order_id": int(order["id"]),
            "refunded": refunded, "approved": approve, "resolution": resolution}


# ── reviews ───────────────────────────────────────────────────────────────
def add_review(order_id: int, user_id: int, rating: int, text: str) -> int:
    text = clean_text(text, 600)
    if rating not in (1, 2, 3, 4, 5):
        raise ShopError("bad_rating")
    with db.tx() as c:
        order = c.execute(
            "SELECT * FROM orders WHERE id = ? AND user_id = ?", (order_id, user_id)
        ).fetchone()
        if order is None:
            raise ShopError("no_order")
        if c.execute("SELECT 1 FROM reviews WHERE order_id = ?", (order_id,)).fetchone():
            raise ShopError("already_reviewed")
        cur = c.execute(
            "INSERT INTO reviews(order_id, user_id, product_id, rating, text) "
            "VALUES(?,?,?,?,?)",
            (order_id, user_id, order["product_id"], rating, text),
        )
        return int(cur.lastrowid)


def public_reviews(limit: int = 10, offset: int = 0) -> list[sqlite3.Row]:
    return db.query(
        "SELECT r.*, p.name AS product_name, u.first_name, u.username "
        "FROM reviews r JOIN products p ON p.id = r.product_id "
        "JOIN users u ON u.id = r.user_id "
        "WHERE r.is_public = 1 ORDER BY r.id DESC LIMIT ? OFFSET ?",
        (limit, offset),
    )


def review_stats() -> tuple[int, float]:
    row = db.query_one(
        "SELECT COUNT(*) AS n, COALESCE(AVG(rating), 0) AS avg FROM reviews WHERE is_public = 1"
    )
    return int(row["n"]), float(row["avg"])


# ── deposits ──────────────────────────────────────────────────────────────
def new_order_ref() -> str:
    return "dep_" + secrets.token_hex(10)


def create_deposit(user_id: int, amount_minor: int, currency: str) -> sqlite3.Row:
    ref = new_order_ref()
    with db.tx() as c:
        cur = c.execute(
            "INSERT INTO deposits(user_id, order_ref, price_amount, price_currency) "
            "VALUES(?,?,?,?)",
            (user_id, ref, amount_minor, currency),
        )
        deposit_id = int(cur.lastrowid)
    return db.query_one("SELECT * FROM deposits WHERE id = ?", (deposit_id,))  # type: ignore


def attach_payment(deposit_id: int, payment: dict) -> None:
    """Store the PSP's answer. The unique index on pay_address is the guard
    that one address is never handed to two deposits."""
    with db.tx() as c:
        c.execute(
            "UPDATE deposits SET payment_id = ?, pay_address = ?, pay_amount = ?, "
            "pay_currency = ?, status = ?, expires_at = ?, updated_at = datetime('now') "
            "WHERE id = ?",
            (
                str(payment.get("payment_id") or ""),
                payment.get("pay_address"),
                str(payment.get("pay_amount") or ""),
                str(payment.get("pay_currency") or ""),
                str(payment.get("payment_status") or "waiting"),
                payment.get("expiration_estimate_date"),
                deposit_id,
            ),
        )


def get_deposit(deposit_id: int, user_id: int | None = None) -> sqlite3.Row | None:
    sql = "SELECT * FROM deposits WHERE id = ?"
    params: tuple = (deposit_id,)
    if user_id is not None:
        sql += " AND user_id = ?"
        params += (user_id,)
    return db.query_one(sql, params)


def user_deposits(user_id: int, limit: int = 10) -> list[sqlite3.Row]:
    return db.query(
        "SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT ?", (user_id, limit)
    )


FINAL_PAID = {"finished", "confirmed", "sending", "partially_paid"}


def apply_ipn(payload: dict, digest: str) -> dict | None:
    """Record an IPN and credit the balance at most once per deposit.

    Returns a dict describing what changed, or None if the event was a replay
    or nothing needed doing.
    """
    payment_id = str(payload.get("payment_id") or "")
    status = str(payload.get("payment_status") or "").lower()
    order_ref = str(payload.get("order_id") or "")
    bonus_percent = max(0, min(50, int(db.get_setting("referral_bonus_percent", "5") or 0)))

    with db.tx() as c:
        try:
            c.execute(
                "INSERT INTO ipn_events(digest, payment_id, status, body) VALUES(?,?,?,?)",
                (digest, payment_id, status, str(payload)[:8000]),
            )
        except sqlite3.IntegrityError:
            return None  # exact same body already handled

        deposit = None
        if order_ref:
            deposit = c.execute(
                "SELECT * FROM deposits WHERE order_ref = ?", (order_ref,)
            ).fetchone()
        if deposit is None and payment_id:
            deposit = c.execute(
                "SELECT * FROM deposits WHERE payment_id = ?", (payment_id,)
            ).fetchone()
        if deposit is None:
            return None

        c.execute(
            "UPDATE deposits SET status = ?, actually_paid = ?, updated_at = datetime('now') "
            "WHERE id = ?",
            (status, str(payload.get("actually_paid") or ""), deposit["id"]),
        )

        if status not in FINAL_PAID or int(deposit["credited"]) > 0:
            return {"credited": 0, "user_id": int(deposit["user_id"]),
                    "deposit_id": int(deposit["id"]), "status": status}

        # Credit what the invoice was for, scaled down if they underpaid.
        amount = int(deposit["price_amount"])
        try:
            expected = float(deposit["pay_amount"] or 0)
            actual = float(payload.get("actually_paid") or 0)
            if expected > 0 and 0 < actual < expected:
                amount = int(amount * actual / expected)
        except (TypeError, ValueError):
            pass
        if amount <= 0:
            return {"credited": 0, "user_id": int(deposit["user_id"]),
                    "deposit_id": int(deposit["id"]), "status": status}

        # credited = 0 in the WHERE clause makes double-crediting impossible
        # even if two IPNs with different bodies arrive at the same moment.
        claimed = c.execute(
            "UPDATE deposits SET credited = ? WHERE id = ? AND credited = 0",
            (amount, deposit["id"]),
        )
        if claimed.rowcount != 1:
            return None

        user = c.execute("SELECT * FROM users WHERE id = ?", (deposit["user_id"],)).fetchone()
        new_balance = int(user["balance"]) + amount
        c.execute("UPDATE users SET balance = ? WHERE id = ?", (new_balance, user["id"]))
        c.execute(
            "INSERT INTO ledger(user_id, amount, balance_after, kind, ref, note) "
            "VALUES(?,?,?,'deposit',?,?)",
            (user["id"], amount, new_balance, deposit["order_ref"], status),
        )

        bonus = 0
        referrer_id = user["referred_by"]
        if referrer_id and bonus_percent:
            bonus = amount * bonus_percent // 100
            if bonus > 0:
                ref_user = c.execute(
                    "SELECT balance FROM users WHERE id = ?", (referrer_id,)
                ).fetchone()
                if ref_user is not None:
                    ref_balance = int(ref_user["balance"]) + bonus
                    c.execute(
                        "UPDATE users SET balance = ?, referral_bonus = referral_bonus + ? "
                        "WHERE id = ?",
                        (ref_balance, bonus, referrer_id),
                    )
                    c.execute(
                        "INSERT INTO ledger(user_id, amount, balance_after, kind, ref, note) "
                        "VALUES(?,?,?,'bonus',?,?)",
                        (referrer_id, bonus, ref_balance, deposit["order_ref"],
                         f"referral from {user['id']}"),
                    )
                else:
                    bonus = 0

    return {
        "credited": amount,
        "user_id": int(deposit["user_id"]),
        "deposit_id": int(deposit["id"]),
        "status": status,
        "balance": new_balance,
        "referrer_id": referrer_id if bonus else None,
        "bonus": bonus,
    }


def adjust_balance(user_id: int, delta_minor: int, note: str, actor: str) -> int:
    with db.tx() as c:
        user = c.execute("SELECT balance FROM users WHERE id = ?", (user_id,)).fetchone()
        if user is None:
            raise ShopError("no_user")
        new_balance = int(user["balance"]) + delta_minor
        if new_balance < 0:
            raise ShopError("negative")
        c.execute("UPDATE users SET balance = ? WHERE id = ?", (new_balance, user_id))
        c.execute(
            "INSERT INTO ledger(user_id, amount, balance_after, kind, ref, note) "
            "VALUES(?,?,?,'admin',?,?)",
            (user_id, delta_minor, new_balance, actor, clean_text(note, 200)),
        )
        c.execute(
            "INSERT INTO admin_log(actor, action, detail) VALUES(?,?,?)",
            (actor, "balance.adjust", f"user={user_id} delta={delta_minor}"),
        )
    return new_balance
