"""Configuration, read once from the environment (or a .env file beside it)."""
from __future__ import annotations

import os
import secrets
from dataclasses import dataclass, field
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def _load_dotenv(path: Path) -> None:
    """Minimal .env reader. Values already in the environment win."""
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        os.environ.setdefault(key, value)


def _flag(name: str, default: bool = False) -> bool:
    value = os.environ.get(name)
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


def _ids(name: str) -> set[int]:
    out: set[int] = set()
    for chunk in os.environ.get(name, "").replace(";", ",").split(","):
        chunk = chunk.strip()
        if chunk.lstrip("-").isdigit():
            out.add(int(chunk))
    return out


@dataclass(frozen=True)
class Settings:
    bot_token: str
    bot_admin_ids: set[int]
    review_channel: str

    admin_username: str
    admin_password: str
    secret_key: str
    cookie_secure: bool

    nowpayments_api_key: str
    nowpayments_ipn_secret: str
    payments_mode: str
    public_base_url: str
    shop_currency: str
    default_pay_currency: str

    host: str
    port: int
    db_path: Path
    media_dir: Path

    generated_password: str | None = field(default=None, compare=False)

    @property
    def payments_enabled(self) -> bool:
        """False in demo mode, or whenever the PSP is not fully configured."""
        if self.payments_mode == "demo":
            return False
        return bool(self.nowpayments_api_key and self.nowpayments_ipn_secret)

    @property
    def ipn_url(self) -> str:
        return self.public_base_url.rstrip("/") + "/ipn/nowpayments"


def load_settings() -> Settings:
    _load_dotenv(ROOT / ".env")

    secret = os.environ.get("SECRET_KEY", "").strip()
    if len(secret) < 32:
        # A per-process key still works; it only means sessions do not survive
        # a restart. The startup banner tells the operator to pin one.
        secret = secrets.token_hex(32)

    password = os.environ.get("ADMIN_PASSWORD", "").strip()
    generated = None
    if not password:
        password = secrets.token_urlsafe(12)
        generated = password

    db_path = Path(os.environ.get("DB_PATH", "data/shop.db"))
    if not db_path.is_absolute():
        db_path = ROOT / db_path

    return Settings(
        bot_token=os.environ.get("BOT_TOKEN", "").strip(),
        bot_admin_ids=_ids("BOT_ADMIN_IDS"),
        review_channel=os.environ.get("REVIEW_CHANNEL", "").strip(),
        admin_username=os.environ.get("ADMIN_USERNAME", "admin").strip() or "admin",
        admin_password=password,
        secret_key=secret,
        cookie_secure=_flag("COOKIE_SECURE", False),
        nowpayments_api_key=os.environ.get("NOWPAYMENTS_API_KEY", "").strip(),
        nowpayments_ipn_secret=os.environ.get("NOWPAYMENTS_IPN_SECRET", "").strip(),
        payments_mode=os.environ.get("PAYMENTS_MODE", "auto").strip().lower(),
        public_base_url=os.environ.get("PUBLIC_BASE_URL", "http://127.0.0.1:8791").strip(),
        shop_currency=os.environ.get("SHOP_CURRENCY", "GEL").strip().upper(),
        default_pay_currency=os.environ.get("DEFAULT_PAY_CURRENCY", "usdttrc20").strip().lower(),
        host=os.environ.get("HOST", "127.0.0.1").strip(),
        port=int(os.environ.get("PORT", "8791")),
        db_path=db_path,
        media_dir=ROOT / "media",
        generated_password=generated,
    )


settings = load_settings()
