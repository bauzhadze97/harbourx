"""Public site, admin panel and the NOWPayments IPN endpoint."""
from __future__ import annotations

import json
import hashlib
import logging
import secrets
from pathlib import Path

from fastapi import FastAPI, Form, Request, UploadFile
from fastapi.responses import (
    FileResponse, HTMLResponse, JSONResponse, PlainTextResponse, RedirectResponse,
)
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates

from .. import db, repo
from ..config import settings
from ..bot import notify
from ..security import (
    check_csrf, clean_text, hash_password, make_csrf, make_session, media_path,
    read_session, safe_media_name, verify_ipn, verify_password,
)

log = logging.getLogger("shop.web")

HERE = Path(__file__).resolve().parent
TEMPLATES = Jinja2Templates(directory=str(HERE / "templates"))
SESSION_COOKIE = "shop_admin"
MAX_UPLOAD = 6 * 1024 * 1024

# Hashed once at import so the plaintext never sits in a comparison.
ADMIN_HASH = hash_password(settings.admin_password)

app = FastAPI(title="Shop", docs_url=None, redoc_url=None, openapi_url=None)
app.mount("/static", StaticFiles(directory=str(HERE / "static")), name="static")


# ── plumbing ──────────────────────────────────────────────────────────────
@app.middleware("http")
async def security_headers(request: Request, call_next):
    response = await call_next(request)
    response.headers.setdefault("X-Content-Type-Options", "nosniff")
    response.headers.setdefault("X-Frame-Options", "DENY")
    response.headers.setdefault("Referrer-Policy", "no-referrer")
    response.headers.setdefault(
        "Content-Security-Policy",
        "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
        "script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
    )
    response.headers.setdefault("Permissions-Policy",
                                "geolocation=(), microphone=(), camera=()")
    return response


def current_admin(request: Request) -> str | None:
    return read_session(request.cookies.get(SESSION_COOKIE))


def require_admin(request: Request) -> str | None:
    """Returns the admin name, or None when the caller must be redirected."""
    return current_admin(request)


def login_redirect() -> RedirectResponse:
    return RedirectResponse("/admin/login", status_code=303)


def csrf_ok(request: Request, token: str | None) -> bool:
    return check_csrf(token, request.cookies.get(SESSION_COOKIE))


def render(request: Request, template: str, **context) -> HTMLResponse:
    session = request.cookies.get(SESSION_COOKIE, "")
    context.setdefault("csrf", make_csrf(session) if session else "")
    context.setdefault("admin", current_admin(request))
    context.setdefault("cfg", db.get_settings_map())
    context.setdefault("money", repo.money)
    context.setdefault("flash", request.query_params.get("m", ""))
    context.setdefault(
        "open_disputes",
        db.query_one("SELECT COUNT(*) n FROM disputes WHERE status='open'")["n"],
    )
    return TEMPLATES.TemplateResponse(request, template, context)


def audit(actor: str, action: str, detail: str = "", ip: str = "") -> None:
    with db.tx() as c:
        c.execute("INSERT INTO admin_log(actor, action, detail, ip) VALUES(?,?,?,?)",
                  (actor, action, detail[:500], ip[:64]))


def back(url: str, message: str = "") -> RedirectResponse:
    if message:
        url += ("&" if "?" in url else "?") + "m=" + message.replace(" ", "+")
    return RedirectResponse(url, status_code=303)


async def store_upload(file: UploadFile | None) -> str | None:
    if file is None or not file.filename:
        return None
    if file.content_type not in {"image/jpeg", "image/png", "image/webp", "image/gif"}:
        return None
    settings.media_dir.mkdir(parents=True, exist_ok=True)
    name = safe_media_name(file.filename)
    target = settings.media_dir / name
    size = 0
    with target.open("wb") as out:
        while chunk := await file.read(64 * 1024):
            size += len(chunk)
            if size > MAX_UPLOAD:
                out.close()
                target.unlink(missing_ok=True)
                return None
            out.write(chunk)
    return name


# ── public site ───────────────────────────────────────────────────────────
@app.get("/", response_class=HTMLResponse)
async def public_home(request: Request):
    cfg = db.get_settings_map()
    total, average = repo.review_stats()
    return TEMPLATES.TemplateResponse(
        request, "public.html",
        {"cfg": cfg, "bot_username": notify.bot_username(),
         "reviews_total": total, "reviews_average": average},
    )


@app.get("/media/{name}")
async def media(name: str):
    path = media_path(name)
    if path is None:
        return PlainTextResponse("not found", status_code=404)
    return FileResponse(path, headers={"Cache-Control": "public, max-age=86400"})


@app.get("/healthz")
async def healthz():
    return {"ok": True}


# ── IPN ───────────────────────────────────────────────────────────────────
@app.post("/ipn/nowpayments")
async def ipn(request: Request):
    raw = await request.body()
    if len(raw) > 64 * 1024:
        return JSONResponse({"ok": False}, status_code=413)
    try:
        payload = json.loads(raw.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        return JSONResponse({"ok": False}, status_code=400)
    if not isinstance(payload, dict):
        return JSONResponse({"ok": False}, status_code=400)

    signature = request.headers.get("x-nowpayments-sig")
    if not verify_ipn(payload, signature):
        log.warning("rejected IPN with a bad signature for payment %s",
                    payload.get("payment_id"))
        # 403 and nothing else: never leak whether the payment id exists.
        return JSONResponse({"ok": False}, status_code=403)

    digest = hashlib.sha256(raw).hexdigest()
    result = repo.apply_ipn(payload, digest)
    if result and result.get("credited"):
        await notify.send(
            result["user_id"],
            f"✅ ბალანსი შეივსო: <b>{repo.money(result['credited'])} "
            f"{settings.shop_currency}</b>\nახალი ბალანსი: "
            f"<b>{repo.money(result['balance'])}</b>",
            parse_mode="HTML",
        )
        if result.get("referrer_id"):
            await notify.send(
                int(result["referrer_id"]),
                f"🎁 მოწვეულმა მეგობარმა შეავსო ბალანსი — მიიღე "
                f"<b>{repo.money(result['bonus'])} {settings.shop_currency}</b>",
                parse_mode="HTML",
            )
    return {"ok": True}


# ── admin auth ────────────────────────────────────────────────────────────
LOCK_WINDOW_MINUTES = 15
LOCK_AFTER = 8


def login_locked(ip: str) -> bool:
    row = db.query_one(
        "SELECT COUNT(*) AS n FROM login_attempts "
        "WHERE ip = ? AND ok = 0 AND created_at > datetime('now', ?)",
        (ip, f"-{LOCK_WINDOW_MINUTES} minutes"),
    )
    return int(row["n"]) >= LOCK_AFTER


def record_attempt(ip: str, ok: bool) -> None:
    with db.tx() as c:
        c.execute("INSERT INTO login_attempts(ip, ok) VALUES(?, ?)", (ip, 1 if ok else 0))
        c.execute("DELETE FROM login_attempts WHERE created_at < datetime('now', '-1 day')")


@app.get("/admin/login", response_class=HTMLResponse)
async def login_form(request: Request):
    if current_admin(request):
        return RedirectResponse("/admin", status_code=303)
    # A pre-session cookie gives the login form a CSRF anchor of its own.
    token = request.cookies.get(SESSION_COOKIE) or "pre." + secrets.token_hex(16)
    response = TEMPLATES.TemplateResponse(
        request, "login.html",
        {"csrf": make_csrf(token), "error": request.query_params.get("e", ""),
         "cfg": db.get_settings_map()},
    )
    response.set_cookie(SESSION_COOKIE, token, httponly=True, samesite="strict",
                        secure=settings.cookie_secure, max_age=900, path="/")
    return response


@app.post("/admin/login")
async def login(request: Request, username: str = Form(""), password: str = Form(""),
                csrf: str = Form("")):
    ip = (request.client.host if request.client else "") or "unknown"
    if not csrf_ok(request, csrf):
        return RedirectResponse("/admin/login?e=session+expired", status_code=303)
    if login_locked(ip):
        return RedirectResponse("/admin/login?e=too+many+attempts", status_code=303)

    ok = secrets.compare_digest(username.strip(), settings.admin_username)
    # Always run the hash so a wrong username is not faster than a wrong password.
    ok = verify_password(password, ADMIN_HASH) and ok
    record_attempt(ip, ok)
    if not ok:
        return RedirectResponse("/admin/login?e=wrong+credentials", status_code=303)

    token = make_session(settings.admin_username)
    audit(settings.admin_username, "login", "", ip)
    response = RedirectResponse("/admin", status_code=303)
    response.set_cookie(SESSION_COOKIE, token, httponly=True, samesite="strict",
                        secure=settings.cookie_secure, max_age=8 * 3600, path="/")
    return response


@app.post("/admin/logout")
async def logout(request: Request, csrf: str = Form("")):
    if csrf_ok(request, csrf):
        response = RedirectResponse("/admin/login", status_code=303)
        response.delete_cookie(SESSION_COOKIE, path="/")
        return response
    return back("/admin")


# ── admin pages ───────────────────────────────────────────────────────────
@app.get("/admin", response_class=HTMLResponse)
async def dashboard(request: Request):
    if not require_admin(request):
        return login_redirect()
    stats = {
        "users": db.query_one("SELECT COUNT(*) n FROM users")["n"],
        "orders": db.query_one("SELECT COUNT(*) n FROM orders")["n"],
        "revenue": db.query_one(
            "SELECT COALESCE(SUM(paid),0) n FROM orders WHERE status != 'refunded'")["n"],
        "deposits": db.query_one("SELECT COALESCE(SUM(credited),0) n FROM deposits")["n"],
        "stock": db.query_one(
            "SELECT COUNT(*) n FROM stock_items WHERE status='available'")["n"],
        "open_disputes": db.query_one(
            "SELECT COUNT(*) n FROM disputes WHERE status='open'")["n"],
        "reviews": db.query_one("SELECT COUNT(*) n FROM reviews")["n"],
        "balances": db.query_one("SELECT COALESCE(SUM(balance),0) n FROM users")["n"],
    }
    return render(
        request, "admin/dashboard.html", stats=stats,
        orders=db.query(
            "SELECT o.*, p.name AS product_name, u.username, u.first_name "
            "FROM orders o JOIN products p ON p.id=o.product_id "
            "JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 10"),
        deposits=db.query("SELECT * FROM deposits ORDER BY id DESC LIMIT 10"),
        payments_enabled=settings.payments_enabled,
        ipn_url=settings.ipn_url,
    )


@app.get("/admin/settings", response_class=HTMLResponse)
async def settings_page(request: Request):
    if not require_admin(request):
        return login_redirect()
    return render(request, "admin/settings.html")


TEXT_SETTINGS = {
    "start_text": 3000, "rules_text": 3000, "support_url": 300, "chat_url": 300,
    "channel_url": 300, "admin_contact": 100, "operator_contact": 100,
    "dispute_window_hours": 4, "referral_bonus_percent": 4, "discount_tiers": 200,
    "min_deposit": 12, "site_title": 120, "site_tagline": 200, "review_channel": 100,
}


@app.post("/admin/settings")
async def settings_save(request: Request):
    who = require_admin(request)
    if not who:
        return login_redirect()
    form = await request.form()
    if not csrf_ok(request, form.get("csrf")):
        return back("/admin/settings", "session expired")

    for key, limit in TEXT_SETTINGS.items():
        if key in form:
            db.set_setting(key, clean_text(str(form[key]), limit))

    photo = form.get("start_photo_file")
    if isinstance(photo, UploadFile):
        name = await store_upload(photo)
        if name:
            db.set_setting("start_photo", name)
    if form.get("clear_photo"):
        db.set_setting("start_photo", "")

    audit(who, "settings.save")
    return back("/admin/settings", "saved")


# ── catalog admin ─────────────────────────────────────────────────────────
@app.get("/admin/catalog", response_class=HTMLResponse)
async def catalog(request: Request):
    if not require_admin(request):
        return login_redirect()
    return render(
        request, "admin/catalog.html",
        cities=db.query("SELECT * FROM cities ORDER BY position, name"),
        districts=db.query(
            "SELECT d.*, c.name AS city_name FROM districts d "
            "JOIN cities c ON c.id = d.city_id ORDER BY c.name, d.position, d.name"),
        products=db.query(
            "SELECT p.*, c.name AS city_name, d.name AS district_name, ("
            "  SELECT COUNT(*) FROM stock_items s "
            "  WHERE s.product_id = p.id AND s.status='available') AS in_stock "
            "FROM products p JOIN cities c ON c.id = p.city_id "
            "LEFT JOIN districts d ON d.id = p.district_id "
            "ORDER BY c.position, c.name, p.position, p.name"),
    )


@app.post("/admin/catalog/city")
async def city_save(request: Request):
    who = require_admin(request)
    if not who:
        return login_redirect()
    form = await request.form()
    if not csrf_ok(request, form.get("csrf")):
        return back("/admin/catalog", "session expired")
    action = str(form.get("action", "create"))
    try:
        if action == "create":
            db.connect().execute(
                "INSERT INTO cities(name, position) VALUES(?, ?)",
                (clean_text(str(form.get("name")), 60),
                 int(form.get("position") or 0)))
        elif action == "toggle":
            db.connect().execute(
                "UPDATE cities SET is_active = 1 - is_active WHERE id = ?",
                (int(form["id"]),))
        elif action == "delete":
            db.connect().execute("DELETE FROM cities WHERE id = ?", (int(form["id"]),))
    except Exception as exc:
        return back("/admin/catalog", f"error: {type(exc).__name__}")
    audit(who, f"city.{action}")
    return back("/admin/catalog", "ok")


@app.post("/admin/catalog/district")
async def district_save(request: Request):
    who = require_admin(request)
    if not who:
        return login_redirect()
    form = await request.form()
    if not csrf_ok(request, form.get("csrf")):
        return back("/admin/catalog", "session expired")
    action = str(form.get("action", "create"))
    try:
        if action == "create":
            db.connect().execute(
                "INSERT INTO districts(city_id, name) VALUES(?, ?)",
                (int(form["city_id"]), clean_text(str(form.get("name")), 60)))
        elif action == "delete":
            db.connect().execute("DELETE FROM districts WHERE id = ?", (int(form["id"]),))
    except Exception as exc:
        return back("/admin/catalog", f"error: {type(exc).__name__}")
    audit(who, f"district.{action}")
    return back("/admin/catalog", "ok")


@app.post("/admin/catalog/product")
async def product_save(request: Request):
    who = require_admin(request)
    if not who:
        return login_redirect()
    form = await request.form()
    if not csrf_ok(request, form.get("csrf")):
        return back("/admin/catalog", "session expired")
    action = str(form.get("action", "create"))
    try:
        if action == "create":
            photo = await store_upload(form.get("photo"))
            district = form.get("district_id")
            db.connect().execute(
                "INSERT INTO products(city_id, district_id, name, description, price, "
                "photo, position) VALUES(?,?,?,?,?,?,?)",
                (int(form["city_id"]),
                 int(district) if district and str(district).isdigit() else None,
                 clean_text(str(form.get("name")), 80),
                 clean_text(str(form.get("description")), 1500),
                 repo.parse_money(str(form.get("price"))),
                 photo, int(form.get("position") or 0)))
        elif action == "update":
            photo = await store_upload(form.get("photo"))
            db.connect().execute(
                "UPDATE products SET name = ?, description = ?, price = ?, "
                "position = ?, photo = COALESCE(?, photo) WHERE id = ?",
                (clean_text(str(form.get("name")), 80),
                 clean_text(str(form.get("description")), 1500),
                 repo.parse_money(str(form.get("price"))),
                 int(form.get("position") or 0), photo, int(form["id"])))
        elif action == "toggle":
            db.connect().execute(
                "UPDATE products SET is_active = 1 - is_active WHERE id = ?",
                (int(form["id"]),))
        elif action == "delete":
            db.connect().execute("DELETE FROM products WHERE id = ?", (int(form["id"]),))
    except repo.ShopError:
        return back("/admin/catalog", "bad price")
    except Exception as exc:
        return back("/admin/catalog", f"error: {type(exc).__name__}")
    audit(who, f"product.{action}")
    return back("/admin/catalog", "ok")


@app.get("/admin/stock/{product_id}", response_class=HTMLResponse)
async def stock_page(request: Request, product_id: int):
    if not require_admin(request):
        return login_redirect()
    product = repo.get_product(product_id)
    if product is None:
        return back("/admin/catalog", "not found")
    return render(
        request, "admin/stock.html", product=product,
        items=db.query(
            "SELECT * FROM stock_items WHERE product_id = ? ORDER BY id DESC LIMIT 200",
            (product_id,)),
    )


@app.post("/admin/stock/{product_id}")
async def stock_save(request: Request, product_id: int):
    who = require_admin(request)
    if not who:
        return login_redirect()
    form = await request.form()
    if not csrf_ok(request, form.get("csrf")):
        return back(f"/admin/stock/{product_id}", "session expired")
    action = str(form.get("action", "add"))
    if action == "add":
        # One line of the textarea = one unit for sale.
        lines = [clean_text(line, 1000) for line in
                 str(form.get("payloads", "")).splitlines()]
        lines = [line for line in lines if line]
        photo = await store_upload(form.get("photo"))
        with db.tx() as c:
            for line in lines[:500]:
                c.execute(
                    "INSERT INTO stock_items(product_id, payload, photo) VALUES(?,?,?)",
                    (product_id, line, photo))
        audit(who, "stock.add", f"product={product_id} n={len(lines)}")
        return back(f"/admin/stock/{product_id}", f"added {len(lines)}")
    if action == "withdraw":
        db.connect().execute(
            "UPDATE stock_items SET status='withdrawn' WHERE id = ? AND status='available'",
            (int(form["id"]),))
        audit(who, "stock.withdraw", str(form.get("id")))
    return back(f"/admin/stock/{product_id}", "ok")


# ── users, orders, disputes, reviews ──────────────────────────────────────
@app.get("/admin/users", response_class=HTMLResponse)
async def users_page(request: Request):
    if not require_admin(request):
        return login_redirect()
    q = clean_text(request.query_params.get("q", ""), 64)
    if q:
        rows = db.query(
            "SELECT * FROM users WHERE CAST(id AS TEXT) LIKE ? OR username LIKE ? "
            "OR first_name LIKE ? ORDER BY id DESC LIMIT 100",
            (f"%{q}%", f"%{q}%", f"%{q}%"))
    else:
        rows = db.query("SELECT * FROM users ORDER BY last_seen_at DESC LIMIT 100")
    return render(request, "admin/users.html", users=rows, q=q)


@app.post("/admin/users")
async def users_action(request: Request):
    who = require_admin(request)
    if not who:
        return login_redirect()
    form = await request.form()
    if not csrf_ok(request, form.get("csrf")):
        return back("/admin/users", "session expired")
    user_id = int(form["id"])
    action = str(form.get("action"))
    if action == "ban":
        db.connect().execute("UPDATE users SET is_banned = 1 - is_banned WHERE id = ?",
                             (user_id,))
        audit(who, "user.ban_toggle", str(user_id))
    elif action == "balance":
        try:
            delta = repo.parse_money(str(form.get("amount")))
        except repo.ShopError:
            return back("/admin/users", "bad amount")
        if str(form.get("direction")) == "minus":
            delta = -delta
        try:
            repo.adjust_balance(user_id, delta, str(form.get("note", ""))[:200], who)
        except repo.ShopError as exc:
            return back("/admin/users", str(exc))
        await notify.send(user_id,
                          f"ℹ️ ბალანსი შეიცვალა: <b>{repo.money(delta)}</b>",
                          parse_mode="HTML")
    return back("/admin/users", "ok")


@app.get("/admin/orders", response_class=HTMLResponse)
async def orders_page(request: Request):
    if not require_admin(request):
        return login_redirect()
    return render(request, "admin/orders.html", orders=db.query(
        "SELECT o.*, p.name AS product_name, c.name AS city_name, u.username, "
        "       u.first_name, s.payload "
        "FROM orders o JOIN products p ON p.id=o.product_id "
        "JOIN cities c ON c.id=p.city_id JOIN users u ON u.id=o.user_id "
        "JOIN stock_items s ON s.id=o.stock_item_id "
        "ORDER BY o.id DESC LIMIT 200"))


@app.get("/admin/disputes", response_class=HTMLResponse)
async def disputes_page(request: Request):
    if not require_admin(request):
        return login_redirect()
    return render(request, "admin/disputes.html", disputes=db.query(
        "SELECT d.*, o.paid, o.created_at AS order_at, p.name AS product_name, "
        "       u.username, u.first_name "
        "FROM disputes d JOIN orders o ON o.id=d.order_id "
        "JOIN products p ON p.id=o.product_id JOIN users u ON u.id=d.user_id "
        "ORDER BY (d.status='open') DESC, d.id DESC LIMIT 200"))


@app.post("/admin/disputes")
async def dispute_action(request: Request):
    who = require_admin(request)
    if not who:
        return login_redirect()
    form = await request.form()
    if not csrf_ok(request, form.get("csrf")):
        return back("/admin/disputes", "session expired")
    approve = str(form.get("action")) == "approve"
    try:
        result = repo.resolve_dispute(int(form["id"]), approve,
                                      str(form.get("resolution", "")), who)
    except repo.ShopError as exc:
        return back("/admin/disputes", str(exc))
    if approve:
        text = (f"✅ დავა შენაძენზე #{result['order_id']} დაკმაყოფილდა.\n"
                f"დაბრუნდა <b>{repo.money(result['refunded'])} ₾</b>.")
    else:
        text = f"❌ დავა შენაძენზე #{result['order_id']} არ დაკმაყოფილდა."
    if result["resolution"]:
        text += f"\n\n{result['resolution']}"
    await notify.send(result["user_id"], text, parse_mode="HTML")
    return back("/admin/disputes", "ok")


@app.get("/admin/reviews", response_class=HTMLResponse)
async def reviews_page(request: Request):
    if not require_admin(request):
        return login_redirect()
    return render(request, "admin/reviews.html", reviews=db.query(
        "SELECT r.*, p.name AS product_name, u.username, u.first_name "
        "FROM reviews r JOIN products p ON p.id=r.product_id "
        "JOIN users u ON u.id=r.user_id ORDER BY r.id DESC LIMIT 200"))


@app.post("/admin/reviews")
async def reviews_action(request: Request):
    who = require_admin(request)
    if not who:
        return login_redirect()
    form = await request.form()
    if not csrf_ok(request, form.get("csrf")):
        return back("/admin/reviews", "session expired")
    review_id = int(form["id"])
    if str(form.get("action")) == "hide":
        db.connect().execute("UPDATE reviews SET is_public = 1 - is_public WHERE id = ?",
                             (review_id,))
    else:
        db.connect().execute("DELETE FROM reviews WHERE id = ?", (review_id,))
    audit(who, "review." + str(form.get("action")), str(review_id))
    return back("/admin/reviews", "ok")


@app.get("/admin/deposits", response_class=HTMLResponse)
async def deposits_page(request: Request):
    if not require_admin(request):
        return login_redirect()
    return render(request, "admin/deposits.html", deposits=db.query(
        "SELECT d.*, u.username, u.first_name FROM deposits d "
        "JOIN users u ON u.id = d.user_id ORDER BY d.id DESC LIMIT 200"))


@app.get("/admin/logs", response_class=HTMLResponse)
async def logs_page(request: Request):
    if not require_admin(request):
        return login_redirect()
    return render(
        request, "admin/logs.html",
        entries=db.query("SELECT * FROM admin_log ORDER BY id DESC LIMIT 200"),
        ledger=db.query(
            "SELECT l.*, u.username FROM ledger l JOIN users u ON u.id = l.user_id "
            "ORDER BY l.id DESC LIMIT 200"),
        ipn=db.query("SELECT id, payment_id, status, created_at FROM ipn_events "
                     "ORDER BY id DESC LIMIT 100"),
    )


# ── local bot preview ─────────────────────────────────────────────────────
# Drives the real handlers without Telegram, so the bot can be seen and
# clicked through on a machine that has no route to api.telegram.org.
@app.get("/admin/preview", response_class=HTMLResponse)
async def preview_page(request: Request):
    if not require_admin(request):
        return login_redirect()
    return render(request, "admin/preview.html")


@app.post("/admin/preview/api")
async def preview_api(request: Request):
    if not require_admin(request):
        return JSONResponse({"error": "auth"}, status_code=401)
    try:
        payload = await request.json()
    except ValueError:
        return JSONResponse({"error": "bad json"}, status_code=400)
    if not csrf_ok(request, str(payload.get("csrf", ""))):
        return JSONResponse({"error": "csrf"}, status_code=403)

    from ..bot.emulator import emulator

    engine = emulator()
    user_id = int(payload.get("user_id") or 777_000_001)
    name = clean_text(str(payload.get("name") or "Preview"), 32)
    action = str(payload.get("action") or "state")
    try:
        if action == "text":
            data = await engine.send_text(user_id, name,
                                          clean_text(str(payload.get("text")), 500))
        elif action == "press":
            data = await engine.press(user_id, name,
                                      int(payload.get("message_id")),
                                      str(payload.get("data"))[:64])
        elif action == "reset":
            data = engine.reset(user_id)
        else:
            data = engine.state(user_id)
    except Exception as exc:  # the preview must never take the panel down
        log.exception("preview failed")
        return JSONResponse({"error": f"{type(exc).__name__}: {exc}"}, status_code=500)

    user = repo.get_user(user_id)
    return {"screen": data,
            "balance": repo.money(user["balance"]) if user else "0.00",
            "purchases": user["purchases"] if user else 0}
