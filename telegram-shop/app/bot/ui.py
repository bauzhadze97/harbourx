"""Texts and keyboards. Everything the buyer reads is built here."""
from __future__ import annotations

from html import escape

from aiogram.types import (
    InlineKeyboardButton, InlineKeyboardMarkup, ReplyKeyboardRemove,
)

from .. import db, repo

BACK = "⬅️ უკან"
MENU = "🏠 მთავარი"


def esc(value) -> str:
    return escape(str(value if value is not None else ""), quote=False)


def kb(*rows: list[InlineKeyboardButton]) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[r for r in rows if r])


def btn(text: str, data: str) -> InlineKeyboardButton:
    return InlineKeyboardButton(text=text, callback_data=data)


def url_btn(text: str, url: str) -> InlineKeyboardButton | None:
    if not url or not url.startswith(("http://", "https://", "tg://")):
        return None
    return InlineKeyboardButton(text=text, url=url)


def back_row(target: str = "menu") -> list[InlineKeyboardButton]:
    return [btn(BACK, f"nav:{target}")]


# ── main menu ─────────────────────────────────────────────────────────────
def main_menu(cfg: dict[str, str]) -> InlineKeyboardMarkup:
    chat = url_btn("ჩატი ↗", cfg.get("chat_url", ""))
    channel = url_btn("არხი ↗", cfg.get("channel_url", ""))
    rows = [
        [btn("🏢 ქალაქი", "nav:cities"), btn("🖥 ბალანსის შევსება", "nav:topup")],
        [btn("🎴 წესები", "nav:rules"), btn("🧾 მხარდაჭერა", "nav:support")],
        [btn("🛒 რა დევს?", "nav:stock")] + ([chat] if chat else []),
        ([channel] if channel else []) + [btn("📋 ჩემი ყიდვები", "nav:orders")],
        [btn("🏅 შეფასებები", "reviews:0")],
    ]
    return kb(*rows)


def start_caption(user, cfg: dict[str, str], bot_username: str) -> str:
    percent = repo.discount_percent(int(user["purchases"]))
    lines = [
        cfg.get("start_text", ""),
        "",
        f"შენი ბალანსია: <b>{repo.money(user['balance'])}</b> ლარი",
        f"ნაყიდი გაქვს: <b>{user['purchases']}</b> -ჯერ",
        f"დაგროვებადი ფასდაკლება: <b>{percent}%</b>",
        "",
        f"მოწვეული: <b>{user['referral_count']}</b>",
        f"მიღებული ბონუსი: <b>{repo.money(user['referral_bonus'])}</b>",
        "",
        "მოიწვიე მეგობარი და მიიღე ბონუსი:",
    ]
    if bot_username:
        lines.append(f"https://t.me/{bot_username}?start=ref-{user['id']}")
    return "\n".join(lines)


# ── catalog ───────────────────────────────────────────────────────────────
def cities_view() -> tuple[str, InlineKeyboardMarkup]:
    cities = repo.active_cities()
    if not cities:
        return "ჯერჯერობით ქალაქები არ არის დამატებული.", kb(back_row())
    rows = []
    for city in cities:
        mark = "🟢" if city["in_stock"] else "⚪️"
        rows.append([btn(f"{mark} {city['name']} ({city['in_stock']})",
                         f"city:{city['id']}")])
    rows.append(back_row())
    return "🏢 <b>აირჩიე ქალაქი</b>", kb(*rows)


def products_view(city_id: int) -> tuple[str, InlineKeyboardMarkup]:
    products = repo.city_products(city_id)
    if not products:
        return "ამ ქალაქში ახლა პროდუქტი არ არის.", kb(back_row("cities"))
    rows = []
    for p in products:
        mark = "🟢" if p["in_stock"] else "🔴"
        rows.append([btn(f"{mark} {p['name']} — {repo.money(p['price'])} ₾",
                         f"product:{p['id']}")])
    rows.append(back_row("cities"))
    return "🛍 <b>აირჩიე პროდუქტი</b>", kb(*rows)


def product_view(product, user) -> tuple[str, InlineKeyboardMarkup]:
    percent = repo.discount_percent(int(user["purchases"]))
    price = int(product["price"])
    payable = price - price * percent // 100
    lines = [
        f"<b>{esc(product['name'])}</b>",
        f"📍 {esc(product['city_name'])}"
        + (f" · {esc(product['district_name'])}" if product["district_name"] else ""),
        "",
        esc(product["description"]) if product["description"] else "",
        "",
        f"ფასი: <b>{repo.money(price)} ₾</b>",
    ]
    if percent:
        lines.append(f"შენი ფასდაკლება: <b>{percent}%</b> → "
                     f"<b>{repo.money(payable)} ₾</b>")
    lines.append(f"მარაგში: <b>{product['in_stock']}</b>")
    lines.append(f"შენი ბალანსი: <b>{repo.money(user['balance'])} ₾</b>")

    rows = []
    if product["in_stock"] > 0:
        rows.append([btn(f"✅ ყიდვა — {repo.money(payable)} ₾",
                         f"buy:{product['id']}")])
    else:
        rows.append([btn("🔴 მარაგი ამოიწურა", "noop")])
    if int(user["balance"]) < payable:
        rows.append([btn("🖥 ბალანსის შევსება", "nav:topup")])
    rows.append([btn(BACK, f"city:{product['city_id']}")])
    return "\n".join(x for x in lines if x is not None), kb(*rows)


def confirm_view(product, payable: int) -> tuple[str, InlineKeyboardMarkup]:
    text = (
        f"დაადასტურე შენაძენი:\n\n"
        f"<b>{esc(product['name'])}</b>\n"
        f"ჩამოგეჭრება: <b>{repo.money(payable)} ₾</b>\n\n"
        f"დადასტურების შემდეგ ადგილს მაშინვე მიიღებ."
    )
    return text, kb(
        [btn("✅ ვადასტურებ", f"buyok:{product['id']}"),
         btn("❌ გაუქმება", f"product:{product['id']}")],
    )


def delivered_view(order: dict) -> tuple[str, InlineKeyboardMarkup]:
    product = order["product"]
    text = (
        f"✅ <b>შენაძენი #{order['order_id']}</b>\n\n"
        f"<b>{esc(product['name'])}</b>\n"
        f"გადახდილი: <b>{repo.money(order['paid'])} ₾</b>\n"
        f"ნაშთი: <b>{repo.money(order['balance'])} ₾</b>\n\n"
        f"{esc(order['payload'])}\n\n"
        f"⏳ დავის გახსნა შეგიძლია <b>{db.get_setting('dispute_window_hours', '3')} "
        f"საათის</b> განმავლობაში."
    )
    return text, order_actions(order["order_id"], has_review=False, dispute_status=None,
                               can_dispute=True)


def order_actions(order_id: int, has_review: bool, dispute_status: str | None,
                  can_dispute: bool) -> InlineKeyboardMarkup:
    rows = []
    first = []
    if dispute_status == "open":
        first.append(btn("⏳ დავა განიხილება", "noop"))
    elif dispute_status == "resolved":
        first.append(btn("✅ დავა დაკმაყოფილდა", "noop"))
    elif dispute_status == "rejected":
        first.append(btn("❌ დავა უარყოფილია", "noop"))
    elif can_dispute:
        first.append(btn("🔍 ვერ ვიპოვე", f"dispute:{order_id}"))
    else:
        first.append(btn("⌛️ დავის ვადა ამოიწურა", "noop"))
    rows.append(first)
    rows.append([btn("⭐️ შეფასება დატოვებულია", "noop") if has_review
                 else btn("⭐️ შეფასების დატოვება", f"review:{order_id}")])
    rows.append([btn(MENU, "nav:menu")])
    return kb(*rows)


# ── top-up ────────────────────────────────────────────────────────────────
def topup_view(cfg: dict[str, str], currencies: list[str]) -> tuple[str, InlineKeyboardMarkup]:
    minimum = repo.money(int(cfg.get("min_deposit", "500") or 500))
    text = (
        "🖥 <b>ბალანსის შევსება</b>\n\n"
        f"მინიმალური თანხა: <b>{minimum} ₾</b>\n\n"
        "აირჩიე ვალუტა. ყოველ შევსებაზე მიიღებ <b>ახალ, ერთჯერად</b> მისამართს — "
        "ძველზე გადმორიცხვა აღარ ჩაირიცხება."
    )
    rows, row = [], []
    labels = {"usdttrc20": "USDT TRC-20", "usdterc20": "USDT ERC-20", "btc": "Bitcoin",
              "ltc": "Litecoin", "trx": "TRON", "eth": "Ethereum", "usdtbsc": "USDT BSC",
              "sol": "Solana", "ton": "TON"}
    for code in currencies:
        row.append(btn(labels.get(code, code.upper()), f"topupcur:{code}"))
        if len(row) == 2:
            rows.append(row)
            row = []
    if row:
        rows.append(row)
    rows.append(back_row())
    return text, kb(*rows)


def invoice_view(deposit, cfg: dict[str, str], demo: bool) -> tuple[str, InlineKeyboardMarkup]:
    lines = [
        f"🧾 <b>შევსება #{deposit['id']}</b>",
        "",
        f"ჩასარიცხი თანხა: <b>{repo.money(deposit['price_amount'])} "
        f"{esc(deposit['price_currency'])}</b>",
        "",
        "ვალუტა:",
        f"<code>{esc((deposit['pay_currency'] or '').upper())}</code>",
        "",
        "რაოდენობა (დააკოპირე შეხებით):",
        f"<code>{esc(deposit['pay_amount'])}</code>",
        "",
        "მისამართი (დააკოპირე შეხებით):",
        f"<code>{esc(deposit['pay_address'])}</code>",
        "",
        "⚠️ ეს მისამართი <b>მხოლოდ ამ ერთი შევსებისთვისაა</b>. "
        "შემდეგ ჯერზე ახალს მიიღებ — ძველზე ნუ გადმორიცხავ.",
        "თანხის დადასტურების შემდეგ ბალანსი ავტომატურად შეივსება.",
    ]
    if demo:
        lines.insert(1, "\n🧪 <b>სადემონსტრაციო რეჟიმი</b> — მისამართი ნამდვილი არ არის.")
    rows = [
        [btn("🔄 სტატუსის შემოწმება", f"depcheck:{deposit['id']}")],
        [btn("🖥 ახალი შევსება", "nav:topup")],
        [btn(MENU, "nav:menu")],
    ]
    return "\n".join(lines), kb(*rows)


# ── lists ─────────────────────────────────────────────────────────────────
def stock_view() -> tuple[str, InlineKeyboardMarkup]:
    rows = repo.stock_summary()
    if not rows:
        return "მარაგი ცარიელია.", kb(back_row())
    lines = ["🛒 <b>რა დევს</b>", ""]
    current_city = None
    for row in rows:
        if row["city"] != current_city:
            current_city = row["city"]
            lines.append(f"\n📍 <b>{esc(current_city)}</b>")
        mark = "🟢" if row["in_stock"] else "🔴"
        lines.append(f"{mark} {esc(row['product'])} — {repo.money(row['price'])} ₾ "
                     f"({row['in_stock']} ცალი)")
    return "\n".join(lines), kb(back_row())


def orders_view(orders) -> tuple[str, InlineKeyboardMarkup]:
    if not orders:
        return "შენაძენები ჯერ არ გაქვს.", kb(back_row())
    rows = []
    for o in orders:
        flag = {"refunded": "↩️", "disputed": "⏳"}.get(o["status"], "✅")
        rows.append([btn(f"{flag} #{o['id']} {o['product_name']} — "
                         f"{repo.money(o['paid'])} ₾", f"order:{o['id']}")])
    rows.append(back_row())
    return "📋 <b>ჩემი ყიდვები</b>", kb(*rows)


def order_view(order) -> tuple[str, InlineKeyboardMarkup]:
    deadline = repo.utc(order["dispute_until"])
    can_dispute = order["status"] != "refunded" and repo.dispute_open_allowed(order)
    lines = [
        f"📦 <b>შენაძენი #{order['id']}</b>",
        "",
        f"<b>{esc(order['product_name'])}</b>",
        f"📍 {esc(order['city_name'])}",
        f"გადახდილი: <b>{repo.money(order['paid'])} ₾</b>",
        f"თარიღი: {esc(order['created_at'])} UTC",
        "",
        esc(order["payload"]),
    ]
    if order["status"] == "refunded":
        lines.append("\n↩️ თანხა დაბრუნებულია.")
    elif deadline:
        lines.append(f"\n⏳ დავის ვადა: {deadline:%Y-%m-%d %H:%M} UTC")
    return "\n".join(lines), order_actions(
        int(order["id"]), bool(order["has_review"]), order["dispute_status"], can_dispute)


PAGE = 5


def reviews_view(offset: int) -> tuple[str, InlineKeyboardMarkup]:
    total, average = repo.review_stats()
    items = repo.public_reviews(PAGE, offset)
    lines = [f"🏅 <b>შეფასებები</b> — {total} ცალი, საშუალო {average:.1f}/5", ""]
    if not items:
        lines.append("ჯერ არავის დაუტოვებია შეფასება.")
    for r in items:
        who = r["first_name"] or (f"@{r['username']}" if r["username"] else "ანონიმი")
        lines.append(f"{'⭐️' * int(r['rating'])} — <b>{esc(who)}</b> "
                     f"· {esc(r['product_name'])}")
        if r["text"]:
            lines.append(f"<i>{esc(r['text'])}</i>")
        lines.append("")
    rows = []
    nav = []
    if offset > 0:
        nav.append(btn("⬅️", f"reviews:{max(0, offset - PAGE)}"))
    if offset + PAGE < total:
        nav.append(btn("➡️", f"reviews:{offset + PAGE}"))
    if nav:
        rows.append(nav)
    rows.append(back_row())
    return "\n".join(lines), kb(*rows)


def rating_kb(order_id: int) -> InlineKeyboardMarkup:
    return kb(
        [btn("⭐️" * n, f"rate:{order_id}:{n}") for n in (1, 2, 3)],
        [btn("⭐️" * n, f"rate:{order_id}:{n}") for n in (4, 5)],
        [btn("❌ გაუქმება", f"order:{order_id}")],
    )


REMOVE = ReplyKeyboardRemove()
