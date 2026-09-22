"""Checks for the parts where getting it wrong costs money."""
from __future__ import annotations

import hashlib
import json
import os
import sys
import tempfile
import threading
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

TMP = tempfile.mkdtemp(prefix="shoptest-")
os.environ["DB_PATH"] = str(Path(TMP) / "test.db")
os.environ["SECRET_KEY"] = "a" * 64
os.environ["NOWPAYMENTS_IPN_SECRET"] = "ipn-secret-for-tests"

from app import db, repo  # noqa: E402
from app.security import (  # noqa: E402
    check_csrf, hash_password, ipn_signature, make_csrf, make_session,
    media_path, read_session, verify_ipn, verify_password,
)

PASSED: list[str] = []
FAILED: list[str] = []


def check(name: str, condition: bool, detail: str = "") -> None:
    (PASSED if condition else FAILED).append(name if condition else f"{name}: {detail}")


def fresh_product(price: int, units: int) -> int:
    with db.tx() as c:
        c.execute("INSERT INTO cities(name) VALUES(?)",
                  (f"city-{c.execute('SELECT COUNT(*) n FROM cities').fetchone()[0]}",))
        city_id = c.execute("SELECT MAX(id) m FROM cities").fetchone()[0]
        c.execute("INSERT INTO products(city_id, name, price) VALUES(?,?,?)",
                  (city_id, f"p{city_id}", price))
        product_id = c.execute("SELECT MAX(id) m FROM products").fetchone()[0]
        for i in range(units):
            c.execute("INSERT INTO stock_items(product_id, payload) VALUES(?,?)",
                      (product_id, f"unit-{i}"))
    return int(product_id)


def make_user(uid: int, balance: int) -> None:
    with db.tx() as c:
        c.execute("INSERT OR REPLACE INTO users(id, balance) VALUES(?,?)", (uid, balance))


# ── money maths ───────────────────────────────────────────────────────────
def test_money():
    check("parse '12.34'", repo.parse_money("12.34") == 1234)
    check("parse '25'", repo.parse_money("25") == 2500)
    check("parse '12,5'", repo.parse_money("12,5") == 1250)
    for bad in ("", "abc", "-5", "0", "1e9999"):
        try:
            repo.parse_money(bad)
            check(f"reject {bad!r}", False, "accepted")
        except repo.ShopError:
            check(f"reject {bad!r}", True)
    check("money()", repo.money(1234) == "12.34")


# ── the last-item race ────────────────────────────────────────────────────
def test_single_item_race():
    """20 buyers, 1 unit in stock: exactly one order may exist."""
    product_id = fresh_product(1000, 1)
    buyers = list(range(9000, 9020))
    for uid in buyers:
        make_user(uid, 100_000)

    results: list[str] = []
    lock = threading.Lock()
    start = threading.Barrier(len(buyers))

    def buy(uid: int) -> None:
        start.wait()
        try:
            repo.purchase(uid, product_id)
            outcome = "ok"
        except repo.ShopError as exc:
            outcome = str(exc)
        with lock:
            results.append(outcome)

    threads = [threading.Thread(target=buy, args=(uid,)) for uid in buyers]
    for t in threads:
        t.start()
    for t in threads:
        t.join()

    wins = results.count("ok")
    orders = db.query_one("SELECT COUNT(*) n FROM orders WHERE product_id = ?",
                          (product_id,))["n"]
    sold = db.query_one(
        "SELECT COUNT(*) n FROM stock_items WHERE product_id = ? AND status='sold'",
        (product_id,))["n"]
    check("one buyer wins the last unit", wins == 1, f"wins={wins} results={set(results)}")
    check("exactly one order row", orders == 1, f"orders={orders}")
    check("exactly one unit sold", sold == 1, f"sold={sold}")


def test_balance_never_goes_negative():
    """One user, 10 units, only enough balance for 3."""
    product_id = fresh_product(1000, 10)
    uid = 9100
    make_user(uid, 3000)
    start = threading.Barrier(10)
    outcomes: list[str] = []
    lock = threading.Lock()

    def buy() -> None:
        start.wait()
        try:
            repo.purchase(uid, product_id)
            out = "ok"
        except repo.ShopError as exc:
            out = str(exc)
        with lock:
            outcomes.append(out)

    threads = [threading.Thread(target=buy) for _ in range(10)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()

    user = repo.get_user(uid)
    spent = db.query_one(
        "SELECT COALESCE(SUM(paid),0) n FROM orders WHERE user_id = ? AND product_id = ?",
        (uid, product_id))["n"]
    check("balance stays >= 0", user["balance"] >= 0, f"balance={user['balance']}")
    check("exactly 3 purchases", outcomes.count("ok") == 3,
          f"ok={outcomes.count('ok')}")
    check("spent matches balance drop", 3000 - spent == user["balance"],
          f"spent={spent} balance={user['balance']}")
    ledger = db.query_one(
        "SELECT COALESCE(SUM(amount),0) n FROM ledger WHERE user_id = ?", (uid,))["n"]
    check("ledger reconciles", ledger == -spent, f"ledger={ledger} spent={spent}")


def test_stock_and_order_link():
    product_id = fresh_product(500, 3)
    uid = 9200
    make_user(uid, 10_000)
    order = repo.purchase(uid, product_id)
    row = db.query_one("SELECT * FROM stock_items WHERE id = ?",
                       (db.query_one("SELECT stock_item_id FROM orders WHERE id = ?",
                                     (order["order_id"],))["stock_item_id"],))
    check("unit marked sold", row["status"] == "sold")
    check("unit points back at the order", row["order_id"] == order["order_id"])
    check("payload delivered", order["payload"].startswith("unit-"))


# ── deposits ──────────────────────────────────────────────────────────────
def ipn_body(order_ref: str, amount: str, status: str = "finished") -> dict:
    return {"payment_id": "pay-" + order_ref, "payment_status": status,
            "pay_address": "addr-" + order_ref, "price_amount": 10.0,
            "price_currency": "gel", "order_id": order_ref,
            "actually_paid": amount, "pay_amount": amount}


def test_ipn_credits_once():
    uid = 9300
    make_user(uid, 0)
    deposit = repo.create_deposit(uid, 5000, "GEL")
    repo.attach_payment(int(deposit["id"]), {
        "payment_id": "pay-" + deposit["order_ref"], "pay_address": "addr-one",
        "pay_amount": "20.0", "pay_currency": "usdttrc20", "payment_status": "waiting"})

    body = ipn_body(deposit["order_ref"], "20.0")
    digest = hashlib.sha256(json.dumps(body, sort_keys=True).encode()).hexdigest()
    first = repo.apply_ipn(body, digest)
    second = repo.apply_ipn(body, digest)                       # identical replay
    third = repo.apply_ipn(body, digest + "x")                  # same event, new digest

    check("first IPN credits", first and first["credited"] == 5000,
          str(first))
    check("identical replay ignored", second is None, str(second))
    check("re-send with a new digest credits nothing",
          third is None or third.get("credited") == 0, str(third))
    check("balance credited once", repo.get_user(uid)["balance"] == 5000,
          str(repo.get_user(uid)["balance"]))
    rows = db.query_one(
        "SELECT COUNT(*) n FROM ledger WHERE user_id = ? AND kind='deposit'", (uid,))["n"]
    check("one deposit ledger row", rows == 1, f"rows={rows}")


def test_ipn_concurrent_credit():
    """Two IPNs for the same deposit arriving together must credit once."""
    uid = 9310
    make_user(uid, 0)
    deposit = repo.create_deposit(uid, 4000, "GEL")
    repo.attach_payment(int(deposit["id"]), {
        "payment_id": "pay-" + deposit["order_ref"], "pay_address": "addr-two",
        "pay_amount": "16.0", "pay_currency": "usdttrc20", "payment_status": "waiting"})

    start = threading.Barrier(6)
    credited: list[int] = []
    lock = threading.Lock()

    def deliver(n: int) -> None:
        body = ipn_body(deposit["order_ref"], "16.0")
        body["seq"] = n                       # different body -> different digest
        start.wait()
        result = repo.apply_ipn(body, hashlib.sha256(
            json.dumps(body, sort_keys=True).encode()).hexdigest())
        with lock:
            credited.append((result or {}).get("credited", 0))

    threads = [threading.Thread(target=deliver, args=(n,)) for n in range(6)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()

    check("only one concurrent IPN credits", sum(1 for c in credited if c) == 1,
          str(credited))
    check("balance is the invoice amount", repo.get_user(uid)["balance"] == 4000,
          str(repo.get_user(uid)["balance"]))


def test_underpayment_is_prorated():
    uid = 9320
    make_user(uid, 0)
    deposit = repo.create_deposit(uid, 10_000, "GEL")
    repo.attach_payment(int(deposit["id"]), {
        "payment_id": "pay-" + deposit["order_ref"], "pay_address": "addr-three",
        "pay_amount": "40.0", "pay_currency": "usdttrc20", "payment_status": "waiting"})
    body = ipn_body(deposit["order_ref"], "20.0", "partially_paid")
    body["pay_amount"] = "40.0"
    repo.apply_ipn(body, "digest-underpay")
    balance = repo.get_user(uid)["balance"]
    check("half payment credits half", balance == 5000, f"balance={balance}")


def test_addresses_are_never_reused():
    make_user(9330, 0)
    make_user(9331, 0)
    a = repo.create_deposit(9330, 1000, "GEL")
    b = repo.create_deposit(9331, 1000, "GEL")
    check("each deposit gets its own order_ref", a["order_ref"] != b["order_ref"])
    repo.attach_payment(int(a["id"]), {"payment_id": "p1", "pay_address": "SHARED",
                                       "pay_amount": "1", "pay_currency": "x",
                                       "payment_status": "waiting"})
    reused = True
    try:
        repo.attach_payment(int(b["id"]), {"payment_id": "p2", "pay_address": "SHARED",
                                           "pay_amount": "1", "pay_currency": "x",
                                           "payment_status": "waiting"})
    except Exception:
        reused = False
    check("the same address cannot land on two deposits", not reused,
          "duplicate accepted")


# ── disputes and reviews ──────────────────────────────────────────────────
def test_dispute_window():
    product_id = fresh_product(1000, 2)
    uid = 9400
    make_user(uid, 10_000)
    order = repo.purchase(uid, product_id)
    dispute_id = repo.open_dispute(order["order_id"], uid, "ვერ ვიპოვე")
    check("dispute opens inside the window", dispute_id > 0)

    try:
        repo.open_dispute(order["order_id"], uid, "again")
        check("second dispute refused", False, "accepted")
    except repo.ShopError as exc:
        check("second dispute refused", str(exc) == "already_open", str(exc))

    other = repo.purchase(uid, product_id)
    with db.tx() as c:
        c.execute("UPDATE orders SET dispute_until = datetime('now','-1 hour') WHERE id = ?",
                  (other["order_id"],))
    try:
        repo.open_dispute(other["order_id"], uid, "late")
        check("expired window refused", False, "accepted")
    except repo.ShopError as exc:
        check("expired window refused", str(exc) == "window_closed", str(exc))

    before = repo.get_user(uid)["balance"]
    result = repo.resolve_dispute(dispute_id, True, "ბოდიში", "tester")
    after = repo.get_user(uid)["balance"]
    check("refund lands on the balance", after - before == order["paid"],
          f"{before}->{after}")
    check("refunded unit is withdrawn", db.query_one(
        "SELECT status FROM stock_items WHERE id = ?",
        (db.query_one("SELECT stock_item_id FROM orders WHERE id=?",
                      (order["order_id"],))["stock_item_id"],))["status"] == "withdrawn")
    try:
        repo.resolve_dispute(dispute_id, True, "", "tester")
        check("double refund refused", False, "accepted")
    except repo.ShopError:
        check("double refund refused", True)


def test_dispute_belongs_to_buyer():
    product_id = fresh_product(1000, 1)
    make_user(9500, 10_000)
    make_user(9501, 10_000)
    order = repo.purchase(9500, product_id)
    try:
        repo.open_dispute(order["order_id"], 9501, "not mine")
        check("stranger cannot dispute someone else's order", False, "accepted")
    except repo.ShopError as exc:
        check("stranger cannot dispute someone else's order",
              str(exc) == "no_order", str(exc))


def test_reviews():
    product_id = fresh_product(800, 2)
    uid = 9600
    make_user(uid, 10_000)
    order = repo.purchase(uid, product_id)
    check("review saved", repo.add_review(order["order_id"], uid, 5, "კარგია") > 0)
    try:
        repo.add_review(order["order_id"], uid, 4, "again")
        check("one review per order", False, "accepted")
    except repo.ShopError as exc:
        check("one review per order", str(exc) == "already_reviewed", str(exc))
    for bad in (0, 6, -1):
        try:
            repo.add_review(order["order_id"], uid, bad, "")
            check(f"rating {bad} refused", False, "accepted")
        except repo.ShopError:
            check(f"rating {bad} refused", True)
    make_user(9601, 10_000)
    other = repo.purchase(9601, product_id)
    try:
        repo.add_review(other["order_id"], uid, 5, "")
        check("cannot review another buyer's order", False, "accepted")
    except repo.ShopError:
        check("cannot review another buyer's order", True)


def test_discount_tiers():
    check("0 purchases -> 0%", repo.discount_percent(0) == 0)
    check("3 purchases -> 2%", repo.discount_percent(3) == 2)
    check("12 purchases -> 7%", repo.discount_percent(12) == 7)
    check("99 purchases -> 10%", repo.discount_percent(99) == 10)

    product_id = fresh_product(10_000, 1)
    uid = 9700
    make_user(uid, 50_000)
    with db.tx() as c:
        c.execute("UPDATE users SET purchases = 10 WHERE id = ?", (uid,))
    order = repo.purchase(uid, product_id)
    check("discount applied at checkout", order["paid"] == 9300,
          f"paid={order['paid']}")


def test_banned_user_cannot_buy():
    product_id = fresh_product(500, 1)
    uid = 9800
    make_user(uid, 10_000)
    with db.tx() as c:
        c.execute("UPDATE users SET is_banned = 1 WHERE id = ?", (uid,))
    try:
        repo.purchase(uid, product_id)
        check("banned user blocked", False, "purchase went through")
    except repo.ShopError as exc:
        check("banned user blocked", str(exc) == "banned", str(exc))


def test_referral():
    repo.touch_user(9900, "ref", "Ref")
    repo.touch_user(9901, "kid", "Kid", referrer_id=9900)
    check("referrer recorded", repo.get_user(9901)["referred_by"] == 9900)
    check("referral counted", repo.get_user(9900)["referral_count"] == 1)
    repo.touch_user(9902, "self", "Self", referrer_id=9902)
    check("self-referral rejected", repo.get_user(9902)["referred_by"] is None)
    repo.touch_user(9903, "ghost", "Ghost", referrer_id=123456789)
    check("unknown referrer rejected", repo.get_user(9903)["referred_by"] is None)

    deposit = repo.create_deposit(9901, 10_000, "GEL")
    repo.attach_payment(int(deposit["id"]), {
        "payment_id": "pay-ref", "pay_address": "addr-ref", "pay_amount": "40",
        "pay_currency": "usdttrc20", "payment_status": "waiting"})
    body = ipn_body(deposit["order_ref"], "40")
    repo.apply_ipn(body, "digest-ref")
    check("referrer got 5%", repo.get_user(9900)["balance"] == 500,
          str(repo.get_user(9900)["balance"]))


# ── security primitives ───────────────────────────────────────────────────
def test_security():
    encoded = hash_password("correct horse")
    check("password verifies", verify_password("correct horse", encoded))
    check("wrong password rejected", not verify_password("Correct horse", encoded))
    check("two hashes differ (salted)", encoded != hash_password("correct horse"))

    session = make_session("admin")
    check("session round-trips", read_session(session) == "admin")
    check("tampered session rejected", read_session(session[:-3] + "aaa") is None)
    check("garbage session rejected", read_session("nonsense") is None)

    token = make_csrf(session)
    check("csrf accepted for its session", check_csrf(token, session))
    check("csrf rejected for another session",
          not check_csrf(token, make_session("admin")))
    check("csrf rejected when missing", not check_csrf(None, session))

    body = {"payment_id": "1", "payment_status": "finished", "nested": {"b": 2, "a": 1}}
    signature = ipn_signature(body)
    check("valid IPN signature accepted", verify_ipn(body, signature))
    check("tampered IPN body rejected",
          not verify_ipn({**body, "payment_status": "waiting"}, signature))
    check("missing signature rejected", not verify_ipn(body, None))
    check("key order does not matter",
          verify_ipn({"nested": {"a": 1, "b": 2}, "payment_status": "finished",
                      "payment_id": "1"}, signature))

    for evil in ("../../etc/passwd", "/etc/passwd", "..\\..\\x", "", "no-such-file.png"):
        check(f"media traversal blocked {evil!r}", media_path(evil) is None)


def main() -> int:
    db.init_db()
    for name, fn in sorted(globals().items()):
        if name.startswith("test_") and callable(fn):
            fn()
    print(f"\n{len(PASSED)} passed, {len(FAILED)} failed\n")
    for failure in FAILED:
        print("  ✗", failure)
    if not FAILED:
        print("  ყველა შემოწმება გაიარა ✅")
    return 1 if FAILED else 0


if __name__ == "__main__":
    raise SystemExit(main())
