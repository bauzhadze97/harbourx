"""Password hashing, signed sessions, CSRF and the NOWPayments IPN signature."""
from __future__ import annotations

import base64
import hashlib
import hmac
import json
import re
import secrets
import time
import unicodedata
from pathlib import Path

from .config import settings

# ── passwords ─────────────────────────────────────────────────────────────
# scrypt from the standard library: no extra dependency, memory-hard, and the
# parameters below are the interactive-login set from RFC 7914.
# maxmem has to be raised explicitly: OpenSSL caps scrypt at 32 MiB by
# default, which is just under what n=2**15, r=8 needs.
_SCRYPT = dict(n=2 ** 15, r=8, p=1, dklen=32)
_MAXMEM = 96 * 1024 * 1024


def hash_password(password: str) -> str:
    salt = secrets.token_bytes(16)
    dk = hashlib.scrypt(password.encode("utf-8"), salt=salt, maxmem=_MAXMEM, **_SCRYPT)
    return "scrypt$%d$%d$%d$%s$%s" % (
        _SCRYPT["n"], _SCRYPT["r"], _SCRYPT["p"],
        base64.b64encode(salt).decode(), base64.b64encode(dk).decode(),
    )


def verify_password(password: str, encoded: str) -> bool:
    try:
        scheme, n, r, p, salt_b64, dk_b64 = encoded.split("$")
        if scheme != "scrypt":
            return False
        dk = hashlib.scrypt(
            password.encode("utf-8"),
            salt=base64.b64decode(salt_b64),
            n=int(n), r=int(r), p=int(p),
            dklen=len(base64.b64decode(dk_b64)),
            maxmem=_MAXMEM,
        )
    except (ValueError, TypeError):
        return False
    return hmac.compare_digest(dk, base64.b64decode(dk_b64))


# ── signed tokens (session cookie, CSRF) ──────────────────────────────────
def _sign(payload: bytes) -> str:
    mac = hmac.new(settings.secret_key.encode(), payload, hashlib.sha256).digest()
    return "%s.%s" % (
        base64.urlsafe_b64encode(payload).decode().rstrip("="),
        base64.urlsafe_b64encode(mac).decode().rstrip("="),
    )


def _unsign(token: str) -> bytes | None:
    try:
        payload_b64, mac_b64 = token.split(".", 1)
        payload = base64.urlsafe_b64decode(payload_b64 + "=" * (-len(payload_b64) % 4))
        mac = base64.urlsafe_b64decode(mac_b64 + "=" * (-len(mac_b64) % 4))
    except (ValueError, TypeError):
        return None
    expected = hmac.new(settings.secret_key.encode(), payload, hashlib.sha256).digest()
    if not hmac.compare_digest(mac, expected):
        return None
    return payload


SESSION_TTL = 8 * 3600


def make_session(username: str) -> str:
    body = json.dumps(
        {"u": username, "exp": int(time.time()) + SESSION_TTL, "n": secrets.token_hex(8)},
        separators=(",", ":"),
    ).encode()
    return _sign(body)


def read_session(token: str | None) -> str | None:
    if not token:
        return None
    payload = _unsign(token)
    if payload is None:
        return None
    try:
        data = json.loads(payload)
    except ValueError:
        return None
    if int(data.get("exp", 0)) < time.time():
        return None
    return str(data.get("u") or "") or None


CSRF_TTL = 4 * 3600


def make_csrf(session_token: str) -> str:
    # Bound to the session, so a token lifted from one browser is useless in
    # another.
    anchor = hashlib.sha256(session_token.encode()).hexdigest()[:16]
    body = json.dumps({"a": anchor, "exp": int(time.time()) + CSRF_TTL},
                      separators=(",", ":")).encode()
    return _sign(body)


def check_csrf(token: str | None, session_token: str | None) -> bool:
    if not token or not session_token:
        return False
    payload = _unsign(token)
    if payload is None:
        return False
    try:
        data = json.loads(payload)
    except ValueError:
        return False
    if int(data.get("exp", 0)) < time.time():
        return False
    anchor = hashlib.sha256(session_token.encode()).hexdigest()[:16]
    return hmac.compare_digest(str(data.get("a", "")), anchor)


# ── NOWPayments IPN ───────────────────────────────────────────────────────
def _sorted_json(value):
    """NOWPayments signs the JSON body with its keys sorted, recursively."""
    if isinstance(value, dict):
        return {k: _sorted_json(value[k]) for k in sorted(value)}
    if isinstance(value, list):
        return [_sorted_json(v) for v in value]
    return value


def ipn_signature(body: dict) -> str:
    message = json.dumps(_sorted_json(body), separators=(",", ":"))
    return hmac.new(
        settings.nowpayments_ipn_secret.encode(),
        message.encode(),
        hashlib.sha512,
    ).hexdigest()


def verify_ipn(body: dict, header_signature: str | None) -> bool:
    if not settings.nowpayments_ipn_secret or not header_signature:
        return False
    return hmac.compare_digest(ipn_signature(body), header_signature.strip())


# ── misc hardening helpers ────────────────────────────────────────────────
_SAFE_NAME = re.compile(r"[^a-zA-Z0-9._-]")


def safe_media_name(original: str, suffix_fallback: str = ".jpg") -> str:
    """Never trust an uploaded file name: keep only the extension we allow."""
    ext = Path(original or "").suffix.lower()
    if ext not in {".jpg", ".jpeg", ".png", ".webp", ".gif"}:
        ext = suffix_fallback
    return f"{secrets.token_hex(16)}{ext}"


def media_path(name: str) -> Path | None:
    """Resolve a media file name, refusing anything that escapes the folder."""
    if not name:
        return None
    cleaned = _SAFE_NAME.sub("", Path(name).name)
    if not cleaned:
        return None
    path = (settings.media_dir / cleaned).resolve()
    try:
        path.relative_to(settings.media_dir.resolve())
    except ValueError:
        return None
    return path if path.is_file() else None


_CONTROL = {c: None for c in range(32) if c not in (9, 10, 13)}


def clean_text(value: str | None, limit: int = 4000) -> str:
    """Strip control characters and normalise, then cap the length."""
    if not value:
        return ""
    value = unicodedata.normalize("NFC", value).translate(_CONTROL)
    return value.strip()[:limit]
