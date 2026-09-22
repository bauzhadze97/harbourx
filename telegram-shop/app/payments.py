"""NOWPayments client.

Each top-up calls POST /v1/payment, and NOWPayments answers with a fresh
``pay_address`` for that invoice alone. That is what makes the deposit address
single-use: we never re-read an old deposit row to show an address again, and
``deposits.pay_address`` carries a unique index so the same address can never
end up on two invoices.
"""
from __future__ import annotations

import logging
import secrets

import httpx

from .config import settings

log = logging.getLogger("shop.payments")

API = "https://api.nowpayments.io/v1"
TIMEOUT = httpx.Timeout(20.0, connect=10.0)


class PaymentError(Exception):
    pass


def _headers() -> dict[str, str]:
    return {"x-api-key": settings.nowpayments_api_key,
            "Content-Type": "application/json"}


async def api_status() -> str:
    if not settings.payments_enabled:
        return "demo"
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT) as client:
            r = await client.get(f"{API}/status")
            r.raise_for_status()
            return str(r.json().get("message", "unknown"))
    except Exception as exc:  # network or API trouble should not crash a page
        log.warning("nowpayments status check failed: %s", exc)
        return "unreachable"


async def min_amount(pay_currency: str, price_currency: str) -> float | None:
    if not settings.payments_enabled:
        return None
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT) as client:
            r = await client.get(
                f"{API}/min-amount",
                params={"currency_from": pay_currency,
                        "currency_to": price_currency.lower()},
                headers=_headers(),
            )
            r.raise_for_status()
            return float(r.json().get("min_amount"))
    except Exception as exc:
        log.info("min-amount lookup failed: %s", exc)
        return None


async def available_currencies() -> list[str]:
    if not settings.payments_enabled:
        return ["usdttrc20", "btc", "ltc", "trx"]
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT) as client:
            r = await client.get(f"{API}/currencies", headers=_headers())
            r.raise_for_status()
            return [str(c).lower() for c in r.json().get("currencies", [])]
    except Exception as exc:
        log.warning("currency list failed: %s", exc)
        return []


async def create_payment(*, order_ref: str, amount_minor: int, price_currency: str,
                         pay_currency: str, description: str) -> dict:
    """Create one invoice. The returned pay_address belongs to it alone."""
    price_amount = round(amount_minor / 100, 2)

    if not settings.payments_enabled:
        # Demo mode keeps the whole flow testable without live keys. The
        # address is obviously fake so nobody mistakes it for a real one.
        return {
            "payment_id": "demo_" + secrets.token_hex(8),
            "pay_address": "DEMO" + secrets.token_hex(16).upper(),
            "pay_amount": f"{price_amount / 2.7:.6f}",
            "pay_currency": pay_currency,
            "payment_status": "waiting",
            "demo": True,
        }

    body = {
        "price_amount": price_amount,
        "price_currency": price_currency.lower(),
        "pay_currency": pay_currency.lower(),
        "order_id": order_ref,
        "order_description": description[:200],
        "ipn_callback_url": settings.ipn_url,
    }
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT) as client:
            r = await client.post(f"{API}/payment", json=body, headers=_headers())
    except httpx.HTTPError as exc:
        log.error("payment request failed: %s", exc)
        raise PaymentError("network") from exc

    if r.status_code >= 400:
        # Never log the API key; the body can carry the reason safely.
        log.error("payment rejected (%s): %s", r.status_code, r.text[:300])
        raise PaymentError(f"api_{r.status_code}")

    data = r.json()
    if not data.get("pay_address"):
        raise PaymentError("no_address")
    return data


async def payment_status(payment_id: str) -> dict | None:
    if not settings.payments_enabled or payment_id.startswith("demo_"):
        return None
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT) as client:
            r = await client.get(f"{API}/payment/{payment_id}", headers=_headers())
            r.raise_for_status()
            return r.json()
    except Exception as exc:
        log.info("payment status lookup failed: %s", exc)
        return None
