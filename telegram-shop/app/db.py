"""SQLite storage: schema, connection handling and transaction helpers.

Concurrency notes
-----------------
Everything that moves money or stock runs inside ``tx()``, which opens a
``BEGIN IMMEDIATE`` transaction. That takes SQLite's write lock up front, so two
buyers racing for the last item serialise instead of both reading "1 in stock".
WAL mode keeps readers from blocking behind those writers.
"""
from __future__ import annotations

import sqlite3
import threading
from contextlib import contextmanager
from pathlib import Path
from typing import Iterator

from .config import settings

_local = threading.local()

SCHEMA = """
PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id             INTEGER PRIMARY KEY,           -- telegram user id
    username       TEXT,
    first_name     TEXT,
    language       TEXT    NOT NULL DEFAULT 'ka',
    balance        INTEGER NOT NULL DEFAULT 0,    -- minor units (tetri)
    purchases      INTEGER NOT NULL DEFAULT 0,
    referred_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    referral_bonus INTEGER NOT NULL DEFAULT 0,
    referral_count INTEGER NOT NULL DEFAULT 0,
    is_banned      INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    last_seen_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (balance >= 0)
);

CREATE TABLE IF NOT EXISTS cities (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    name     TEXT    NOT NULL UNIQUE,
    position INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS districts (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    city_id  INTEGER NOT NULL REFERENCES cities(id) ON DELETE CASCADE,
    name     TEXT    NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    UNIQUE (city_id, name)
);

CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    city_id     INTEGER NOT NULL REFERENCES cities(id) ON DELETE CASCADE,
    district_id INTEGER REFERENCES districts(id) ON DELETE SET NULL,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    price       INTEGER NOT NULL,                 -- minor units
    photo       TEXT,                             -- file name under media/
    position    INTEGER NOT NULL DEFAULT 0,
    is_active   INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (price > 0)
);

-- One row per physical unit on sale. Selling = flipping exactly one row to
-- 'sold', which is what makes overselling impossible.
CREATE TABLE IF NOT EXISTS stock_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    payload    TEXT    NOT NULL,                  -- what the buyer receives
    photo      TEXT,
    status     TEXT    NOT NULL DEFAULT 'available',  -- available|sold|withdrawn
    order_id   INTEGER,
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    sold_at    TEXT,
    CHECK (status IN ('available', 'sold', 'withdrawn'))
);
CREATE INDEX IF NOT EXISTS idx_stock_pick ON stock_items(product_id, status, id);

CREATE TABLE IF NOT EXISTS orders (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    product_id    INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    stock_item_id INTEGER NOT NULL UNIQUE REFERENCES stock_items(id) ON DELETE RESTRICT,
    price         INTEGER NOT NULL,               -- list price, minor units
    discount      INTEGER NOT NULL DEFAULT 0,     -- minor units taken off
    paid          INTEGER NOT NULL,               -- what was actually debited
    status        TEXT    NOT NULL DEFAULT 'completed', -- completed|disputed|refunded
    dispute_until TEXT    NOT NULL,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (status IN ('completed', 'disputed', 'refunded'))
);
CREATE INDEX IF NOT EXISTS idx_orders_user ON orders(user_id, id DESC);

CREATE TABLE IF NOT EXISTS disputes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    message    TEXT    NOT NULL DEFAULT '',
    photo      TEXT,
    status     TEXT    NOT NULL DEFAULT 'open',   -- open|resolved|rejected
    resolution TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    closed_at  TEXT,
    CHECK (status IN ('open', 'resolved', 'rejected'))
);

CREATE TABLE IF NOT EXISTS reviews (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    rating     INTEGER NOT NULL,
    text       TEXT    NOT NULL DEFAULT '',
    is_public  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (rating BETWEEN 1 AND 5)
);

-- A deposit is a single-use invoice: one row, one address, never reissued.
CREATE TABLE IF NOT EXISTS deposits (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    order_ref      TEXT    NOT NULL UNIQUE,       -- our id sent to the PSP
    payment_id     TEXT    UNIQUE,                -- NOWPayments payment id
    pay_address    TEXT,
    pay_amount     TEXT,
    pay_currency   TEXT,
    price_amount   INTEGER NOT NULL,              -- minor units of shop currency
    price_currency TEXT    NOT NULL,
    status         TEXT    NOT NULL DEFAULT 'waiting',
    credited       INTEGER NOT NULL DEFAULT 0,    -- minor units actually credited
    actually_paid  TEXT,
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    expires_at     TEXT
);
CREATE INDEX IF NOT EXISTS idx_deposits_user ON deposits(user_id, id DESC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_deposits_address
    ON deposits(pay_address) WHERE pay_address IS NOT NULL;

-- Every IPN body we accept is stored once; replays are ignored by digest.
CREATE TABLE IF NOT EXISTS ipn_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    digest     TEXT    NOT NULL UNIQUE,
    payment_id TEXT,
    status     TEXT,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Append-only money log. Balance is derived work, this is the audit trail.
CREATE TABLE IF NOT EXISTS ledger (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    amount     INTEGER NOT NULL,                  -- signed, minor units
    balance_after INTEGER NOT NULL,
    kind       TEXT    NOT NULL,                  -- deposit|purchase|refund|bonus|admin
    ref        TEXT    NOT NULL DEFAULT '',
    note       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_ledger_user ON ledger(user_id, id DESC);

CREATE TABLE IF NOT EXISTS admin_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    actor      TEXT    NOT NULL,
    action     TEXT    NOT NULL,
    detail     TEXT    NOT NULL DEFAULT '',
    ip         TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ip         TEXT    NOT NULL,
    ok         INTEGER NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_login_ip ON login_attempts(ip, id DESC);
"""

DEFAULT_SETTINGS = {
    "start_photo": "",
    "start_text": (
        "ჰეი მადლობა რო მეწვიე შენ ჯეინის საბურგერეში ხარ ^_^\n"
        "👾 ადმინისტრაცია: @stevenson132geo\n"
        "👾 ოპერატორი: @Tkachenko1133\n\n"
        "აბა კარგად გეერთე"
    ),
    "rules_text": (
        "<b>წესები</b>\n\n"
        "1. შეკვეთის აღება ხდება გადახდიდან მაშინვე.\n"
        "2. თუ ვერ იპოვე — დავის გახსნა შეგიძლია ყიდვიდან 3 საათის განმავლობაში.\n"
        "3. ამ ვადის გასვლის შემდეგ პრეტენზია აღარ განიხილება.\n"
        "4. ბალანსის შევსება მხოლოდ ბოტში გაცემულ ერთჯერად მისამართზე.\n"
        "5. ერთი და იგივე მისამართი მეორედ არასდროს გამოიყენება."
    ),
    "support_url": "https://t.me/Tkachenko1133",
    "chat_url": "https://t.me/testchanelll11",
    "channel_url": "https://t.me/testchanelll11",
    "admin_contact": "@stevenson132geo",
    "operator_contact": "@Tkachenko1133",
    "dispute_window_hours": "3",
    "referral_bonus_percent": "5",
    "discount_tiers": "3:2,5:5,10:7,20:10",
    "min_deposit": "500",
    "site_title": "ჯეინის საბურგერე",
    "site_tagline": "Telegram-ის მაღაზია",
}


def connect() -> sqlite3.Connection:
    """One connection per thread, configured the same way every time."""
    conn = getattr(_local, "conn", None)
    if conn is not None:
        return conn
    settings.db_path.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(
        settings.db_path,
        timeout=30.0,
        isolation_level=None,  # explicit transactions only
        check_same_thread=False,
    )
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode = WAL")
    conn.execute("PRAGMA foreign_keys = ON")
    conn.execute("PRAGMA busy_timeout = 30000")
    conn.execute("PRAGMA synchronous = FULL")
    _local.conn = conn
    return conn


@contextmanager
def tx() -> Iterator[sqlite3.Connection]:
    """A write transaction that grabs the lock immediately."""
    conn = connect()
    conn.execute("BEGIN IMMEDIATE")
    try:
        yield conn
    except Exception:
        conn.execute("ROLLBACK")
        raise
    else:
        conn.execute("COMMIT")


def query(sql: str, params: tuple | dict = ()) -> list[sqlite3.Row]:
    return connect().execute(sql, params).fetchall()


def query_one(sql: str, params: tuple | dict = ()) -> sqlite3.Row | None:
    return connect().execute(sql, params).fetchone()


def init_db() -> None:
    conn = connect()
    conn.executescript(SCHEMA)
    with tx() as c:
        for key, value in DEFAULT_SETTINGS.items():
            c.execute(
                "INSERT INTO settings(key, value) VALUES(?, ?) "
                "ON CONFLICT(key) DO NOTHING",
                (key, value),
            )


def get_setting(key: str, default: str = "") -> str:
    row = query_one("SELECT value FROM settings WHERE key = ?", (key,))
    return row["value"] if row else DEFAULT_SETTINGS.get(key, default)


def get_settings_map() -> dict[str, str]:
    out = dict(DEFAULT_SETTINGS)
    for row in query("SELECT key, value FROM settings"):
        out[row["key"]] = row["value"]
    return out


def set_setting(key: str, value: str) -> None:
    with tx() as c:
        c.execute(
            "INSERT INTO settings(key, value) VALUES(?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            (key, value),
        )
