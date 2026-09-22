"""A local stand-in for Telegram.

The handlers, the dispatcher, the FSM and the database are the real ones — only
the transport is replaced. Feeding an Update in here exercises exactly the code
path a real message would, which makes the bot testable (and demoable) on a
machine that cannot reach api.telegram.org.
"""
from __future__ import annotations

import asyncio
import datetime as dt
from typing import Any

from aiogram import Bot, Dispatcher
from aiogram.client.default import DefaultBotProperties
from aiogram.client.session.base import BaseSession
from aiogram.enums import ParseMode
from aiogram.fsm.storage.memory import MemoryStorage
from aiogram.methods import TelegramMethod
from aiogram.types import CallbackQuery, Chat, Message, Update, User

from ..config import settings
from .handlers import router
from . import notify

BOT_ID = 8_985_987_890
BOT_USERNAME = "JaneShopPreviewBot"


def _now() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc)


class Screen:
    """What the emulated chat currently shows."""

    def __init__(self) -> None:
        self.messages: dict[int, dict] = {}
        self.order: list[int] = []
        self.toasts: list[str] = []

    def add(self, message_id: int, data: dict) -> None:
        self.messages[message_id] = data
        self.order.append(message_id)

    def edit(self, message_id: int, **changes) -> None:
        self.messages.setdefault(message_id, {}).update(changes)

    def drop(self, message_id: int) -> None:
        self.messages.pop(message_id, None)
        self.order = [m for m in self.order if m != message_id]

    def dump(self) -> dict:
        return {
            "messages": [dict(self.messages[m], id=m) for m in self.order
                         if m in self.messages],
            "toasts": self.toasts,
        }


def _markup(method: Any) -> list[list[dict]]:
    markup = getattr(method, "reply_markup", None)
    rows = getattr(markup, "inline_keyboard", None) or []
    return [[{"text": b.text, "data": b.callback_data, "url": b.url} for b in row]
            for row in rows]


class PreviewSession(BaseSession):
    """Answers Bot API calls locally and records what the chat would show."""

    def __init__(self) -> None:
        super().__init__()
        self.next_id = 1000
        self.screens: dict[int, Screen] = {}

    def screen(self, chat_id: int) -> Screen:
        return self.screens.setdefault(chat_id, Screen())

    @staticmethod
    def _chat(value: Any) -> int | None:
        """Numeric chats are shown; a channel @username is delivered silently."""
        try:
            return int(value)
        except (TypeError, ValueError):
            return None

    def _message(self, chat_id: int, text: str, method: Any,
                 photo: str | None = None) -> Message:
        self.next_id += 1
        message_id = self.next_id
        self.screen(chat_id).add(message_id, {
            "text": text or "", "photo": photo, "keyboard": _markup(method),
            "at": _now().strftime("%H:%M"),
        })
        return Message.model_validate({
            "message_id": message_id, "date": _now(),
            "chat": {"id": chat_id, "type": "private"},
            "from": {"id": BOT_ID, "is_bot": True, "first_name": "Shop",
                     "username": BOT_USERNAME},
            "text": text or "",
        }).as_(None)

    async def make_request(self, bot: Bot, method: TelegramMethod, timeout=None):
        name = type(method).__name__

        if name == "GetMe":
            return User.model_validate({
                "id": BOT_ID, "is_bot": True, "first_name": "ჯეინის საბურგერე",
                "username": BOT_USERNAME})
        if name in {"DeleteWebhook", "SetWebhook", "AnswerCallbackQuery", "Close",
                    "LogOut", "SetMyCommands"}:
            if name == "AnswerCallbackQuery" and getattr(method, "text", None):
                # Only the chat the query came from can show the toast; the
                # handler layer sets it on the right screen before returning.
                for screen in self.screens.values():
                    screen.toasts.append(method.text)
            return True
        if name == "GetUpdates":
            await asyncio.sleep(3600)
            return []
        chat_id = self._chat(getattr(method, "chat_id", None))
        if chat_id is None:
            # A post to a channel by @username: nothing to draw, but the call
            # must still succeed the way Telegram would.
            return self._existing(0, 0) if name.startswith(("Send", "Edit")) else True

        if name in {"SendMessage"}:
            return self._message(chat_id, method.text, method)
        if name in {"SendPhoto"}:
            photo = getattr(method.photo, "path", None) or str(method.photo)
            return self._message(chat_id, method.caption or "", method,
                                 photo=str(photo))
        if name == "EditMessageText":
            self.screen(chat_id).edit(
                int(method.message_id), text=method.text, keyboard=_markup(method))
            return self._existing(chat_id, int(method.message_id))
        if name == "EditMessageCaption":
            self.screen(chat_id).edit(
                int(method.message_id), text=method.caption or "",
                keyboard=_markup(method))
            return self._existing(chat_id, int(method.message_id))
        if name == "EditMessageMedia":
            media = method.media
            photo = getattr(getattr(media, "media", None), "path", None)
            self.screen(chat_id).edit(
                int(method.message_id), text=media.caption or "",
                photo=str(photo) if photo else None, keyboard=_markup(method))
            return self._existing(chat_id, int(method.message_id))
        if name == "DeleteMessage":
            self.screen(chat_id).drop(int(method.message_id))
            return True
        if name == "EditMessageReplyMarkup":
            self.screen(chat_id).edit(
                int(method.message_id), keyboard=_markup(method))
            return self._existing(chat_id, int(method.message_id))
        return True

    def _existing(self, chat_id: int, message_id: int) -> Message:
        return Message.model_validate({
            "message_id": message_id, "date": _now(),
            "chat": {"id": chat_id, "type": "private"},
            "from": {"id": BOT_ID, "is_bot": True, "first_name": "Shop",
                     "username": BOT_USERNAME},
            "text": "",
        }).as_(None)

    async def stream_content(self, *args, **kwargs):  # pragma: no cover
        if False:
            yield b""

    async def close(self) -> None:
        return None


class Emulator:
    def __init__(self) -> None:
        self.session = PreviewSession()
        self.bot = Bot("0:preview", session=self.session,
                       default=DefaultBotProperties(parse_mode=ParseMode.HTML))
        self.dispatcher = Dispatcher(storage=MemoryStorage())
        self.dispatcher.include_router(router)
        self.update_id = 1
        self.lock = asyncio.Lock()
        notify.bind(self.bot, BOT_USERNAME)

    def _user(self, user_id: int, name: str) -> dict:
        return {"id": user_id, "is_bot": False, "first_name": name,
                "username": f"preview{user_id}"}

    async def _feed(self, update: dict) -> dict:
        self.update_id += 1
        await self.dispatcher.feed_update(
            self.bot, Update.model_validate({"update_id": self.update_id, **update}))
        return {}

    async def send_text(self, user_id: int, name: str, text: str) -> dict:
        async with self.lock:
            screen = self.session.screen(user_id)
            screen.toasts.clear()
            self.session.next_id += 1
            screen.add(self.session.next_id, {
                "text": text, "photo": None, "keyboard": [], "from_user": True,
                "at": _now().strftime("%H:%M")})
            await self._feed({"message": {
                "message_id": self.session.next_id, "date": _now(),
                "chat": {"id": user_id, "type": "private"},
                "from": self._user(user_id, name), "text": text}})
            return screen.dump()

    async def press(self, user_id: int, name: str, message_id: int,
                    data: str) -> dict:
        async with self.lock:
            screen = self.session.screen(user_id)
            screen.toasts.clear()
            current = screen.messages.get(message_id, {})
            payload = {
                "id": f"cb{self.update_id}",
                "from": self._user(user_id, name),
                "chat_instance": str(user_id),
                "data": data,
                "message": {
                    "message_id": message_id, "date": _now(),
                    "chat": {"id": user_id, "type": "private"},
                    "from": {"id": BOT_ID, "is_bot": True, "first_name": "Shop",
                             "username": BOT_USERNAME},
                    "text": "" if current.get("photo") else (current.get("text") or ""),
                    **({"photo": [{"file_id": "x", "file_unique_id": "x",
                                   "width": 100, "height": 100}],
                        "caption": current.get("text") or ""}
                       if current.get("photo") else {}),
                },
            }
            await self._feed({"callback_query": payload})
            return screen.dump()

    def state(self, user_id: int) -> dict:
        return self.session.screen(user_id).dump()

    def reset(self, user_id: int) -> dict:
        self.session.screens[user_id] = Screen()
        return self.session.screen(user_id).dump()


_emulator: Emulator | None = None


def emulator() -> Emulator:
    global _emulator
    if _emulator is None:
        _emulator = Emulator()
    return _emulator
