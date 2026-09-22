"""Fill the database with sample data so the panel is not an empty shell.

Safe to re-run: it only adds what is missing. Never run it on live data you
care about — it also creates fake users, orders and reviews.
"""
from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from app import db, repo  # noqa: E402

CITIES = {
    "თბილისი": ["ვაკე", "საბურთალო", "ისანი", "გლდანი"],
    "ბათუმი": ["ძველი ბათუმი", "ხელვაჩაური"],
    "ქუთაისი": ["ცენტრი"],
}

PRODUCTS = [
    ("თბილისი", "ვაკე", "ჯეინის ცხარე საწებელი 250მლ", 2500,
     "სახლში მომზადებული, ძალიან ცხარე. მინის ქილა."),
    ("თბილისი", "საბურთალო", "ადჟიკა კლასიკური 200მლ", 1800,
     "ტრადიციული რეცეპტი, საშუალო სიცხარე."),
    ("თბილისი", "გლდანი", "ტყემალი მწვანე 500მლ", 1200,
     "გაზაფხულის მოსავლიდან."),
    ("ბათუმი", "ძველი ბათუმი", "საწებელი ნიგვზით 300მლ", 3200,
     "ნიგვზის ბაზაზე, რბილი გემო."),
    ("ქუთაისი", "ცენტრი", "ცხარე წიწაკა მწნილი", 1500,
     "ქილა 400 გრამი."),
]


def main() -> None:
    db.init_db()
    db.set_setting("start_photo", "start.jpg")

    for city, districts in CITIES.items():
        with db.tx() as c:
            c.execute("INSERT OR IGNORE INTO cities(name) VALUES(?)", (city,))
            city_id = c.execute("SELECT id FROM cities WHERE name = ?", (city,)).fetchone()[0]
            for i, district in enumerate(districts):
                c.execute(
                    "INSERT OR IGNORE INTO districts(city_id, name, position) VALUES(?,?,?)",
                    (city_id, district, i))

    for city, district, name, price, description in PRODUCTS:
        with db.tx() as c:
            row = c.execute("SELECT id FROM products WHERE name = ?", (name,)).fetchone()
            if row:
                continue
            city_id = c.execute("SELECT id FROM cities WHERE name = ?", (city,)).fetchone()[0]
            district_row = c.execute(
                "SELECT id FROM districts WHERE city_id = ? AND name = ?",
                (city_id, district)).fetchone()
            cur = c.execute(
                "INSERT INTO products(city_id, district_id, name, description, price) "
                "VALUES(?,?,?,?,?)",
                (city_id, district_row[0] if district_row else None, name,
                 description, price))
            product_id = cur.lastrowid
            for n in range(1, 5):
                c.execute(
                    "INSERT INTO stock_items(product_id, payload) VALUES(?,?)",
                    (product_id,
                     f"{city}, {district} — საკნის კოდი {1000 + product_id * 7 + n}, "
                     f"თარო {n}"))

    demo_users = [
        (501234001, "giorgi_g", "გიორგი"),
        (501234002, "nino_k", "ნინო"),
        (501234003, "lasha_m", "ლაშა"),
    ]
    for uid, username, first in demo_users:
        repo.touch_user(uid, username, first)
        if repo.get_user(uid)["balance"] == 0:
            repo.adjust_balance(uid, 12000, "სადემონსტრაციო ბალანსი", "seed")

    if db.query_one("SELECT COUNT(*) n FROM orders")["n"] == 0:
        products = db.query("SELECT id FROM products ORDER BY id")
        for (uid, _, _), product in zip(demo_users, products):
            try:
                order = repo.purchase(uid, int(product["id"]))
            except repo.ShopError as exc:
                print("skip:", exc)
                continue
            repo.add_review(order["order_id"], uid, 5,
                            "ყველაფერი ზუსტად ისე იყო, როგორც წერია. მადლობა!")

        # One order left in dispute so the panel has something to act on.
        try:
            order = repo.purchase(demo_users[1][0], int(products[3]["id"]))
            repo.open_dispute(order["order_id"], demo_users[1][0],
                              "მითითებულ ადგილას ვერაფერი ვიპოვე, 40 წუთი ვეძებდი.")
        except repo.ShopError as exc:
            print("skip dispute:", exc)

    print("სადემონსტრაციო მონაცემები მზადაა.")
    print("  ქალაქი:", db.query_one("SELECT COUNT(*) n FROM cities")["n"])
    print("  პროდუქტი:", db.query_one("SELECT COUNT(*) n FROM products")["n"])
    print("  მარაგი:", db.query_one(
        "SELECT COUNT(*) n FROM stock_items WHERE status='available'")["n"])
    print("  შეკვეთა:", db.query_one("SELECT COUNT(*) n FROM orders")["n"])


if __name__ == "__main__":
    main()
