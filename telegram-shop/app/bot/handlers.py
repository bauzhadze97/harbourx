"""All bot handlers."""
from __future__ import annotations

import logging
import time
from collections import defaultdict

from aiogram import Bot, F, Router
from aiogram.exceptions import TelegramBadRequest
from aiogram.filters import Command, CommandObject, CommandStart
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import (
    CallbackQuery, FSInputFile, InputMediaPhoto, Message,
)

from .. import db, payments, repo
from ..config import settings
from ..security import media_path
from . import notify, ui

log = logging.getLogger("shop.bot")
router = Router()


class Flow(StatesGroup):
    topup_amount = State()
    dispute_text = State()
    review_text = State()


# ── throttling ────────────────────────────────────────────────────────────
# A tiny in-process limiter. It is not a substitute for the atomic purchase
# transaction, it just keeps one user from hammering the API.
_hits: dict[int, list[float]] = defaultdict(list)
LIMIT, WINDOW = 20, 10.0


def throttled(user_id: int) -> bool:
    now = time.monotonic()
    hits = _hits[user_id]
    hits[:] = [t for t in hits if now - t < WINDOW]
    if len(hits) >= LIMIT:
        return True
    hits.append(now)
    return False


async def guard(event: Message | CallbackQuery) -> repo.sqlite3.Row | None:
    """Rate limit, upsert and ban check in one place."""
    tg = event.from_user
    if tg is None or tg.is_bot:
        return None
    if throttled(tg.id):
        if isinstance(event, CallbackQuery):
            await event.answer("ნელა 🙂", show_alert=False)
        return None
    user = repo.touch_user(tg.id, tg.username, tg.first_name)
    if user["is_banned"]:
        text = "⛔️ წვდომა შეზღუდულია."
        if isinstance(event, CallbackQuery):
            await event.answer(text, show_alert=True)
        else:
            await event.answer(text)
        return None
    return user


# ── rendering ─────────────────────────────────────────────────────────────
async def render(target: Message | CallbackQuery, text: str, markup=None,
                 photo: str | None = None) -> None:
    """Show a view, swapping between photo and text messages as needed."""
    message = target.message if isinstance(target, CallbackQuery) else target
    bot: Bot = message.bot
    path = media_path(photo) if photo else None
    has_photo = bool(message.photo) if isinstance(target, CallbackQuery) else False
    editable = isinstance(target, CallbackQuery)

    try:
        if path and editable and has_photo:
            await message.edit_media(
                InputMediaPhoto(media=FSInputFile(path), caption=text[:1024],
                                parse_mode="HTML"),
                reply_markup=markup,
            )
            return
        if path and editable:
            await message.delete()
        if path:
            await bot.send_photo(message.chat.id, FSInputFile(path),
                                 caption=text[:1024], parse_mode="HTML",
                                 reply_markup=markup)
            return
        if editable and has_photo:
            await message.delete()
            await bot.send_message(message.chat.id, text, parse_mode="HTML",
                                   reply_markup=markup,
                                   disable_web_page_preview=True)
            return
        if editable:
            await message.edit_text(text, parse_mode="HTML", reply_markup=markup,
                                    disable_web_page_preview=True)
            return
        await message.answer(text, parse_mode="HTML", reply_markup=markup,
                             disable_web_page_preview=True)
    except TelegramBadRequest as exc:
        if "message is not modified" in str(exc):
            return
        log.info("render fell back to a new message: %s", exc)
        await bot.send_message(message.chat.id, text, parse_mode="HTML",
                               reply_markup=markup, disable_web_page_preview=True)


async def show_menu(target: Message | CallbackQuery, user) -> None:
    cfg = db.get_settings_map()
    caption = ui.start_caption(user, cfg, notify.bot_username())
    await render(target, caption, ui.main_menu(cfg), cfg.get("start_photo") or None)


# ── commands ──────────────────────────────────────────────────────────────
@router.message(CommandStart())
async def cmd_start(message: Message, command: CommandObject, state: FSMContext) -> None:
    await state.clear()
    tg = message.from_user
    referrer = None
    payload = (command.args or "").strip()
    if payload.startswith("ref-") and payload[4:].isdigit():
        referrer = int(payload[4:])
    if throttled(tg.id):
        return
    user = repo.touch_user(tg.id, tg.username, tg.first_name, referrer)
    if user["is_banned"]:
        await message.answer("⛔️ წვდომა შეზღუდულია.")
        return
    await show_menu(message, user)


@router.message(Command("id"))
async def cmd_id(message: Message) -> None:
    await message.answer(f"შენი ID: <code>{message.from_user.id}</code>",
                         parse_mode="HTML")


@router.message(Command("cancel"))
async def cmd_cancel(message: Message, state: FSMContext) -> None:
    await state.clear()
    user = await guard(message)
    if user:
        await show_menu(message, user)


# ── navigation ────────────────────────────────────────────────────────────
@router.callback_query(F.data == "noop")
async def cb_noop(call: CallbackQuery) -> None:
    await call.answer()


@router.callback_query(F.data.startswith("nav:"))
async def cb_nav(call: CallbackQuery, state: FSMContext) -> None:
    user = await guard(call)
    if user is None:
        return
    await state.clear()
    where = call.data.split(":", 1)[1]
    cfg = db.get_settings_map()

    if where == "menu":
        await show_menu(call, user)
    elif where == "cities":
        text, markup = ui.cities_view()
        await render(call, text, markup)
    elif where == "rules":
        await render(call, cfg.get("rules_text", ""), ui.kb(ui.back_row()))
    elif where == "support":
        text = (
            "🧾 <b>მხარდაჭერა</b>\n\n"
            f"ადმინისტრაცია: {ui.esc(cfg.get('admin_contact'))}\n"
            f"ოპერატორი: {ui.esc(cfg.get('operator_contact'))}\n\n"
            "დაწერე პრობლემის ნომერი და შეკვეთის ID."
        )
        rows = [r for r in [[ui.url_btn("💬 დაწერე ოპერატორს",
                                        cfg.get("support_url", ""))]] if r[0]]
        await render(call, text, ui.kb(*rows, ui.back_row()))
    elif where == "stock":
        text, markup = ui.stock_view()
        await render(call, text, markup)
    elif where == "orders":
        text, markup = ui.orders_view(repo.user_orders(user["id"], 12))
        await render(call, text, markup)
    elif where == "topup":
        currencies = await payments.available_currencies()
        preferred = [settings.default_pay_currency, "usdttrc20", "btc", "ltc", "trx", "ton"]
        shown = [c for c in preferred if not currencies or c in currencies]
        text, markup = ui.topup_view(cfg, list(dict.fromkeys(shown))[:6])
        await render(call, text, markup)
    await call.answer()


@router.callback_query(F.data.startswith("city:"))
async def cb_city(call: CallbackQuery) -> None:
    if await guard(call) is None:
        return
    text, markup = ui.products_view(int(call.data.split(":")[1]))
    await render(call, text, markup)
    await call.answer()


@router.callback_query(F.data.startswith("product:"))
async def cb_product(call: CallbackQuery) -> None:
    user = await guard(call)
    if user is None:
        return
    product = repo.get_product(int(call.data.split(":")[1]))
    if product is None:
        await call.answer("პროდუქტი აღარ არის.", show_alert=True)
        return
    text, markup = ui.product_view(product, user)
    await render(call, text, markup, product["photo"])
    await call.answer()


# ── buying ────────────────────────────────────────────────────────────────
@router.callback_query(F.data.startswith("buy:"))
async def cb_buy(call: CallbackQuery) -> None:
    user = await guard(call)
    if user is None:
        return
    product = repo.get_product(int(call.data.split(":")[1]))
    if product is None or not product["in_stock"]:
        await call.answer("მარაგი ამოიწურა.", show_alert=True)
        return
    percent = repo.discount_percent(int(user["purchases"]))
    payable = int(product["price"]) - int(product["price"]) * percent // 100
    if int(user["balance"]) < payable:
        await call.answer("ბალანსი არ არის საკმარისი.", show_alert=True)
        return
    text, markup = ui.confirm_view(product, payable)
    await render(call, text, markup)
    await call.answer()


PURCHASE_ERRORS = {
    "insufficient": "ბალანსი არ არის საკმარისი.",
    "out_of_stock": "სამწუხაროდ, ბოლო ცალი სწორედ ახლა გაიყიდა.",
    "no_product": "პროდუქტი აღარ არის ხელმისაწვდომი.",
    "banned": "წვდომა შეზღუდულია.",
}


@router.callback_query(F.data.startswith("buyok:"))
async def cb_buy_confirm(call: CallbackQuery) -> None:
    user = await guard(call)
    if user is None:
        return
    try:
        order = repo.purchase(int(user["id"]), int(call.data.split(":")[1]))
    except repo.ShopError as exc:
        await call.answer(PURCHASE_ERRORS.get(str(exc), "ვერ მოხერხდა."), show_alert=True)
        product = repo.get_product(int(call.data.split(":")[1]))
        if product is not None:
            text, markup = ui.product_view(product, repo.get_user(user["id"]))
            await render(call, text, markup, product["photo"])
        return
    text, markup = ui.delivered_view(order)
    await render(call, text, markup, order["photo"] or order["product"].get("photo"))
    await call.answer("შენაძენი მზადაა ✅")


@router.callback_query(F.data.startswith("order:"))
async def cb_order(call: CallbackQuery) -> None:
    user = await guard(call)
    if user is None:
        return
    order = repo.get_order(int(call.data.split(":")[1]), int(user["id"]))
    if order is None:
        await call.answer("ვერ მოიძებნა.", show_alert=True)
        return
    text, markup = ui.order_view(order)
    await render(call, text, markup)
    await call.answer()


# ── disputes ──────────────────────────────────────────────────────────────
@router.callback_query(F.data.startswith("dispute:"))
async def cb_dispute(call: CallbackQuery, state: FSMContext) -> None:
    user = await guard(call)
    if user is None:
        return
    order_id = int(call.data.split(":")[1])
    order = repo.get_order(order_id, int(user["id"]))
    if order is None:
        await call.answer("ვერ მოიძებნა.", show_alert=True)
        return
    if not repo.dispute_open_allowed(order):
        await call.answer("დავის ვადა ამოიწურა.", show_alert=True)
        return
    if order["dispute_status"]:
        await call.answer("დავა უკვე გახსნილია.", show_alert=True)
        return
    await state.set_state(Flow.dispute_text)
    await state.update_data(order_id=order_id)
    await render(
        call,
        f"🔍 <b>დავა შენაძენზე #{order_id}</b>\n\n"
        "აღწერე რა მოხდა — სად ეძებდი, რამდენ ხანს. სურვილის შემთხვევაში "
        "მიაბი ფოტო იმავე შეტყობინებაში.\n\n/cancel — გაუქმება",
        ui.kb([ui.btn("❌ გაუქმება", f"order:{order_id}")]),
    )
    await call.answer()


@router.message(Flow.dispute_text)
async def on_dispute_text(message: Message, state: FSMContext) -> None:
    user = await guard(message)
    if user is None:
        return
    data = await state.get_data()
    order_id = int(data.get("order_id", 0))
    photo_name = await _save_photo(message)
    body = message.text or message.caption or ""
    if not body.strip() and not photo_name:
        await message.answer("დაწერე მოკლე აღწერა.")
        return
    try:
        dispute_id = repo.open_dispute(order_id, int(user["id"]), body, photo_name)
    except repo.ShopError as exc:
        await state.clear()
        await message.answer({
            "window_closed": "დავის 3-საათიანი ვადა ამოიწურა.",
            "already_open": "დავა უკვე გახსნილია.",
            "no_order": "შენაძენი ვერ მოიძებნა.",
        }.get(str(exc), "ვერ მოხერხდა."))
        return
    await state.clear()
    await message.answer(
        f"✅ დავა #{dispute_id} გაიხსნა. ოპერატორი მალე გიპასუხებს.",
        reply_markup=ui.kb([ui.btn(ui.MENU, "nav:menu")]),
    )
    for admin_id in settings.bot_admin_ids:
        await notify.send(admin_id, f"🔔 ახალი დავა #{dispute_id} შეკვეთაზე #{order_id}")


# ── reviews ───────────────────────────────────────────────────────────────
@router.callback_query(F.data.startswith("reviews:"))
async def cb_reviews(call: CallbackQuery) -> None:
    if await guard(call) is None:
        return
    text, markup = ui.reviews_view(max(0, int(call.data.split(":")[1])))
    await render(call, text, markup)
    await call.answer()


@router.callback_query(F.data.startswith("review:"))
async def cb_review(call: CallbackQuery) -> None:
    user = await guard(call)
    if user is None:
        return
    order_id = int(call.data.split(":")[1])
    order = repo.get_order(order_id, int(user["id"]))
    if order is None:
        await call.answer("ვერ მოიძებნა.", show_alert=True)
        return
    if order["has_review"]:
        await call.answer("შეფასება უკვე დატოვებული გაქვს.", show_alert=True)
        return
    await render(call, f"⭐️ რამდენს დაუწერდი შენაძენს #{order_id}?",
                 ui.rating_kb(order_id))
    await call.answer()


@router.callback_query(F.data.startswith("rate:"))
async def cb_rate(call: CallbackQuery, state: FSMContext) -> None:
    user = await guard(call)
    if user is None:
        return
    _, raw_order, raw_rating = call.data.split(":")
    await state.set_state(Flow.review_text)
    await state.update_data(order_id=int(raw_order), rating=int(raw_rating))
    await render(
        call,
        f"{'⭐️' * int(raw_rating)}\n\nდაწერე კომენტარი, ან დააჭირე "
        "„გამოტოვება“-ს.",
        ui.kb([ui.btn("გამოტოვება", f"rateskip:{raw_order}")],
              [ui.btn("❌ გაუქმება", f"order:{raw_order}")]),
    )
    await call.answer()


async def _finish_review(user, order_id: int, rating: int, text: str,
                         reply_to: Message | CallbackQuery) -> None:
    try:
        review_id = repo.add_review(order_id, int(user["id"]), rating, text)
    except repo.ShopError as exc:
        msg = {"already_reviewed": "შეფასება უკვე დატოვებული გაქვს.",
               "no_order": "შენაძენი ვერ მოიძებნა."}.get(str(exc), "ვერ მოხერხდა.")
        if isinstance(reply_to, CallbackQuery):
            await reply_to.answer(msg, show_alert=True)
        else:
            await reply_to.answer(msg)
        return
    await render(reply_to, "🙏 მადლობა შეფასებისთვის!",
                 ui.kb([ui.btn("🏅 შეფასებები", "reviews:0")],
                       [ui.btn(ui.MENU, "nav:menu")]))
    await _publish_review(review_id)


@router.callback_query(F.data.startswith("rateskip:"))
async def cb_rate_skip(call: CallbackQuery, state: FSMContext) -> None:
    user = await guard(call)
    if user is None:
        return
    data = await state.get_data()
    await state.clear()
    await _finish_review(user, int(data.get("order_id", 0)),
                         int(data.get("rating", 5)), "", call)
    await call.answer()


@router.message(Flow.review_text)
async def on_review_text(message: Message, state: FSMContext) -> None:
    user = await guard(message)
    if user is None:
        return
    data = await state.get_data()
    await state.clear()
    await _finish_review(user, int(data.get("order_id", 0)),
                         int(data.get("rating", 5)),
                         message.text or message.caption or "", message)


async def _publish_review(review_id: int) -> None:
    channel = db.get_setting("review_channel") or settings.review_channel
    if not channel:
        return
    row = db.query_one(
        "SELECT r.*, p.name AS product_name, u.first_name, u.username "
        "FROM reviews r JOIN products p ON p.id = r.product_id "
        "JOIN users u ON u.id = r.user_id WHERE r.id = ?", (review_id,))
    if row is None:
        return
    who = row["first_name"] or (f"@{row['username']}" if row["username"] else "ანონიმი")
    text = (f"{'⭐️' * int(row['rating'])}\n<b>{ui.esc(who)}</b> — "
            f"{ui.esc(row['product_name'])}")
    if row["text"]:
        text += f"\n\n<i>{ui.esc(row['text'])}</i>"
    await notify.send(channel, text, parse_mode="HTML")  # type: ignore[arg-type]


# ── top-up ────────────────────────────────────────────────────────────────
@router.callback_query(F.data.startswith("topupcur:"))
async def cb_topup_currency(call: CallbackQuery, state: FSMContext) -> None:
    user = await guard(call)
    if user is None:
        return
    currency = call.data.split(":", 1)[1][:16]
    minimum = int(db.get_setting("min_deposit", "500") or 500)
    await state.set_state(Flow.topup_amount)
    await state.update_data(currency=currency)
    await render(
        call,
        f"💰 შეიყვანე თანხა <b>{settings.shop_currency}</b>-ში "
        f"(მინიმუმ {repo.money(minimum)}).\n\nმაგ.: <code>25</code>\n\n"
        "/cancel — გაუქმება",
        ui.kb([ui.btn("❌ გაუქმება", "nav:topup")]),
    )
    await call.answer()


@router.message(Flow.topup_amount)
async def on_topup_amount(message: Message, state: FSMContext) -> None:
    user = await guard(message)
    if user is None:
        return
    data = await state.get_data()
    currency = str(data.get("currency") or settings.default_pay_currency)
    minimum = int(db.get_setting("min_deposit", "500") or 500)
    try:
        amount = repo.parse_money(message.text or "")
    except repo.ShopError:
        await message.answer("თანხა ციფრებით ჩაწერე, მაგ.: 25")
        return
    if amount < minimum:
        await message.answer(f"მინიმალური თანხაა {repo.money(minimum)} "
                             f"{settings.shop_currency}.")
        return

    deposit = repo.create_deposit(int(user["id"]), amount, settings.shop_currency)
    try:
        payment = await payments.create_payment(
            order_ref=deposit["order_ref"], amount_minor=amount,
            price_currency=settings.shop_currency, pay_currency=currency,
            description=f"Top-up for {user['id']}",
        )
    except payments.PaymentError as exc:
        log.error("deposit %s failed: %s", deposit["id"], exc)
        await state.clear()
        await message.answer(
            "გადახდის შექმნა ვერ მოხერხდა. სცადე ცოტა ხანში ან დაუკავშირდი "
            "ოპერატორს.",
            reply_markup=ui.kb([ui.btn(ui.MENU, "nav:menu")]))
        return

    repo.attach_payment(int(deposit["id"]), payment)
    await state.clear()
    fresh = repo.get_deposit(int(deposit["id"]), int(user["id"]))
    text, markup = ui.invoice_view(fresh, db.get_settings_map(),
                                   bool(payment.get("demo")))
    await message.answer(text, parse_mode="HTML", reply_markup=markup)


@router.callback_query(F.data.startswith("depcheck:"))
async def cb_deposit_check(call: CallbackQuery) -> None:
    user = await guard(call)
    if user is None:
        return
    deposit = repo.get_deposit(int(call.data.split(":")[1]), int(user["id"]))
    if deposit is None:
        await call.answer("ვერ მოიძებნა.", show_alert=True)
        return
    remote = await payments.payment_status(deposit["payment_id"] or "")
    status = str((remote or {}).get("payment_status") or deposit["status"])
    fresh = repo.get_user(int(user["id"]))
    await call.answer(
        f"სტატუსი: {status}\nბალანსი: {repo.money(fresh['balance'])} ₾",
        show_alert=True,
    )


# ── helpers ───────────────────────────────────────────────────────────────
async def _save_photo(message: Message) -> str | None:
    """Download a photo attached to a dispute into media/."""
    if not message.photo:
        return None
    from ..security import safe_media_name
    try:
        photo = message.photo[-1]
        if photo.file_size and photo.file_size > 8 * 1024 * 1024:
            return None
        name = safe_media_name("dispute.jpg")
        settings.media_dir.mkdir(parents=True, exist_ok=True)
        await message.bot.download(photo, destination=settings.media_dir / name)
        return name
    except Exception as exc:  # a missing photo must not sink the dispute
        log.info("dispute photo download failed: %s", exc)
        return None


@router.message(F.text)
async def fallback(message: Message) -> None:
    user = await guard(message)
    if user is None:
        return
    await show_menu(message, user)
