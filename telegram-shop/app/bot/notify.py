"""A thin handle on the running bot so the admin panel can message users."""
from __future__ import annotations

import logging

from aiogram import Bot
from aiogram.exceptions import TelegramAPIError

log = logging.getLogger("shop.notify")

_bot: Bot | None = None
_username: str = ""


def bind(bot: Bot, username: str) -> None:
    global _bot, _username
    _bot, _username = bot, username


def bot_username() -> str:
    return _username


async def send(chat_id: int | str, text: str, **kwargs) -> bool:
    if _bot is None:
        return False
    try:
        await _bot.send_message(chat_id, text, **kwargs)
        return True
    except TelegramAPIError as exc:
        # Blocked the bot, deleted account, bad chat id — never fatal for us.
        log.info("could not message %s: %s", chat_id, exc)
        return False
