/* ============================================================================
   HarbourX portal data layer
   ----------------------------------------------------------------------------
   The portfolio and transactions pages read the same account record and the
   same price feeds as the dashboard. Rather than each page growing its own
   copy of "what does -0.5 BTC -> A$48,250.50 mean", the answers live here once.

   Nothing in this file renders anything. It reads the stored record, talks to
   the price feeds, and turns the strings a transaction carries into numbers a
   page can filter and total.

   window.hxPortal
     user()                     the signed-in client's record, or null
     requireUser()              the same, but sends a signed-out visitor to sign-in
     symbol(code)               the currency symbol for a code
     money(value, code)         formatted currency
     number(value, decimals)    formatted number
     btcPrice(code)             a live rate, a cached one, or a sane default
     parse(transaction)         { direction, asset, value, local, display }
     shell(subtitle)            the name, initials, menu and year every page has
   ========================================================================== */
(function () {
  "use strict";

  /* The figure to fall back on when every feed is unreachable — the same table
     the dashboard carries, so two pages open side by side cannot disagree. */
  var DEFAULT_BTC_PRICE = {
    USD: 95000, EUR: 88000, GBP: 75000, AUD: 145000, CAD: 130000,
    NZD: 158000, CHF: 84000, JPY: 14800000, SGD: 128000
  };
  var PRICE_TIMEOUT_MS = 6000;

  var SYMBOLS = {
    USD: "$", EUR: "€", GBP: "£", JPY: "¥", CNY: "¥", AUD: "A$", CAD: "C$",
    NZD: "NZ$", CHF: "Fr", SGD: "S$", HKD: "HK$", INR: "₹", KRW: "₩", NGN: "₦",
    PHP: "₱", RUB: "₽", TRY: "₺", UAH: "₴", VND: "₫", ZAR: "R", BRL: "R$",
    MXN: "MX$", PLN: "zł", SEK: "kr", NOK: "kr", DKK: "kr", THB: "฿", IDR: "Rp",
    MYR: "RM", AED: "د.إ", SAR: "﷼", ILS: "₪"
  };

  function user() {
    try {
      var raw = localStorage.getItem("user");
      var parsed = raw ? JSON.parse(raw) : null;
      return parsed && parsed.email ? parsed : null;
    } catch (error) {
      return null;
    }
  }

  function requireUser() {
    var record = user();
    if (!record) window.location.replace("login.html");
    return record;
  }

  function symbol(code) {
    var upper = String(code || "USD").toUpperCase();
    try {
      var parts = new Intl.NumberFormat(undefined, { style: "currency", currency: upper }).formatToParts(0);
      var found = parts.find(function (part) { return part.type === "currency"; });
      if (found && found.value) return found.value;
    } catch (error) { /* an unknown code; the table below still has an answer */ }
    return SYMBOLS[upper] || upper;
  }

  function money(value, code) {
    var upper = String(code || "USD").toUpperCase();
    try {
      return new Intl.NumberFormat(undefined, {
        style: "currency", currency: upper,
        minimumFractionDigits: 2, maximumFractionDigits: 2
      }).format(Number(value) || 0);
    } catch (error) {
      return symbol(upper) + number(value, 2);
    }
  }

  function number(value, decimals) {
    var places = decimals == null ? 2 : Number(decimals);
    return (Number(value) || 0).toLocaleString(undefined, {
      minimumFractionDigits: places, maximumFractionDigits: places
    });
  }

  /* ------------------------------------------------------------ the price */

  function cacheKey(code) { return "btc_" + String(code || "usd").toLowerCase() + "_price"; }

  function cachedPrice(code) {
    try { return Number(localStorage.getItem(cacheKey(code))) || 0; } catch (error) { return 0; }
  }

  /**
   * A live rate if any feed answers, the last one this browser saw if not, and
   * the table above if it has never seen one. The feeds are raced rather than
   * tried in turn, so one rate-limited provider does not hold up the page.
   */
  function btcPrice(code) {
    var upper = String(code || "USD").toUpperCase();
    var lower = upper.toLowerCase();
    var endpoints = [
      { url: "https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=" + lower,
        read: function (d) { return Number(d && d.bitcoin && d.bitcoin[lower]); } },
      { url: "https://api.coinbase.com/v2/prices/BTC-" + upper + "/spot",
        read: function (d) { return Number(d && d.data && d.data.amount); } }
    ];

    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, PRICE_TIMEOUT_MS);

    var attempts = endpoints.map(function (endpoint) {
      return fetch(endpoint.url, { cache: "no-store", signal: controller.signal })
        .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
        .then(function (data) {
          var price = endpoint.read(data);
          if (!(price > 0)) return Promise.reject();
          return price;
        });
    });

    /* Promise.any is the shape this wants — first success wins, and the whole
       thing only rejects when every feed has. Older browsers get the same
       behaviour from the fallback below. */
    var race = typeof Promise.any === "function"
      ? Promise.any(attempts)
      : new Promise(function (resolve, reject) {
          var left = attempts.length;
          attempts.forEach(function (attempt) {
            attempt.then(resolve, function () { if (--left === 0) reject(); });
          });
        });

    return race
      .then(function (price) {
        clearTimeout(timer);
        controller.abort();
        try { localStorage.setItem(cacheKey(upper), String(price)); } catch (error) { /* private window */ }
        return { price: price, live: true };
      })
      .catch(function () {
        clearTimeout(timer);
        var fallback = cachedPrice(upper) || DEFAULT_BTC_PRICE[upper] || 0;
        return { price: fallback, live: false };
      });
  }

  /* ------------------------------------------------------ reading a record

     A transaction carries its amount as the string a person should read:
     "+1.25 BTC", "-A$1,000.00", "-0.50000000 BTC -> A$48,250.50". Useful for
     display, useless for filtering or totalling, so this turns it back into
     the numbers behind it. */

  /* Every asset a client can hold. A row reading "-1000.00000000 DOGE" used to
     match nothing here and fall through to the local-currency branch, where it
     was counted as a thousand dollars of cash leaving the account. */
  var ASSET_SYMBOLS = ["BTC", "ETH", "XRP", "BNB", "SOL", "DOGE", "ADA", "LINK"];
  var ASSET_PATTERN = new RegExp("([+-]?)\\s*([\\d,]+(?:\\.\\d+)?)\\s*(" + ASSET_SYMBOLS.join("|") + ")\\b", "i");
  var LOCAL_PATTERN = new RegExp(
    "([+-]?)\\s*[^\\d\\s+-]{0,4}\\s*([\\d,]+(?:\\.\\d{1,2})?)(?!\\s*(?:" + ASSET_SYMBOLS.join("|") + "))",
    "i"
  );

  function toNumber(text) { return Number(String(text || "").replace(/,/g, "")) || 0; }

  function parse(transaction) {
    var amount = String((transaction && transaction.amount) || "");
    var out = {
      direction: amount.trim().charAt(0) === "-" ? "out" : "in",
      asset: "",
      value: 0,      // in the asset's own units
      local: 0,      // the local-currency side, when the row has one
      display: amount
    };

    var asset = amount.match(ASSET_PATTERN);
    if (asset) {
      out.asset = asset[3].toUpperCase();
      out.value = toNumber(asset[2]);
    }

    /* A conversion names both sides: "-0.5 BTC -> A$48,250.50". The local
       figure is whatever follows the arrow; on a row with no arrow it is the
       only figure there is. */
    var arrow = amount.indexOf("->");
    var tail = arrow >= 0 ? amount.slice(arrow + 2) : amount;
    if (!asset || arrow >= 0) {
      var local = tail.match(LOCAL_PATTERN);
      if (local) {
        out.local = toNumber(local[2]);
        if (!out.asset) {
          out.asset = "LOCAL";
          out.value = out.local;
        }
      }
    }
    return out;
  }

  /* -------------------------------------------------------------- the page */

  /** The furniture every portal page carries: who is signed in, and the menu. */
  function shell(subtitle) {
    var record = user();
    if (record && record.name) {
      var nameEl = document.getElementById("userName");
      var initialsEl = document.getElementById("userInitials");
      if (nameEl) nameEl.textContent = record.name;
      if (initialsEl) {
        initialsEl.textContent = record.name.split(/\s+/).slice(0, 2)
          .map(function (part) { return part.charAt(0); }).join("").toUpperCase() || "HX";
      }
    }
    if (subtitle) {
      var sub = document.querySelector(".portal-user small");
      if (sub) sub.textContent = subtitle;
    }

    var menu = document.getElementById("portalMenu");
    var sidebar = document.getElementById("portalSidebar");
    if (menu && sidebar) {
      menu.addEventListener("click", function () { sidebar.classList.toggle("is-open"); });
    }

    var year = document.getElementById("year");
    if (year) year.textContent = new Date().getFullYear();
    return record;
  }

  /* ------------------------------------------------------- the asset table */

  /* Indicative prices as a ratio of Bitcoin's, for when the market call does
     not answer. The same table the dashboard carries, so two pages open side
     by side cannot disagree about what a holding is worth. */
  var INDICATIVE_RATIO = {
    BTC: 1, ETH: 0.038, XRP: 0.00003, BNB: 0.0089,
    SOL: 0.0021, DOGE: 0.0000021, ADA: 0.0000095, LINK: 0.00024
  };

  var assetTable = null;

  /** The supported assets, fetched once per page. */
  function assets() {
    if (assetTable) return assetTable;
    assetTable = fetch("assets.php", { cache: "no-store" })
      .then(function (response) { return response.json(); })
      .then(function (data) { return (data && data.assets) || {}; })
      .catch(function () { return {}; });
    return assetTable;
  }

  /**
   * A price for every asset, and whether it was measured.
   *
   * One /coins/markets call covers the lot. If it fails, the last board this
   * browser saw is used; if there is none, Bitcoin's price times the ratios
   * above — marked not live, so a page can say which kind of figure it shows.
   */
  function board(code) {
    var upper = String(code || "USD").toUpperCase();
    var lower = upper.toLowerCase();
    var cacheKey = "hx_board_" + lower;

    return assets().then(function (table) {
      var symbols = Object.keys(table).length ? Object.keys(table) : Object.keys(INDICATIVE_RATIO);
      var ids = symbols
        .map(function (name) { return (table[name] && table[name].coingeckoId) || ""; })
        .filter(Boolean)
        .join(",");

      var controller = new AbortController();
      var timer = setTimeout(function () { controller.abort(); }, PRICE_TIMEOUT_MS);

      return fetch(
        "https://api.coingecko.com/api/v3/coins/markets?vs_currency=" + lower
          + "&ids=" + ids + "&sparkline=false&price_change_percentage=24h",
        { cache: "no-store", signal: controller.signal }
      )
        .then(function (response) {
          if (!response.ok) throw new Error("Market feed unavailable");
          return response.json();
        })
        .then(function (rows) {
          if (!Array.isArray(rows) || !rows.length) throw new Error("Empty market feed");
          var byId = {};
          rows.forEach(function (row) { byId[String(row.id)] = row; });

          var prices = {};
          symbols.forEach(function (name) {
            var row = byId[(table[name] && table[name].coingeckoId) || ""];
            var price = row ? Number(row.current_price) : 0;
            if (price > 0) prices[name] = { price: price, change: Number(row.price_change_percentage_24h) };
          });
          if (!prices.BTC) throw new Error("No Bitcoin quote");

          try { localStorage.setItem(cacheKey, JSON.stringify(prices)); } catch (e) { /* full or blocked */ }
          return { prices: prices, live: true, table: table };
        })
        .catch(function () {
          try {
            var cached = JSON.parse(localStorage.getItem(cacheKey) || "null");
            if (cached && cached.BTC) return { prices: cached, live: false, table: table };
          } catch (e) { /* fall through */ }

          return btcPrice(upper).then(function (result) {
            var prices = {};
            symbols.forEach(function (name) {
              var ratio = INDICATIVE_RATIO[name];
              if (ratio) prices[name] = { price: result.price * ratio, change: NaN };
            });
            return { prices: prices, live: false, table: table };
          });
        })
        .finally(function () { clearTimeout(timer); });
    });
  }

  /** What this account holds, largest first, priced from a board. */
  function holdings(record, quote) {
    var stored = (record && typeof record.holdings === "object" && record.holdings) || {};
    var table = (quote && quote.table) || {};
    var prices = (quote && quote.prices) || {};

    return Object.keys(Object.keys(table).length ? table : INDICATIVE_RATIO)
      .map(function (name) {
        var amount = name === "BTC"
          ? Math.max(0, Number(record && record.btc) || 0)
          : Math.max(0, Number(stored[name]) || 0);
        var price = (prices[name] && prices[name].price) || 0;
        var asset = table[name] || { name: name, glyph: "·", decimals: 8, swatch: "btc" };
        return { symbol: name, asset: asset, amount: amount, price: price, value: amount * price };
      })
      .filter(function (holding) { return holding.amount > 0; })
      .sort(function (a, b) { return b.value - a.value; });
  }

  window.hxPortal = {
    user: user,
    requireUser: requireUser,
    symbol: symbol,
    money: money,
    number: number,
    btcPrice: btcPrice,
    assets: assets,
    board: board,
    holdings: holdings,
    parse: parse,
    shell: shell
  };
})();
