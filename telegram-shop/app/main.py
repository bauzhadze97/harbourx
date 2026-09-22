"""Entry point: runs the Telegram bot and the web server in one event loop."""
from __future__ import annotations

import asyncio
import logging
import sys

from aiogram import Bot, Dispatcher
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode
from aiogram.fsm.storage.memory import MemoryStorage
import uvicorn

from . import db
from .config import settings
from .bot import notify
from .bot.handlers import router
from .web.server import app as web_app

log = logging.getLogger("shop")


def banner() -> None:
    line = "─" * 62
    print(line)
    print(f"  {db.get_setting('site_title')} — ლოკალური გაშვება")
    print(line)
    print(f"  საიტი        http://{settings.host}:{settings.port}/")
    print(f"  ადმინ-პანელი http://{settings.host}:{settings.port}/admin")
    print(f"  მომხმარებელი {settings.admin_username}")
    if settings.generated_password:
        print(f"  პაროლი       {settings.generated_password}   "
              f"← შეინახე, .env-ში ჩაწერე ADMIN_PASSWORD")
    else:
        print("  პაროლი       (.env-იდან)")
    print(f"  IPN          {settings.ipn_url}")
    if not settings.payments_enabled:
        print("  ⚠️  NOWPayments გამორთულია — შევსება დემო რეჟიმშია.")
    if "SECRET_KEY" not in __import__("os").environ:
        print("  ⚠️  SECRET_KEY დაყენებული არ არის — სესიები გადატვირთვას ვერ გადაიტანს.")
    print(line, flush=True)


async def run() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)-7s %(name)s  %(message)s",
        datefmt="%H:%M:%S",
    )
    logging.getLogger("aiogram.event").setLevel(logging.WARNING)
    db.init_db()

    config = uvicorn.Config(web_app, host=settings.host, port=settings.port,
                            log_level="warning", access_log=False)
    server = uvicorn.Server(config)
    web_task = asyncio.create_task(server.serve(), name="web")

    if not settings.bot_token:
        banner()
        print("  ⚠️  BOT_TOKEN არ არის — მხოლოდ საიტი და ადმინი გაეშვა.", flush=True)
        await web_task
        return

    bot = Bot(settings.bot_token,
              default=DefaultBotProperties(parse_mode=ParseMode.HTML))
    try:
        me = await bot.get_me()
    except Exception as exc:
        # No route to Telegram (offline, firewall, bad token): the panel and the
        # site are still worth serving, so say so and keep them up.
        banner()
        print(f"  ⚠️  Telegram-თან კავშირი ვერ დამყარდა: {exc}")
        print("      საიტი და ადმინ-პანელი მუშაობს, ბოტი — არა.", flush=True)
        await bot.session.close()
        await web_task
        return
    notify.bind(bot, me.username or "")

    dispatcher = Dispatcher(storage=MemoryStorage())
    dispatcher.include_router(router)

    banner()
    print(f"  ბოტი         @{me.username} (polling)", flush=True)

    await bot.delete_webhook(drop_pending_updates=True)
    bot_task = asyncio.create_task(
        dispatcher.start_polling(bot, handle_signals=False), name="bot")

    done, pending = await asyncio.wait({web_task, bot_task},
                                      return_when=asyncio.FIRST_COMPLETED)
    for task in pending:
        task.cancel()
    server.should_exit = True
    await asyncio.gather(*pending, return_exceptions=True)
    await bot.session.close()
    for task in done:
        if task.exception():
            raise task.exception()  # type: ignore[misc]


def main() -> None:
    try:
        asyncio.run(run())
    except KeyboardInterrupt:
        print("\nგაჩერდა.")
        sys.exit(0)


if __name__ == "__main__":
    main()
