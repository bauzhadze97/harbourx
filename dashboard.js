/* ============================================================================
   HarbourX client dashboard behaviour
   ----------------------------------------------------------------------------
   Extracted from dashboard.html for the same reason as dashboard.css: the page
   is uncacheable, this file is not. Loaded with `defer`, so it still runs after
   the document is parsed, exactly as it did at the end of <body>.
   ========================================================================== */

let currentUser = null;

try {
  const rawUser = localStorage.getItem("user");
  if (!rawUser) {
    window.location.replace("login.html");
  } else {
    currentUser = JSON.parse(rawUser);
    if (!currentUser || !currentUser.email) {
      localStorage.removeItem("user");
      window.location.replace("login.html");
    }
  }
} catch (e) {
  localStorage.removeItem("user");
  window.location.replace("login.html");
}

if (!currentUser) {
  throw new Error("No logged in user found.");
}

const btcAmount = Number(currentUser.btc || 0);
const selectedCurrencyRaw = String(currentUser.currency || "USD").toUpperCase();
const selectedCurrency = /^[A-Z]{3}$/.test(selectedCurrencyRaw) ? selectedCurrencyRaw : "USD";
const selectedCountryRaw = String(currentUser.country || "").toUpperCase();
const currencyCountryDefaults = {
  AUD: "AU", CAD: "CA", GBP: "GB", NZD: "NZ", USD: "US", EUR: "IE",
  JPY: "JP", SGD: "SG", CHF: "CH", ZAR: "ZA", INR: "IN"
};
const selectedCountry = selectedCountryRaw || currencyCountryDefaults[selectedCurrency] || "";
const fallbackPortfolioUsd = Number(currentUser.portfolioUsd || 0);
const fallbackBtcPrice = btcAmount > 0 && fallbackPortfolioUsd > 0 ? fallbackPortfolioUsd / btcAmount : 0;

// Indicative BTC prices used only when every live price feed and cached price is unavailable
// (for example with no network). Live rates from fetchBtcPrice always take priority.
const DEFAULT_BTC_PRICE = {
  USD: 95000, EUR: 88000, GBP: 75000, AUD: 145000, CAD: 130000,
  NZD: 158000, CHF: 84000, JPY: 14800000, SGD: 128000
};
const defaultPrice = DEFAULT_BTC_PRICE[selectedCurrency] || 0;
const fallbackPrice = Number(localStorage.getItem(`btc_${selectedCurrency.toLowerCase()}_price`) || 0) || fallbackBtcPrice || defaultPrice || 0;

const state = {
  portfolioValue: 0,
  selectedCurrency,
  selectedCountry,
  btcLocalRate: fallbackPrice,
  mainBalance: Number(currentUser.mainBalance || 0),
  feeRequired: !!currentUser.withdrawalFeeRequired,
  feeAmount: Math.max(0, Number(currentUser.withdrawalFeeAmount || 0)),
  feePercent: Math.max(0, Number(currentUser.withdrawalFeePercent || 0)),
  feeNote: String(currentUser.withdrawalFeeNote || ""),
  balances: {
    BTC: {
      total: btcAmount,
      available: btcAmount,
      frozen: btcAmount,
      pending: btcAmount
    }
  },
  prices: {
    BTC: fallbackPrice || 0,
    ETH: (fallbackPrice || defaultPrice || 0) * 0.038,
    SOL: (fallbackPrice || defaultPrice || 0) * 0.0021
  },
  amlStatus: ["verified", "under_review", "unverified"].includes(String(currentUser.amlStatus || "").toLowerCase())
    ? String(currentUser.amlStatus).toLowerCase()
    : "unverified",
  bankAccounts: [],
  transactions: Array.isArray(currentUser.transactions) && currentUser.transactions.length
    ? currentUser.transactions
    : [
        {
          date: new Date().toISOString().split("T")[0],
          type: "Received",
          amount: `+${btcAmount.toFixed(2)} BTC`,
          status: "Completed",
          details: "BTC account",
          detailsUrl: ""
        }
      ]
};

const txList = document.getElementById("txList");
const profileName = document.getElementById("profileName");
const profileLocation = document.getElementById("profileLocation");
const portfolioValueEl = document.getElementById("portfolioValue");
const totalBtcEl = document.getElementById("totalBtc");
const frozenBtcEl = document.getElementById("frozenBtc");
const pendingBtcEl = document.getElementById("pendingBtc");
const assetBtcStrong = document.getElementById("assetBtcStrong");
const assetBtcUsd = document.getElementById("assetBtcUsd");
const assetEthFiat = document.getElementById("assetEthFiat");
const assetUsdcFiat = document.getElementById("assetUsdcFiat");

const withdrawModal = document.getElementById("withdrawModal");
const openWithdrawBtn = document.getElementById("openWithdrawBtn");
const closeWithdrawModalBtn = document.getElementById("closeWithdrawModalBtn");
const cancelWithdrawBtn = document.getElementById("cancelWithdrawBtn");
const submitWithdrawBtn = document.getElementById("submitWithdrawBtn");
const refreshBtn = document.getElementById("refreshBtn");
const amlStatusBtn = document.getElementById("amlStatusBtn");
const changePasswordBtn = document.getElementById("changePasswordBtn");
const logoutBtn = document.getElementById("logoutBtn");

const withdrawAmount = document.getElementById("withdrawAmount");
const withdrawAmountLabel = document.getElementById("withdrawAmountLabel");
const withdrawMaxBtn = document.getElementById("withdrawMaxBtn");
const withdrawMaxLabel = document.getElementById("withdrawMaxLabel");
const withdrawSourceSeg = document.getElementById("withdrawSourceSeg");
const withdrawRateRow = document.getElementById("withdrawRateRow");
let withdrawSource = "btc";
const bankAccountSelect = document.getElementById("bankAccountSelect");
const addPaymentMethodBtn = document.getElementById("addPaymentMethodBtn");
const bankConnectionStatus = document.getElementById("bankConnectionStatus");
const bankConnectionStatusText = document.getElementById("bankConnectionStatusText");
const btcAudRateLabel = document.getElementById("btcAudRateLabel");
const expectedAmountLabel = document.getElementById("expectedAmountLabel");
const withdrawModalTitle = document.getElementById("withdrawModalTitle");
const withdrawModalHint = document.getElementById("withdrawModalHint");
const rateTitleLabel = document.getElementById("rateTitleLabel");
const estimatedCurrencyLabel = document.getElementById("estimatedCurrencyLabel");
const withdrawMessage = document.getElementById("withdrawMessage");
const withdrawFeeRow = document.getElementById("withdrawFeeRow");
const withdrawFeeLabel = document.getElementById("withdrawFeeLabel");
const withdrawFeeAmountLabel = document.getElementById("withdrawFeeAmountLabel");
const withdrawFeeNoticeNote = document.getElementById("withdrawFeeNoticeNote");
const withdrawAvailableLabel = document.getElementById("withdrawAvailableLabel");
const withdrawAvailableValue = document.getElementById("withdrawAvailableValue");
const withdrawInputCurrency = document.getElementById("withdrawInputCurrency");
const withdrawPlatformFee = document.getElementById("withdrawPlatformFee");
const withdrawBankFee = document.getElementById("withdrawBankFee");
const withdrawNetAmount = document.getElementById("withdrawNetAmount");
const withdrawBalanceSource = document.getElementById("withdrawBalanceSource");

const feeModal = document.getElementById("feeModal");
const feeModalTitle = document.getElementById("feeModalTitle");
const closeFeeModalBtn = document.getElementById("closeFeeModalBtn");
const cancelFeeBtn = document.getElementById("cancelFeeBtn");
const confirmFeeBtn = document.getElementById("confirmFeeBtn");
const feeModalLead = document.getElementById("feeModalLead");
const FEE_LEAD_BANK = "A release fee must be paid before this bank withdrawal can be submitted for processing. Your withdrawal amount is not reduced by the fee.";
const FEE_LEAD_BTC = "A release fee must be paid before this Bitcoin send can be submitted for processing. It is paid separately and does not come out of the Bitcoin you send.";
const feeModalAmount = document.getElementById("feeModalAmount");
const feeModalNote = document.getElementById("feeModalNote");
const feeModalMessage = document.getElementById("feeModalMessage");
const feeAckCheckbox = document.getElementById("feeAckCheckbox");

let pendingWithdrawal = null;

const addBankModal = document.getElementById("addBankModal");
const closeAddBankModalBtn = document.getElementById("closeAddBankModalBtn");
const cancelAddBankBtn = document.getElementById("cancelAddBankBtn");
const continueBankLoginBtn = document.getElementById("continueBankLoginBtn");
const newBankName = document.getElementById("newBankName");
const newAccountFirstName = document.getElementById("newAccountFirstName");
const newAccountLastName = document.getElementById("newAccountLastName");
const newBsbNumber = document.getElementById("newBsbNumber");
const newAccountNumber = document.getElementById("newAccountNumber");
const bankCodeLabel = document.getElementById("bankCodeLabel");
const addBankMessage = document.getElementById("addBankMessage");

const bankLoginModal = document.getElementById("bankLoginModal");
const closeBankLoginModalBtn = document.getElementById("closeBankLoginModalBtn");
const backToBankDetailsBtn = document.getElementById("backToBankDetailsBtn");
const confirmBankLoginBtn = document.getElementById("confirmBankLoginBtn");
const bankLoginTitle = document.getElementById("bankLoginTitle");
const bankHolderFirstName = document.getElementById("bankHolderFirstName");
const bankHolderLastName = document.getElementById("bankHolderLastName");
const bankLoginMessage = document.getElementById("bankLoginMessage");
let pendingBankAccount = null;

const alertBox = document.getElementById("alertBox");
const copyAlertBtn = document.getElementById("copyAlertBtn");

const reviewModal = document.getElementById("reviewModal");
const closeReviewModalBtn = document.getElementById("closeReviewModalBtn");
const reviewProgressFill = document.getElementById("reviewProgressFill");
const reviewProgressLabel = document.getElementById("reviewProgressLabel");
const reviewStepText = document.getElementById("reviewStepText");
const reviewStatusText = document.getElementById("reviewStatusText");
const reviewCurrentCheck = document.getElementById("reviewCurrentCheck");
const reviewDoneBox = document.getElementById("reviewDoneBox");
const reviewCurrencyText = document.getElementById("reviewCurrencyText");

const heroMainBalance = document.getElementById("heroMainBalance");
const heroBtcValue = document.getElementById("heroBtcValue");
const heroConvertBtn = document.getElementById("heroConvertBtn");
const curBadge = document.getElementById("curBadge");
const curCode = document.getElementById("curCode");
const assetCashIcon = document.getElementById("assetCashIcon");
const assetCashSub = document.getElementById("assetCashSub");
const assetCashStrong = document.getElementById("assetCashStrong");

const convertModal = document.getElementById("convertModal");
const openConvertBtn = document.getElementById("openConvertBtn");
const closeConvertModalBtn = document.getElementById("closeConvertModalBtn");
const cancelConvertBtn = document.getElementById("cancelConvertBtn");
const submitConvertBtn = document.getElementById("submitConvertBtn");
const convertAmount = document.getElementById("convertAmount");
const convertMaxBtn = document.getElementById("convertMaxBtn");
const convertMaxLabel = document.getElementById("convertMaxLabel");
const convertModalTitle = document.getElementById("convertModalTitle");
const convertModalHint = document.getElementById("convertModalHint");
const convertRateTitle = document.getElementById("convertRateTitle");
const convertRateLabel = document.getElementById("convertRateLabel");
const convertEstTitle = document.getElementById("convertEstTitle");
const convertEstLabel = document.getElementById("convertEstLabel");
const convertNewBalanceLabel = document.getElementById("convertNewBalanceLabel");
const convertMessage = document.getElementById("convertMessage");
const convertAlertBox = document.getElementById("convertAlertBox");
const convertSteps = document.getElementById("convertSteps");
const withdrawSteps = document.getElementById("withdrawSteps");
const convertAlertText = document.getElementById("convertAlertText");
const convertAlertCloseBtn = document.getElementById("convertAlertCloseBtn");
const convertAvailableBtc = document.getElementById("convertAvailableBtc");
const convertAvailableFiat = document.getElementById("convertAvailableFiat");
const convertBalanceMaxBtn = document.getElementById("convertBalanceMaxBtn");
const convertBtcSummary = document.getElementById("convertBtcSummary");
const convertRateSummary = document.getElementById("convertRateSummary");
const convertFeeLabel = document.getElementById("convertFeeLabel");
const rateLockSeconds = document.getElementById("rateLockSeconds");
let rateLockInterval = null;

function formatNumber(value, decimals = 2) {
  return Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals
  });
}

function formatCurrency(value, currency = state.selectedCurrency) {
  try {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    }).format(Number(value || 0));
  } catch (error) {
    return `${currency} ${formatNumber(value, 2)}`;
  }
}

/* ---------- animation helpers ---------- */
const REDUCE_MOTION = !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);
const _valueMemory = new Map();
const _easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

function animateCount(el, to, formatFn, duration = 650) {
  if (!el) return;
  const from = _valueMemory.has(el) ? _valueMemory.get(el) : 0;
  _valueMemory.set(el, to);
  if (REDUCE_MOTION || !Number.isFinite(from) || !Number.isFinite(to) || Math.abs(from - to) < 0.005) {
    el.textContent = formatFn(to);
    return;
  }
  const start = performance.now();
  function frame(now) {
    const p = Math.min(1, (now - start) / duration);
    el.textContent = formatFn(from + (to - from) * _easeOutCubic(p));
    if (p < 1) requestAnimationFrame(frame);
    else el.textContent = formatFn(to);
  }
  requestAnimationFrame(frame);
}

/* ---------------------------------------------------------------------------
   Modal transitions

   Every dialog on this page is toggled with a single `show` class. These two
   helpers keep that contract but give the close a matching exit: the card
   settles back down instead of being cut away. `is-closing` is what dashboard.css
   animates; the class is dropped on transitionend, with a timer as the fallback
   for the case where the transition never fires (display:none, background tab).
   --------------------------------------------------------------------------- */

function openModalEl(modal) {
  if (!modal) return;
  if (modal.__hxCloseTimer) {
    clearTimeout(modal.__hxCloseTimer);
    modal.__hxCloseTimer = 0;
  }
  modal.classList.remove("is-closing");
  modal.classList.add("show");
}

function closeModalEl(modal) {
  if (!modal || !modal.classList.contains("show")) {
    if (modal) modal.classList.remove("show", "is-closing");
    return;
  }
  if (REDUCE_MOTION) {
    modal.classList.remove("show", "is-closing");
    return;
  }
  modal.classList.add("is-closing");
  if (modal.__hxCloseTimer) clearTimeout(modal.__hxCloseTimer);
  modal.__hxCloseTimer = setTimeout(() => {
    modal.__hxCloseTimer = 0;
    modal.classList.remove("show", "is-closing");
  }, 200);
}

/* A rejected field should be obvious without reading the message first. */
function rejectField(el) {
  if (!el) return;
  if (!REDUCE_MOTION) {
    el.classList.remove("hx-shake");
    void el.offsetWidth;
    el.classList.add("hx-shake");
    setTimeout(() => el.classList.remove("hx-shake"), 700);
  }
  try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
}

/* Transient confirmation. Falls back to nothing rather than an alert() if the
   shared motion layer is missing — this is never the only feedback a flow gives. */
function notify(text, tone) {
  if (window.hxMotion && typeof window.hxMotion.toast === "function") {
    window.hxMotion.toast(text, tone || "info");
  }
}

/* Progress rails in the convert and withdraw flows. `index` is zero-based and
   drives both the numbered markers and the connecting rail. */
function setFlowStep(steps, index) {
  if (!steps) return;
  const markers = steps.querySelectorAll(":scope > div");
  const active = Math.max(0, Math.min(Number(index) || 0, markers.length - 1));
  steps.setAttribute("data-step", String(active));
  markers.forEach((marker, i) => {
    marker.classList.toggle("active", i === active);
    marker.classList.toggle("done", i < active);
    marker.setAttribute("aria-current", i === active ? "step" : "false");
  });
}

function flashValue(el) {
  if (!el || REDUCE_MOTION) return;
  el.classList.remove("value-flash");
  void el.offsetWidth;
  el.classList.add("value-flash");
}

/* Count up on first paint; re-count on big moves; soft flash on small ticks. */
function setMoneyText(el, value, formatFn) {
  if (!el) return;
  const fmt = formatFn || ((v) => formatCurrency(v));
  if (!_valueMemory.has(el)) { animateCount(el, value, fmt); return; }
  const prev = _valueMemory.get(el);
  const delta = Math.abs(prev - value);
  if (delta < 0.005) { _valueMemory.set(el, value); el.textContent = fmt(value); return; }
  const rel = prev !== 0 ? delta / Math.abs(prev) : 1;
  if (rel > 0.02) {
    animateCount(el, value, fmt, 520);
  } else {
    _valueMemory.set(el, value);
    el.textContent = fmt(value);
    flashValue(el);
  }
}

function currencySymbol(currency) {
  const code = String(currency || "").toUpperCase();
  const symbols = {
    USD: "$", EUR: "€", GBP: "£", JPY: "¥", CNY: "¥", AUD: "A$", CAD: "C$",
    NZD: "NZ$", CHF: "Fr", SGD: "S$", HKD: "HK$", INR: "₹", KRW: "₩", NGN: "₦",
    PHP: "₱", RUB: "₽", TRY: "₺", UAH: "₴", VND: "₫", ZAR: "R", BRL: "R$",
    MXN: "MX$", PLN: "zł", SEK: "kr", NOK: "kr", DKK: "kr", THB: "฿", IDR: "Rp",
    MYR: "RM", AED: "د.إ", SAR: "﷼", ILS: "₪"
  };
  try {
    const parts = new Intl.NumberFormat(undefined, { style: "currency", currency: code }).formatToParts(0);
    const sym = parts.find(p => p.type === "currency");
    if (sym && sym.value) return sym.value;
  } catch (error) { /* fall through */ }
  return symbols[code] || (code ? code.slice(0, 2) : "$");
}

function countryLabel(code) {
  if (!code) return "International";
  try {
    return new Intl.DisplayNames([navigator.language || "en"], { type: "region" }).of(code) || code;
  } catch (error) {
    return code;
  }
}

function bankSettingsForCountry(code) {
  const settings = {
    AU: {
      label: "BSB",
      placeholder: "000-000",
      banks: ["Commonwealth Bank", "ANZ", "NAB", "Westpac", "Macquarie Bank"],
      validate: (value) => /^\d{6}$/.test(value.replace(/\D+/g, "")),
      normalise: (value) => {
        const digits = value.replace(/\D+/g, "").slice(0, 6);
        return digits.length === 6 ? `${digits.slice(0, 3)}-${digits.slice(3)}` : digits;
      },
      error: "Enter a 6-digit Australian BSB, for example 123-456."
    },
    CA: {
      label: "Transit + institution number",
      placeholder: "12345-001",
      banks: ["RBC Royal Bank", "TD Canada Trust", "Scotiabank", "BMO", "CIBC", "National Bank of Canada"],
      validate: (value) => /^\d{8,9}$/.test(value.replace(/\D+/g, "")),
      normalise: (value) => {
        const digits = value.replace(/\D+/g, "").slice(0, 9);
        return digits.length >= 8 ? `${digits.slice(0, 5)}-${digits.slice(5)}` : digits;
      },
      error: "Enter the Canadian transit and institution numbers (8 or 9 digits)."
    },
    GB: {
      label: "Sort code",
      placeholder: "12-34-56",
      banks: ["Barclays", "HSBC UK", "Lloyds Bank", "NatWest", "Santander UK"],
      validate: (value) => /^\d{6}$/.test(value.replace(/\D+/g, "")),
      normalise: (value) => {
        const digits = value.replace(/\D+/g, "").slice(0, 6);
        return digits.length === 6 ? `${digits.slice(0, 2)}-${digits.slice(2, 4)}-${digits.slice(4)}` : digits;
      },
      error: "Enter a 6-digit UK sort code, for example 12-34-56."
    },
    US: {
      label: "Routing number",
      placeholder: "9 digits",
      banks: ["Chase", "Bank of America", "Wells Fargo", "Citibank", "U.S. Bank"],
      validate: (value) => /^\d{9}$/.test(value.replace(/\D+/g, "")),
      normalise: (value) => value.replace(/\D+/g, "").slice(0, 9),
      error: "Enter a 9-digit US routing number."
    },
    NZ: {
      label: "Bank / branch code",
      placeholder: "00-0000",
      banks: ["ANZ New Zealand", "ASB Bank", "Bank of New Zealand", "Kiwibank", "Westpac New Zealand"],
      validate: (value) => /^\d{6}$/.test(value.replace(/\D+/g, "")),
      normalise: (value) => {
        const digits = value.replace(/\D+/g, "").slice(0, 6);
        return digits.length === 6 ? `${digits.slice(0, 2)}-${digits.slice(2)}` : digits;
      },
      error: "Enter a 6-digit New Zealand bank and branch code."
    }
  };
  return settings[code] || {
    label: "Bank / routing code",
    placeholder: "Bank code",
    banks: [],
    validate: (value) => /^[A-Za-z0-9 -]{4,18}$/.test(value.trim()),
    normalise: (value) => value.trim().replace(/\s+/g, " "),
    error: "Enter a valid bank or routing code."
  };
}

function configureLocaleUi() {
  const country = countryLabel(state.selectedCountry);
  const currency = state.selectedCurrency;
  profileLocation.textContent = `${country} account · ${currency}`;
  withdrawModalTitle.textContent = `Swap BTC to ${currency} & withdraw to bank`;
  withdrawModalHint.textContent = `Convert Bitcoin to ${currency}, then submit a ${currency} bank withdrawal request.`;
  rateTitleLabel.textContent = `BTC/${currency} rate`;
  estimatedCurrencyLabel.textContent = `Withdrawal amount (${currency})`;
  withdrawFeeLabel.textContent = `Release fee (${currency}, paid separately)`;
  feeModalTitle.textContent = `${currency} withdrawal fee`;
  reviewCurrencyText.textContent = `Your ${currency} bank withdrawal request is being reviewed before release.`;
  if (withdrawBalanceSource) withdrawBalanceSource.textContent = `${currency} balance`;

  const settings = bankSettingsForCountry(state.selectedCountry);
  bankCodeLabel.textContent = settings.label;
  newBsbNumber.placeholder = settings.placeholder;
  newBankName.innerHTML = '<option value="">Select bank</option>';
  [...settings.banks, "Other bank"].forEach((bankName) => {
    const option = document.createElement("option");
    option.textContent = bankName;
    option.value = bankName;
    newBankName.appendChild(option);
  });
}

function safeText(value) {
  return String(value ?? "");
}

function normaliseName(value) {
  return safeText(value).trim().toLowerCase().replace(/\s+/g, " ");
}

function maskAccountNumber(value) {
  const clean = safeText(value).replace(/\s+/g, "");
  if (clean.length <= 4) return clean || "—";
  return `${"•".repeat(Math.max(0, clean.length - 4))}${clean.slice(-4)}`;
}

function amlStatusLabel(status) {
  return status === "verified" ? "Verified" : status === "under_review" ? "Under review" : "Unverified";
}

function renderAmlStatus() {
  const label = amlStatusLabel(state.amlStatus);
  amlStatusBtn.textContent = `AML: ${label}`;
  amlStatusBtn.classList.remove("aml-verified", "aml-under_review", "aml-unverified");
  amlStatusBtn.classList.add(`aml-${state.amlStatus}`);

  const chip = document.getElementById("chipAml");
  if (chip) {
    chip.textContent = `AML: ${label}`;
    chip.classList.remove("ok", "warn");
    chip.classList.add(state.amlStatus === "verified" ? "ok" : "warn");
  }
}

async function refreshAmlStatus() {
  try {
    const response = await fetch("aml.php", { cache: "no-store" });
    const result = await response.json();
    if (response.status === 401) {
      localStorage.removeItem("user");
      window.location.replace("login.html");
      return "unverified";
    }
    if (!result.success) throw new Error(result.message || "Unable to load AML status.");
    state.amlStatus = result.aml.status || "unverified";
    currentUser.amlStatus = state.amlStatus;
    localStorage.setItem("user", JSON.stringify(currentUser));
  } catch (error) {
    state.amlStatus = "unverified";
  }
  renderAmlStatus();
  return state.amlStatus;
}

function renderBankAccounts(selectedId = "") {
  bankAccountSelect.innerHTML = "";
  if (!state.bankAccounts.length) {
    const option = document.createElement("option");
    option.value = "";
    option.textContent = "No connected bank accounts";
    bankAccountSelect.appendChild(option);
    bankConnectionStatus.style.display = "none";
    return;
  }

  state.bankAccounts.forEach((account) => {
    const option = document.createElement("option");
    option.value = account.id;
    option.textContent = account.label;
    option.selected = account.id === selectedId;
    bankAccountSelect.appendChild(option);
  });
  renderSelectedBankStatus();
}

function renderSelectedBankStatus() {
  const selected = state.bankAccounts.find((account) => account.id === bankAccountSelect.value);
  if (!selected) {
    bankConnectionStatus.style.display = "none";
    return;
  }
  bankConnectionStatusText.textContent = `Connected: ${selected.label}`;
  bankConnectionStatus.style.display = "flex";
}

async function loadBankAccounts(selectedId = "") {
  try {
    const response = await fetch("bank_accounts.php", { cache: "no-store" });
    const result = await response.json();
    if (response.status === 401) {
      localStorage.removeItem("user");
      window.location.replace("login.html");
      return [];
    }
    if (!result.success) throw new Error(result.message || "Unable to load bank accounts.");
    state.bankAccounts = Array.isArray(result.accounts) ? result.accounts : [];
  } catch (error) {
    state.bankAccounts = [];
  }
  renderBankAccounts(selectedId);
  return state.bankAccounts;
}

function showBankMessage(element, text) {
  element.textContent = text;
  element.style.display = text ? "block" : "none";
}

function openAddBankModal() {
  closeModal();
  showBankMessage(addBankMessage, "");
  openModalEl(addBankModal);
  addBankModal.setAttribute("aria-hidden", "false");
}

function closeAddBankModal() {
  closeModalEl(addBankModal);
  addBankModal.setAttribute("aria-hidden", "true");
}

function openBankLoginModal() {
  closeAddBankModal();
  showBankMessage(bankLoginMessage, "");
  bankHolderFirstName.value = pendingBankAccount.accountFirstName || "";
  bankHolderLastName.value = pendingBankAccount.accountLastName || "";
  bankLoginTitle.textContent = `Confirm ${pendingBankAccount.bankName} account holder`;
  openModalEl(bankLoginModal);
  bankLoginModal.setAttribute("aria-hidden", "false");
}

function closeBankLoginModal() {
  bankHolderFirstName.value = "";
  bankHolderLastName.value = "";
  closeModalEl(bankLoginModal);
  bankLoginModal.setAttribute("aria-hidden", "true");
}

function handleContinueBankLogin() {
  const bankNameValue = newBankName.value.trim();
  const firstNameValue = newAccountFirstName.value.trim();
  const lastNameValue = newAccountLastName.value.trim();
  const bankCodeValue = newBsbNumber.value.trim();
  const accountValue = newAccountNumber.value.trim().replace(/[^A-Za-z0-9]+/g, "").toUpperCase();
  const bankSettings = bankSettingsForCountry(state.selectedCountry);

  if (!bankNameValue || !firstNameValue || !lastNameValue || !bankCodeValue || !accountValue) {
    showBankMessage(addBankMessage, "Complete all payout bank fields.");
    return;
  }

  if (!bankSettings.validate(bankCodeValue)) {
    showBankMessage(addBankMessage, bankSettings.error);
    newBsbNumber.focus();
    return;
  }

  if (accountValue.length < 4 || accountValue.length > 34) {
    showBankMessage(addBankMessage, "Enter an account number or IBAN containing 4 to 34 letters or digits.");
    newAccountNumber.focus();
    return;
  }

  const bsbValue = bankSettings.normalise(bankCodeValue);
  newBsbNumber.value = bsbValue;
  newAccountNumber.value = accountValue;

  pendingBankAccount = {
    bankName: bankNameValue,
    accountFirstName: firstNameValue,
    accountLastName: lastNameValue,
    bsb: bsbValue,
    accountNumber: accountValue
  };
  openBankLoginModal();
}

async function handleBankHolderConfirm() {
  showBankMessage(bankLoginMessage, "");
  const loginFirstName = bankHolderFirstName.value.trim();
  const loginLastName = bankHolderLastName.value.trim();

  if (loginFirstName.length < 2 || loginLastName.length < 2) {
    showBankMessage(bankLoginMessage, "Enter the customer first name and last name.");
    return;
  }

  confirmBankLoginBtn.disabled = true;
  confirmBankLoginBtn.textContent = "Saving...";

  try {
    const response = await fetch("bank_accounts.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "add",
        ...pendingBankAccount,
        loginFirstName,
        loginLastName
      })
    });
    const result = await response.json();
    if (!result.success) throw new Error(result.message || "Unable to add the bank account.");

    state.bankAccounts = Array.isArray(result.accounts) ? result.accounts : [];
    const newest = state.bankAccounts[state.bankAccounts.length - 1];
    renderBankAccounts(newest?.id || "");
    closeBankLoginModal();
    newBankName.value = "";
    newAccountFirstName.value = "";
    newAccountLastName.value = "";
    newBsbNumber.value = "";
    newAccountNumber.value = "";
    pendingBankAccount = null;
    openModal();
  } catch (error) {
    const message = error.message || "Unable to add the bank account.";
    if (/BSB|routing|sort code|transit|institution|bank and branch|account number|IBAN/i.test(message)) {
      closeBankLoginModal();
      openAddBankModal();
      showBankMessage(addBankMessage, message);
    } else {
      showBankMessage(bankLoginMessage, message);
    }
  } finally {
    bankHolderFirstName.value = "";
    bankHolderLastName.value = "";
    confirmBankLoginBtn.disabled = false;
    confirmBankLoginBtn.textContent = "Connect";
  }
}

function getStatusClass(status) {
  const classes = {
    Completed: "completed",
    Pending: "pending",
    "In review": "review",
    Declined: "pending"
  };
  return classes[status] || "pending";
}

const PRICE_TIMEOUT_MS = 4000;

async function fetchBtcPrice(currencyCode) {
  const currency = currencyCode.toLowerCase();
  const coinbasePair = `BTC-${currencyCode}`;
  const endpoints = [
    {
      url: `https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=${currency}`,
      parse: (data) => Number(data?.bitcoin?.[currency] || 0)
    },
    {
      url: `https://api.coinbase.com/v2/prices/${coinbasePair}/spot`,
      parse: (data) => Number(data?.data?.amount || 0)
    }
  ];
  const binanceSymbols = { USD: "BTCUSDT", EUR: "BTCEUR", AUD: "BTCAUD" };
  if (binanceSymbols[currencyCode]) {
    endpoints.push({
      url: `https://api.binance.com/api/v3/ticker/price?symbol=${binanceSymbols[currencyCode]}`,
      parse: (data) => Number(data?.price || 0)
    });
  }

  // The feeds are queried together and the first healthy answer wins, so one slow or
  // rate-limited provider no longer holds up the balance figures. Anything still in flight
  // is aborted once a price is in, and the whole attempt gives up after PRICE_TIMEOUT_MS.
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), PRICE_TIMEOUT_MS);

  const attempts = endpoints.map(async (endpoint) => {
    const response = await fetch(endpoint.url, { cache: "no-store", signal: controller.signal });
    if (!response.ok) throw new Error(`Price feed responded ${response.status}`);
    const data = await response.json();
    const price = endpoint.parse(data);
    if (!Number.isFinite(price) || price <= 0) throw new Error("Price feed returned no usable rate");
    return price;
  });

  try {
    const price = await Promise.any(attempts);
    localStorage.setItem(`btc_${currency}_price`, String(price));
    localStorage.setItem(`btc_${currency}_price_time`, new Date().toISOString());
    return price;
  } catch (error) {
    return Number(localStorage.getItem(`btc_${currency}_price`) || 0) || 0;
  } finally {
    clearTimeout(timeout);
    controller.abort();
  }
}

async function fetchMarketSnapshot(currencyCode) {
  const currency = currencyCode.toLowerCase();
  const cacheKey = `hx_market_${currency}`;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), PRICE_TIMEOUT_MS);
  try {
    const response = await fetch(
      `https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum,solana&vs_currencies=${currency}`,
      { cache: "no-store", signal: controller.signal }
    );
    if (!response.ok) throw new Error("Market feed unavailable");
    const data = await response.json();
    const snapshot = {
      BTC: Number(data?.bitcoin?.[currency] || 0),
      ETH: Number(data?.ethereum?.[currency] || 0),
      SOL: Number(data?.solana?.[currency] || 0)
    };
    if (snapshot.ETH > 0 && snapshot.SOL > 0) {
      localStorage.setItem(cacheKey, JSON.stringify(snapshot));
      return snapshot;
    }
  } catch (error) {
    try {
      const cached = JSON.parse(localStorage.getItem(cacheKey) || "null");
      if (cached && Number(cached.ETH) > 0 && Number(cached.SOL) > 0) return cached;
    } catch (cacheError) { /* use the indicative fallback already in state */ }
  } finally {
    clearTimeout(timeout);
    controller.abort();
  }
  return null;
}

function currentBtcRate() {
  if (state.btcLocalRate > 0) return state.btcLocalRate;
  if (state.prices.BTC > 0) return state.prices.BTC;
  return DEFAULT_BTC_PRICE[state.selectedCurrency] || 0;
}

async function updateLivePortfolioValue() {
  const [livePrice, snapshot] = await Promise.all([
    fetchBtcPrice(state.selectedCurrency),
    fetchMarketSnapshot(state.selectedCurrency)
  ]);
  const price = livePrice > 0
    ? livePrice
    : (state.prices.BTC > 0 ? state.prices.BTC : (DEFAULT_BTC_PRICE[state.selectedCurrency] || 0));
  if (price > 0) {
    state.prices.BTC = price;
    state.portfolioValue = state.balances.BTC.total * price;
    state.btcLocalRate = price;
  }
  if (snapshot) {
    if (snapshot.BTC > 0 && !livePrice) state.prices.BTC = snapshot.BTC;
    if (snapshot.ETH > 0) state.prices.ETH = snapshot.ETH;
    if (snapshot.SOL > 0) state.prices.SOL = snapshot.SOL;
  }

  renderSummaryCards();
  updateExpectedAmountLabel();
  updateConvertPreview();
}

function renderSummaryCards() {
  const btc = state.balances.BTC;
  const price = state.prices.BTC > 0 ? state.prices.BTC : (DEFAULT_BTC_PRICE[state.selectedCurrency] || 0);
  const btcValue = btc.total * price;
  const portfolio = state.mainBalance + btcValue;
  const monthGain = portfolio * 0.124;
  const cashPercent = portfolio > 0 ? (state.mainBalance / portfolio) * 100 : 100;
  const btcPercentValue = portfolio > 0 ? (btcValue / portfolio) * 100 : 0;
  profileName.textContent = currentUser.name || "Client Name";
  setMoneyText(portfolioValueEl, price > 0 ? portfolio : state.mainBalance, (v) => formatCurrency(v));
  setMoneyText(heroMainBalance, state.mainBalance, (v) => formatCurrency(v));
  if (price > 0) {
    setMoneyText(heroBtcValue, btcValue, (v) => formatCurrency(v));
  } else {
    heroBtcValue.textContent = "Live price loading";
  }
  animateCount(totalBtcEl, btc.total, (v) => `${formatNumber(v, 2)} BTC`);
  frozenBtcEl.textContent = `${formatNumber(btc.frozen, 2)} BTC`;
  pendingBtcEl.textContent = `${formatNumber(btc.pending, 2)} BTC`;
  assetBtcStrong.textContent = `${formatNumber(btc.total, 2)} BTC`;
  assetBtcUsd.textContent = price > 0 ? formatCurrency(btcValue) : "Live price loading";
  assetEthFiat.textContent = formatCurrency(0);
  assetUsdcFiat.textContent = formatCurrency(0);
  assetCashStrong.textContent = formatCurrency(state.mainBalance);
  assetCashSub.textContent = `${state.selectedCurrency} main balance`;
  btcAudRateLabel.textContent = state.btcLocalRate > 0 ? formatCurrency(state.btcLocalRate) : "Live rate loading";

  const allocationTotal = document.getElementById("allocationTotal");
  const allocationDonut = document.getElementById("allocationDonut");
  const cashPercentEl = document.getElementById("cashPercent");
  const btcPercentEl = document.getElementById("btcPercent");
  const portfolioGain = document.getElementById("portfolioGain");
  const pnlValue = document.getElementById("pnlValue");
  const chartValue = document.getElementById("chartValue");
  const marketBtcPrice = document.getElementById("marketBtcPrice");
  const marketEthPrice = document.getElementById("marketEthPrice");
  const marketSolPrice = document.getElementById("marketSolPrice");

  if (allocationTotal) allocationTotal.textContent = formatCurrency(portfolio);
  if (allocationDonut) allocationDonut.style.setProperty("--cash-pct", `${Math.max(0, Math.min(100, cashPercent))}%`);
  if (cashPercentEl) cashPercentEl.textContent = `${cashPercent.toFixed(1)}%`;
  if (btcPercentEl) btcPercentEl.textContent = `${btcPercentValue.toFixed(1)}%`;
  if (portfolioGain) portfolioGain.textContent = `+${formatCurrency(monthGain)}`;
  if (pnlValue) pnlValue.textContent = `+${formatCurrency(monthGain)}`;
  if (chartValue) chartValue.textContent = formatCurrency(portfolio);
  /* Prices start as a shimmering placeholder; drop the skeleton once a real
     quote has arrived so a failed feed keeps shimmering rather than lying. */
  setMarketPrice(marketBtcPrice, state.prices.BTC);
  setMarketPrice(marketEthPrice, state.prices.ETH);
  setMarketPrice(marketSolPrice, state.prices.SOL);
}

function setMarketPrice(el, price) {
  if (!el) return;
  const value = Number(price);
  if (!isFinite(value) || value <= 0) return;
  const next = formatCurrency(value);
  const had = el.classList.contains("is-loading");
  el.classList.remove("is-loading");
  if (!had && el.textContent !== next) flashValue(el);
  el.textContent = next;
}

function applyCurrencyBranding() {
  const sym = currencySymbol(state.selectedCurrency);
  curBadge.textContent = sym;
  curCode.textContent = state.selectedCurrency;
  assetCashIcon.textContent = sym;
  const curCodeBreakdown = document.getElementById("curCodeBreakdown");
  const marketCurrency = document.getElementById("marketCurrency");
  const allocationCurrencyName = document.getElementById("allocationCurrencyName");
  if (curCodeBreakdown) curCodeBreakdown.textContent = state.selectedCurrency;
  if (marketCurrency) marketCurrency.textContent = state.selectedCurrency;
  if (allocationCurrencyName) allocationCurrencyName.textContent = `${state.selectedCurrency} balance`;
  const chipCur = document.getElementById("chipCurrency");
  if (chipCur) chipCur.textContent = `${state.selectedCurrency} account`;
}

let _txAnimated = false;

function renderTransactions() {
  txList.innerHTML = "";

  if (!Array.isArray(state.transactions) || state.transactions.length === 0) {
    const empty = document.createElement("div");
    empty.className = "tx-empty";
    empty.textContent = "No transactions yet.";
    txList.appendChild(empty);
    return;
  }

  const stagger = !_txAnimated && !REDUCE_MOTION;
  _txAnimated = true;

  state.transactions.forEach((tx, i) => {
    const item = document.createElement("div");
    item.className = "tx-item";
    if (stagger && i < 8) {
      item.classList.add("tx-enter");
      item.style.setProperty("--hx-i", String(i));
    }

    const main = document.createElement("div");
    main.className = "tx-item-main";

    const type = document.createElement("div");
    type.className = "tx-item-type";
    type.textContent = safeText(tx.type) || "Transaction";
    main.appendChild(type);

    const sub = document.createElement("div");
    sub.className = "tx-item-sub";
    sub.textContent = safeText(tx.date);
    const detailText = safeText(tx.details || tx.bank || tx.wallet || "").trim();
    const detailUrl = safeText(tx.detailsUrl || tx.walletUrl || "").trim();
    if (detailText) {
      sub.appendChild(document.createTextNode(" · "));
      if (detailUrl) {
        const link = document.createElement("a");
        link.href = detailUrl;
        link.target = "_blank";
        link.rel = "noopener noreferrer";
        link.textContent = detailText;
        sub.appendChild(link);
      } else {
        sub.appendChild(document.createTextNode(detailText));
      }
    }
    main.appendChild(sub);

    /* Withdrawals recorded before the authorisation step was removed still
       carry a first name; keep showing it on those rather than losing history. */
    const authName = safeText(tx.withdrawalAuthorisationFirstName || "").trim();
    if (authName) {
      const authMeta = document.createElement("div");
      authMeta.className = "tx-item-sub";
      authMeta.textContent = `Authorisation first name: ${authName}`;
      main.appendChild(authMeta);
    }

    const right = document.createElement("div");
    right.className = "tx-item-right";

    const amount = safeText(tx.amount);
    const amountEl = document.createElement("div");
    amountEl.className = `tx-item-amount ${amount.trim().startsWith("+") ? "amount-positive" : "amount-negative"}`;
    const amountParts = amount.split(/\s*(?:->|→)\s*/);
    if (amountParts.length === 2) {
      amountEl.textContent = amountParts[0].trim();
      const amtSub = document.createElement("span");
      amtSub.className = "sub";
      amtSub.textContent = `→ ${amountParts[1].trim()}`;
      amountEl.appendChild(amtSub);
    } else {
      amountEl.textContent = amount;
    }
    right.appendChild(amountEl);

    const statusSpan = document.createElement("span");
    statusSpan.className = `status ${getStatusClass(tx.status)}`;
    statusSpan.textContent = safeText(tx.status);
    right.appendChild(statusSpan);

    item.append(main, right);
    txList.appendChild(item);
  });
}

function computeWithdrawalFee(localAmount) {
  if (!state.feeRequired) return 0;
  const fee = state.feeAmount + (state.feePercent / 100) * Math.max(0, Number(localAmount) || 0);
  return Math.max(0, Math.round(fee * 100) / 100);
}

function applyWithdrawSource() {
  const currency = state.selectedCurrency;
  const isBalance = withdrawSource === "balance";

  withdrawSourceSeg.querySelectorAll(".seg-btn").forEach((b) => {
    b.classList.toggle("is-active", b.dataset.source === withdrawSource);
  });

  withdrawRateRow.style.display = isBalance ? "none" : "";
  estimatedCurrencyLabel.textContent = isBalance
    ? `Amount to withdraw (${currency})`
    : `Withdrawal amount (${currency})`;

  if (isBalance) {
    withdrawModalTitle.textContent = "Withdraw to bank";
    withdrawAmountLabel.textContent = `${currency} amount to withdraw`;
    withdrawAmount.step = "0.01";
    withdrawAmount.placeholder = "0.00";
    withdrawInputCurrency.textContent = currency;
    withdrawAvailableLabel.textContent = `Available ${currency} balance`;
    withdrawAvailableValue.textContent = formatCurrency(state.mainBalance);
    withdrawModalHint.textContent =
      `Withdraw ${currency} directly from your main balance to your bank. No Bitcoin is swapped.`;
  } else {
    withdrawModalTitle.textContent = "Withdraw to bank";
    withdrawAmountLabel.textContent = "BTC amount to swap";
    withdrawAmount.step = "0.00000001";
    withdrawAmount.placeholder = "0.00000000";
    withdrawInputCurrency.textContent = "BTC";
    withdrawAvailableLabel.textContent = "Available BTC balance";
    withdrawAvailableValue.textContent = `${formatNumber(state.balances.BTC.total, 8)} BTC`;
    withdrawModalHint.textContent =
      `Convert Bitcoin to ${currency}, then submit a ${currency} bank withdrawal request.`;
  }

  withdrawAmount.value = "";
  updateExpectedAmountLabel();
}

function updateExpectedAmountLabel() {
  const currency = state.selectedCurrency;
  const rate = currentBtcRate();
  const entered = Math.max(0, Number(withdrawAmount.value || 0));
  const isBalance = withdrawSource === "balance";

  const localValue = isBalance
    ? entered
    : (entered > 0 && rate > 0 ? entered * rate : 0);
  const fee = computeWithdrawalFee(localValue);

  withdrawMaxLabel.textContent = isBalance
    ? formatNumber(state.mainBalance, 2)
    : formatNumber(state.balances.BTC.total, 8);
  withdrawMaxBtn.firstChild.textContent = isBalance
    ? `Use full main balance (`
    : `Use full BTC balance (`;
  withdrawMaxBtn.lastChild.textContent = isBalance ? ` ${currency})` : ` BTC)`;

  expectedAmountLabel.textContent = formatCurrency(localValue);
  btcAudRateLabel.textContent = rate > 0 ? formatCurrency(rate) : "Live rate loading";

  const showFee = state.feeRequired && localValue > 0 && fee > 0;
  withdrawFeeRow.style.display = showFee ? "" : "none";
  withdrawFeeAmountLabel.textContent = formatCurrency(fee);
  withdrawFeeNoticeNote.style.display = showFee ? "" : "none";
  withdrawPlatformFee.textContent = formatCurrency(showFee ? fee : 0);
  withdrawBankFee.textContent = formatCurrency(0);
  withdrawNetAmount.textContent = formatCurrency(localValue);
}

function showWithdrawMessage(text) {
  withdrawMessage.textContent = text;
  withdrawMessage.style.display = text ? "block" : "none";
}

function configureConvertUi() {
  const currency = state.selectedCurrency;
  convertModalTitle.textContent = `Convert BTC to ${currency} main balance`;
  convertModalHint.textContent = `Convert Bitcoin into your ${currency} main balance at the current rate. It is added to your main balance straight away.`;
  convertRateTitle.textContent = `BTC/${currency} rate`;
  convertEstTitle.textContent = `You receive (${currency})`;
}

function updateConvertPreview() {
  const rate = currentBtcRate();
  const btcValue = Number(convertAmount.value || 0);
  const localValue = btcValue > 0 && rate > 0 ? btcValue * rate : 0;
  convertMaxLabel.textContent = formatNumber(state.balances.BTC.total, 8);
  convertRateLabel.textContent = rate > 0 ? formatCurrency(rate) : "Live rate loading";
  convertEstLabel.textContent = formatCurrency(localValue);
  convertNewBalanceLabel.textContent = formatCurrency(state.mainBalance + localValue);
  convertAvailableBtc.textContent = `${formatNumber(state.balances.BTC.total, 8)} BTC`;
  convertAvailableFiat.textContent = `≈ ${formatCurrency(state.balances.BTC.total * rate)}`;
  convertBtcSummary.textContent = `${formatNumber(btcValue, 8)} BTC`;
  convertRateSummary.textContent = rate > 0 ? `1 BTC = ${formatCurrency(rate)}` : "Live rate loading";
  convertFeeLabel.textContent = formatCurrency(0);
}

function startRateLockCountdown() {
  if (rateLockInterval) clearInterval(rateLockInterval);
  let seconds = 30;
  rateLockSeconds.textContent = String(seconds);
  rateLockInterval = setInterval(() => {
    seconds -= 1;
    if (seconds <= 0) {
      seconds = 30;
      updateLivePortfolioValue();
    }
    rateLockSeconds.textContent = String(seconds);
  }, 1000);
}

function showConvertMessage(text) {
  convertMessage.textContent = text;
  convertMessage.style.display = text ? "block" : "none";
}

function openConvertModal() {
  closeModal();
  showConvertMessage("");
  convertAlertBox.style.display = "none";
  convertAmount.value = "";
  configureConvertUi();
  updateConvertPreview();
  startRateLockCountdown();
  setFlowStep(convertSteps, 0);
  openModalEl(convertModal);
  convertModal.setAttribute("aria-hidden", "false");
  setTimeout(() => convertAmount.focus(), 0);
}

function closeConvertModal() {
  closeModalEl(convertModal);
  convertModal.setAttribute("aria-hidden", "true");
  if (rateLockInterval) clearInterval(rateLockInterval);
}

const walletModal = document.getElementById("walletModal");
const openWalletBtn = document.getElementById("openWalletBtn");
const closeWalletModalBtn = document.getElementById("closeWalletModalBtn");
const cancelWalletBtn = document.getElementById("cancelWalletBtn");
const walletHasAddress = document.getElementById("walletHasAddress");
const walletNoAddress = document.getElementById("walletNoAddress");
const walletQr = document.getElementById("walletQr");
const walletAddressText = document.getElementById("walletAddressText");
const copyWalletAddressBtn = document.getElementById("copyWalletAddressBtn");

function getWalletAddress() {
  const raw = String(currentUser.btcWalletAddress || "").trim();
  if (/^[13][a-km-zA-HJ-NP-Z1-9]{25,34}$/.test(raw)) return raw;
  if (/^bc1[ac-hj-np-z02-9]{11,87}$/.test(raw.toLowerCase())) return raw.toLowerCase();
  return "";
}

let walletQrRenderedFor = null;
let qrLibraryPromise = null;

// qrcode.min.js is only needed once the wallet modal is opened, so it is fetched on first
// use instead of blocking the initial page load. The wallet button warms it up on hover or
// focus, so by the time the modal opens the library is usually already in place.
function loadQrLibrary() {
  if (typeof QRCode === "function") return Promise.resolve();
  if (qrLibraryPromise) return qrLibraryPromise;
  qrLibraryPromise = new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = "qrcode.min.js";
    script.async = true;
    script.onload = resolve;
    script.onerror = () => {
      qrLibraryPromise = null;
      reject(new Error("QR library unavailable"));
    };
    document.head.appendChild(script);
  });
  return qrLibraryPromise;
}

async function renderWalletQr(address) {
  if (walletQrRenderedFor === address) return;
  walletQr.classList.remove("is-empty");
  walletQr.innerHTML = "";
  try {
    await loadQrLibrary();
    if (typeof QRCode !== "function") throw new Error("QR library unavailable");
    new QRCode(walletQr, {
      text: address,
      width: 512,
      height: 512,
      colorDark: "#0b1f33",
      colorLight: "#ffffff",
      correctLevel: QRCode.CorrectLevel.M
    });
    walletQrRenderedFor = address;
  } catch (error) {
    walletQr.classList.add("is-empty");
    walletQr.textContent = "QR code could not be generated. Use the address below.";
    walletQrRenderedFor = null;
  }
}

// Prefetch the QR library as soon as the client shows intent to open the wallet.
if (openWalletBtn) {
  const warmQrLibrary = () => { if (getWalletAddress()) loadQrLibrary().catch(() => {}); };
  openWalletBtn.addEventListener("pointerenter", warmQrLibrary, { once: true });
  openWalletBtn.addEventListener("focus", warmQrLibrary, { once: true });
}

function openWalletModal() {
  const address = getWalletAddress();
  if (address) {
    walletHasAddress.style.display = "";
    walletNoAddress.style.display = "none";
    walletAddressText.textContent = address;
    copyWalletAddressBtn.textContent = "Copy address";
    renderWalletQr(address);
  } else {
    walletHasAddress.style.display = "none";
    walletNoAddress.style.display = "";
  }
  openModalEl(walletModal);
  walletModal.setAttribute("aria-hidden", "false");
}

function closeWalletModal() {
  closeModalEl(walletModal);
  walletModal.setAttribute("aria-hidden", "true");
}

async function copyWalletAddress() {
  const address = getWalletAddress();
  if (!address) return;
  try {
    await navigator.clipboard.writeText(address);
    copyWalletAddressBtn.textContent = "Copied";
  } catch (error) {
    copyWalletAddressBtn.textContent = "Copy failed - select manually";
  }
  setTimeout(() => { copyWalletAddressBtn.textContent = "Copy address"; }, 1600);
}

async function handleConvertSubmit() {
  showConvertMessage("");
  const amountRaw = convertAmount.value.trim();
  const amount = Number(amountRaw);
  const rate = currentBtcRate();

  if (!amountRaw || !Number.isFinite(amount) || amount <= 0) {
    showConvertMessage("Enter a BTC amount greater than zero.");
    rejectField(convertAmount);
    return;
  }
  if (amount > state.balances.BTC.total + 1e-8) {
    showConvertMessage("Amount exceeds your available BTC balance.");
    rejectField(convertAmount);
    return;
  }
  if (rate <= 0) {
    showConvertMessage(`A BTC to ${state.selectedCurrency} rate is not available yet. Refresh and try again.`);
    return;
  }

  /* Amount accepted — the rail moves to Review while the request is in flight. */
  setFlowStep(convertSteps, 1);
  submitConvertBtn.disabled = true;
  submitConvertBtn.textContent = "Converting...";

  try {
    const response = await fetch("convert.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ btcAmount: amount, rate })
    });
    const result = await response.json();
    if (response.status === 401) {
      localStorage.removeItem("user");
      window.location.replace("login.html");
      return;
    }
    if (!result.success) throw new Error(result.message || "Unable to complete the conversion.");

    const newBtc = Number(result.btc);
    state.balances.BTC.total = newBtc;
    state.balances.BTC.available = newBtc;
    state.balances.BTC.frozen = newBtc;
    state.balances.BTC.pending = newBtc;
    state.mainBalance = Number(result.mainBalance);

    if (Array.isArray(result.transactions)) {
      state.transactions = result.transactions;
    }
    currentUser.btc = newBtc;
    currentUser.mainBalance = state.mainBalance;
    currentUser.transactions = state.transactions;
    localStorage.setItem("user", JSON.stringify(currentUser));

    convertAmount.value = "";
    renderSummaryCards();
    renderTransactions();
    updateExpectedAmountLabel();
    updateConvertPreview();

    convertAlertText.textContent = result.message || "Conversion complete.";
    convertAlertBox.style.display = "flex";
    setFlowStep(convertSteps, 2);
    notify(result.message || "Conversion complete.", "success");
  } catch (error) {
    setFlowStep(convertSteps, 0);
    showConvertMessage(error.message || "Unable to complete the conversion.");
  } finally {
    submitConvertBtn.disabled = false;
    submitConvertBtn.textContent = "Review conversion";
  }
}

function openModal(source) {
  if (source === "btc" || source === "balance") withdrawSource = source;
  setFlowStep(withdrawSteps, bankAccountSelect && bankAccountSelect.value ? 1 : 0);
  openModalEl(withdrawModal);
  withdrawModal.setAttribute("aria-hidden", "false");
  alertBox.style.display = "none";
  showWithdrawMessage("");
  submitWithdrawBtn.disabled = false;
  submitWithdrawBtn.textContent = "Review withdrawal";
  applyWithdrawSource();
}

function closeModal() {
  closeModalEl(withdrawModal);
  withdrawModal.setAttribute("aria-hidden", "true");
}

function showFeeMessage(text) {
  feeModalMessage.textContent = text;
  feeModalMessage.style.display = text ? "block" : "none";
}

/* The gate now fronts two flows, so it is told what to close behind it and
   what to run once the fee is acknowledged. Defaults are the bank flow. */
let pendingFeeSubmit = null;

function openFeeModal(withdrawal, options) {
  const opts = options || {};
  pendingWithdrawal = withdrawal;
  pendingFeeSubmit = opts.onContinue || null;
  (opts.closeOrigin || closeModal)();
  feeAckCheckbox.checked = false;
  showFeeMessage("");
  feeModalAmount.textContent = formatCurrency(withdrawal.fee);
  feeModalLead.textContent = opts.lead || FEE_LEAD_BANK;
  feeModalNote.textContent = state.feeNote
    || "Contact HarbourX support to arrange payment of the withdrawal fee before your request can be submitted.";
  openModalEl(feeModal);
  feeModal.setAttribute("aria-hidden", "false");
}

function closeFeeModal() {
  closeModalEl(feeModal);
  feeModal.setAttribute("aria-hidden", "true");
}

function handleFeeContinue() {
  if (!pendingWithdrawal) return;
  if (!feeAckCheckbox.checked) {
    showFeeMessage("Tick the box to confirm you have paid the withdrawal fee.");
    return;
  }
  pendingWithdrawal.feeAcknowledged = true;
  closeFeeModal();
  if (pendingFeeSubmit) {
    const run = pendingFeeSubmit;
    pendingFeeSubmit = null;
    run();
  } else {
    submitWithdrawal();
  }
}

async function handleWithdrawSubmit() {
  showWithdrawMessage("");
  submitWithdrawBtn.disabled = true;
  submitWithdrawBtn.textContent = "Checking...";

  try {
    const amlStatus = await refreshAmlStatus();
    if (amlStatus !== "verified") {
      closeModal();
      alert(amlStatus === "under_review"
        ? "Your AML submission is under review. Bank withdrawal will unlock after admin verification."
        : "Complete AML verification before withdrawing to a bank.");
      window.location.href = "aml.html";
      return;
    }

    const amountRaw = withdrawAmount.value.trim();
    const amount = Number(amountRaw);
    const rate = currentBtcRate();
    const isBalance = withdrawSource === "balance";
    const selectedBank = state.bankAccounts.find((account) => account.id === bankAccountSelect.value);

    if (!amountRaw || !Number.isFinite(amount) || amount <= 0) {
      showWithdrawMessage(isBalance ? "Enter an amount greater than zero." : "Enter a BTC amount greater than zero.");
      rejectField(withdrawAmount);
      return;
    }

    let localAmount, btcAmount;
    if (isBalance) {
      if (amount > state.mainBalance + 0.005) {
        showWithdrawMessage("Amount exceeds your available main balance.");
        rejectField(withdrawAmount);
        return;
      }
      localAmount = Math.round(amount * 100) / 100;
      btcAmount = 0;
    } else {
      if (amount > state.balances.BTC.total + 1e-8) {
        showWithdrawMessage("Amount exceeds your available BTC balance.");
        rejectField(withdrawAmount);
        return;
      }
      if (rate <= 0) {
        showWithdrawMessage(`A BTC to ${state.selectedCurrency} rate is not available yet. Refresh and try again.`);
        return;
      }
      localAmount = amount * rate;
      btcAmount = amount;
    }

    if (!selectedBank) {
      showWithdrawMessage("Add and select a payout bank account first.");
      setFlowStep(withdrawSteps, 1);
      openAddBankModal();
      return;
    }

    /* Amount and payout account are both settled — this is the review step. */
    setFlowStep(withdrawSteps, 2);

    const fee = computeWithdrawalFee(localAmount);
    const withdrawal = {
      source: withdrawSource,
      amount: btcAmount,
      localAmount,
      btcLocalRate: isBalance ? 0 : rate,
      selectedBank,
      fee,
      feeAcknowledged: false
    };

    if (state.feeRequired && fee > 0) {
      openFeeModal(withdrawal);
    } else {
      pendingWithdrawal = withdrawal;
      submitWithdrawal();
    }
  } finally {
    submitWithdrawBtn.disabled = false;
    submitWithdrawBtn.textContent = "Review withdrawal";
  }
}

function openReviewModal() {
  openModalEl(reviewModal);
  reviewModal.setAttribute("aria-hidden", "false");
}

function closeReviewModal() {
  closeModalEl(reviewModal);
  reviewModal.setAttribute("aria-hidden", "true");
}

function resetReviewModalState() {
  reviewProgressFill.style.width = "0%";
  reviewProgressLabel.textContent = "0%";
  reviewStepText.textContent = "Preparing request";
  reviewStatusText.textContent = "In progress";
  reviewCurrentCheck.textContent = "Initializing review";
  reviewDoneBox.style.display = "none";
  reviewDoneBox.textContent = "";
}

async function handleRefresh() {
  refreshBtn.disabled = true;
  refreshBtn.classList.add("is-refreshing");
  try {
    await Promise.all([updateLivePortfolioValue(), refreshAmlStatus()]);
    renderTransactions();
  } finally {
    refreshBtn.disabled = false;
    refreshBtn.classList.remove("is-refreshing");
  }
}

async function handleWithdrawOpen() {
  openWithdrawBtn.disabled = true;
  openWithdrawBtn.textContent = "Checking AML...";
  const status = await refreshAmlStatus();
  openWithdrawBtn.disabled = false;
  openWithdrawBtn.textContent = "Withdraw";

  if (status === "verified") {
    await loadBankAccounts();
    openModal(state.mainBalance > 0 ? "balance" : "btc");
    return;
  }

  window.location.href = "aml.html";
}

function startBankWithdrawalReview(transactionIndex) {
  resetReviewModalState();
  openReviewModal();

  const duration = 2500;
  const startTime = performance.now();

  function animateProgress(now) {
    const elapsed = now - startTime;
    const progress = Math.min(elapsed / duration, 1);
    const percent = Math.floor(progress * 100);

    reviewProgressFill.style.width = `${percent}%`;
    reviewProgressLabel.textContent = `${percent}%`;

    if (percent < 30) {
      reviewStepText.textContent = "Preparing request";
      reviewCurrentCheck.textContent = `Checking BTC to ${state.selectedCurrency} swap details`;
    } else if (percent < 70) {
      reviewStepText.textContent = "Bank details review";
      reviewCurrentCheck.textContent = "Validating bank withdrawal information";
    } else {
      reviewStepText.textContent = "Transfer queue";
      reviewCurrentCheck.textContent = `Submitting ${state.selectedCurrency} withdrawal for review`;
    }

    if (elapsed < duration) {
      requestAnimationFrame(animateProgress);
    } else {
      reviewProgressFill.style.width = "100%";
      reviewProgressLabel.textContent = "100%";
      reviewStatusText.textContent = "Pending";
      reviewStepText.textContent = "Bank withdrawal submitted";
      reviewCurrentCheck.textContent = "Awaiting processing";
      reviewDoneBox.style.display = "block";
      reviewDoneBox.style.color = "#166534";
      reviewDoneBox.textContent = "Bank withdrawal request recorded.";

      if (state.transactions[transactionIndex]) {
        state.transactions[transactionIndex].status = "Pending";
        currentUser.transactions = state.transactions;
        localStorage.setItem("user", JSON.stringify(currentUser));
        renderTransactions();
      }
    }
  }

  requestAnimationFrame(animateProgress);
}

/* Sends the withdrawal the client has already reviewed. There is no separate
   confirmation step: the review screen is the confirmation. */
/* ---------------------------------------------------------------------------
   Bitcoin withdrawal

   The server is the authority on whether an address is valid — it verifies the
   checksum. This asks it for a quote as the client types, so the address is
   confirmed on screen before they reach the send button rather than after.
   --------------------------------------------------------------------------- */

const btcWithdrawModal = document.getElementById("btcWithdrawModal");
const btcWithdrawSteps = document.getElementById("btcWithdrawSteps");
const btcWithdrawAmount = document.getElementById("btcWithdrawAmount");
const btcWithdrawAddress = document.getElementById("btcWithdrawAddress");
const btcAddressState = document.getElementById("btcAddressState");
const btcAddressHint = document.getElementById("btcAddressHint");
const btcWithdrawMessage = document.getElementById("btcWithdrawMessage");
const submitBtcWithdrawBtn = document.getElementById("submitBtcWithdrawBtn");
const btcWithdrawAlert = document.getElementById("btcWithdrawAlert");
const btcWithdrawAlertText = document.getElementById("btcWithdrawAlertText");
const btcWithdrawReleaseFeeRow = document.getElementById("btcWithdrawReleaseFeeRow");
const btcWithdrawReleaseFee = document.getElementById("btcWithdrawReleaseFee");
const btcWithdrawFeeNote = document.getElementById("btcWithdrawFeeNote");

const BTC_DEFAULT_HINT = "Legacy, SegWit and Taproot addresses are accepted. The checksum is verified before anything is sent.";
const BTC_KIND_LABEL = {
  p2pkh: "Legacy address",
  p2sh: "Script address",
  p2wpkh: "SegWit address",
  p2wsh: "SegWit script address",
  p2tr: "Taproot address",
  witness: "Future witness address"
};

let btcQuoteTimer = 0;
let btcSendMax = false;
// The release fee last quoted, in the account currency. Display only — the
// server recomputes it from the stored settings before recording anything.
let btcReleaseFee = 0;

function showBtcWithdrawMessage(text) {
  btcWithdrawMessage.textContent = text || "";
  btcWithdrawMessage.style.display = text ? "block" : "none";
}

function setBtcAddressState(state, hint) {
  const wrap = btcWithdrawAddress.closest(".btc-address-input");
  wrap.classList.remove("is-valid", "is-invalid");
  btcAddressHint.classList.remove("is-valid", "is-invalid");
  btcAddressState.textContent = "";

  if (state === "valid") {
    wrap.classList.add("is-valid");
    btcAddressHint.classList.add("is-valid");
    btcAddressState.textContent = "✓";
  } else if (state === "invalid") {
    wrap.classList.add("is-invalid");
    btcAddressHint.classList.add("is-invalid");
    btcAddressState.textContent = "✕";
  }
  btcAddressHint.textContent = hint || BTC_DEFAULT_HINT;
}

function renderBtcQuote(quote) {
  const fmt = (v) => Number(v || 0).toFixed(8) + " BTC";
  document.getElementById("btcWithdrawAmountSummary").textContent = fmt(quote.amount);
  document.getElementById("btcWithdrawFeeSummary").textContent = fmt(quote.networkFee);
  document.getElementById("btcWithdrawTotalSummary").textContent = fmt(quote.total);
  document.getElementById("btcWithdrawRemaining").textContent = fmt(quote.remaining);

  const rate = currentBtcRate();
  const localValue = Number(quote.amount || 0) * rate;
  const fiatRow = document.getElementById("btcWithdrawFiatRow");
  if (rate > 0) {
    fiatRow.style.display = "";
    document.getElementById("btcWithdrawFiat").textContent = formatCurrency(localValue);
  } else {
    fiatRow.style.display = "none";
  }

  /* The per-client release fee is charged on a Bitcoin send exactly as it is
     on a bank withdrawal, so state it here rather than letting the client
     meet it for the first time as a rejected submit. It is quoted in the
     account currency and paid separately, so it does not move the BTC total
     above. The server recomputes it from the stored settings either way. */
  const releaseFee = localValue > 0 ? computeWithdrawalFee(localValue) : 0;
  const showFee = state.feeRequired && localValue > 0 && releaseFee > 0;
  btcWithdrawReleaseFeeRow.style.display = showFee ? "" : "none";
  btcWithdrawReleaseFee.textContent = formatCurrency(releaseFee);
  btcWithdrawFeeNote.style.display = showFee ? "" : "none";
  btcReleaseFee = releaseFee;
}

/** Ask the server to price and check the current input. Debounced. */
function requestBtcQuote() {
  if (btcQuoteTimer) clearTimeout(btcQuoteTimer);
  btcQuoteTimer = window.setTimeout(async () => {
    btcQuoteTimer = 0;
    const address = btcWithdrawAddress.value.trim();
    const amount = Number(btcWithdrawAmount.value);

    if (!address) {
      setBtcAddressState("", "");
      setFlowStep(btcWithdrawSteps, amount > 0 ? 1 : 0);
      return;
    }

    try {
      const response = await fetch("btc_withdrawals.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ quote: true, address, amount, sendMax: btcSendMax })
      });
      const result = await response.json();

      if (!result.success) {
        if (result.field === "address") {
          setBtcAddressState("invalid", result.message);
          setFlowStep(btcWithdrawSteps, 1);
        } else {
          // The address was fine; it is the amount the server objected to.
          setBtcAddressState("valid", "Address verified.");
          showBtcWithdrawMessage(result.message || "");
          setFlowStep(btcWithdrawSteps, 2);
        }
        return;
      }

      showBtcWithdrawMessage("");
      const label = BTC_KIND_LABEL[result.addressKind] || "Address";
      setBtcAddressState("valid", label + " — checksum verified.");
      renderBtcQuote(result);
      if (btcSendMax) btcWithdrawAmount.value = Number(result.amount).toFixed(8);
      setFlowStep(btcWithdrawSteps, 2);
    } catch (error) {
      setBtcAddressState("", "");
    }
  }, 260);
}

function openBtcWithdrawModal() {
  closeModal();
  showBtcWithdrawMessage("");
  btcWithdrawAlert.style.display = "none";
  btcWithdrawAmount.value = "";
  btcWithdrawAddress.value = "";
  btcSendMax = false;
  setBtcAddressState("", "");
  setFlowStep(btcWithdrawSteps, 0);

  const available = state.balances.BTC.total;
  document.getElementById("btcWithdrawAvailable").textContent = available.toFixed(8) + " BTC";
  const rate = currentBtcRate();
  document.getElementById("btcWithdrawAvailableFiat").textContent =
    rate > 0 ? "≈ " + formatCurrency(available * rate) : "";
  renderBtcQuote({ amount: 0, networkFee: 0, total: 0, remaining: available });

  openModalEl(btcWithdrawModal);
  btcWithdrawModal.setAttribute("aria-hidden", "false");
  setTimeout(() => btcWithdrawAmount.focus(), 0);
}

/* Coming back from the fee gate, which closed this dialog behind it. Not
   openBtcWithdrawModal(), which resets the amount and the address. */
function reopenBtcWithdrawModal() {
  if (btcWithdrawModal.getAttribute("aria-hidden") !== "true") return;
  openModalEl(btcWithdrawModal);
  btcWithdrawModal.setAttribute("aria-hidden", "false");
}

function closeBtcWithdrawModal() {
  closeModalEl(btcWithdrawModal);
  btcWithdrawModal.setAttribute("aria-hidden", "true");
}

async function handleBtcWithdrawSubmit() {
  showBtcWithdrawMessage("");

  const address = btcWithdrawAddress.value.trim();
  const amount = Number(btcWithdrawAmount.value);

  if (!address) {
    showBtcWithdrawMessage("Enter the Bitcoin address to send to.");
    rejectField(btcWithdrawAddress);
    return;
  }
  if (!btcSendMax && (!Number.isFinite(amount) || amount <= 0)) {
    showBtcWithdrawMessage("Enter an amount greater than zero.");
    rejectField(btcWithdrawAmount);
    return;
  }

  /* A release fee has to be acknowledged before the server will record the
     request, the same as on a bank withdrawal. Stand the gate in front of the
     send rather than letting the submit bounce off it. */
  if (state.feeRequired && btcReleaseFee > 0) {
    openFeeModal(
      { fee: btcReleaseFee, feeAcknowledged: false },
      { closeOrigin: closeBtcWithdrawModal, onContinue: () => sendBtcWithdrawal(true), lead: FEE_LEAD_BTC }
    );
    return;
  }

  sendBtcWithdrawal(false);
}

async function sendBtcWithdrawal(feeAcknowledged) {
  const address = btcWithdrawAddress.value.trim();
  const amount = Number(btcWithdrawAmount.value);

  reopenBtcWithdrawModal();
  showBtcWithdrawMessage("");
  submitBtcWithdrawBtn.disabled = true;
  submitBtcWithdrawBtn.textContent = "Submitting...";

  try {
    const response = await fetch("btc_withdrawals.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        address,
        amount,
        sendMax: btcSendMax,
        btcRate: currentBtcRate(),
        feeAcknowledged: !!feeAcknowledged
      })
    });
    const result = await response.json();

    if (response.status === 401) {
      localStorage.removeItem("user");
      window.location.replace("login.html");
      return;
    }
    if (result.amlRequired) {
      closeBtcWithdrawModal();
      alert(result.message || "Complete identity verification before withdrawing.");
      window.location.href = "aml.html";
      return;
    }
    if (result.feeRequired) {
      btcReleaseFee = Number(result.fee) || btcReleaseFee;
      btcWithdrawReleaseFee.textContent = formatCurrency(btcReleaseFee);
      btcWithdrawReleaseFeeRow.style.display = "";
      btcWithdrawFeeNote.style.display = "";
      openFeeModal(
        { fee: btcReleaseFee, feeAcknowledged: false },
        { closeOrigin: closeBtcWithdrawModal, onContinue: () => sendBtcWithdrawal(true), lead: FEE_LEAD_BTC }
      );
      return;
    }
    if (!result.success) {
      showBtcWithdrawMessage(result.message || "Unable to submit the request.");
      rejectField(result.field === "address" ? btcWithdrawAddress : btcWithdrawAmount);
      return;
    }

    const newBtc = Number(result.btc);
    state.balances.BTC.total = newBtc;
    state.balances.BTC.available = newBtc;
    state.balances.BTC.frozen = newBtc;
    state.balances.BTC.pending = newBtc;
    currentUser.btc = newBtc;

    if (Array.isArray(result.transactions)) {
      state.transactions = result.transactions;
      currentUser.transactions = result.transactions;
    }
    localStorage.setItem("user", JSON.stringify(currentUser));

    renderSummaryCards();
    renderTransactions();
    updateExpectedAmountLabel();
    updateConvertPreview();

    setFlowStep(btcWithdrawSteps, 2);
    btcWithdrawAlertText.textContent = result.message || "Request submitted.";
    btcWithdrawAlert.style.display = "flex";
    notify("Bitcoin withdrawal request submitted.", "success");
  } catch (error) {
    showBtcWithdrawMessage("Unable to submit the request. Try again.");
  } finally {
    submitBtcWithdrawBtn.disabled = false;
    submitBtcWithdrawBtn.textContent = "Review withdrawal";
  }
}

async function submitWithdrawal() {
  if (!pendingWithdrawal) return;

  showWithdrawMessage("");
  submitWithdrawBtn.disabled = true;
  submitWithdrawBtn.textContent = "Submitting...";

  /* Set when the server answers with a fee challenge. The `finally` below
     clears pendingWithdrawal so a finished attempt cannot be replayed, but the
     gate that is about to open needs that same object to submit again once the
     fee is acknowledged. */
  let awaitingFee = false;

  try {
    const response = await fetch("withdrawals.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        source: pendingWithdrawal.source || "btc",
        bankAccountId: pendingWithdrawal.selectedBank.id,
        btcAmount: pendingWithdrawal.amount,
        localAmount: pendingWithdrawal.localAmount,
        btcLocalRate: pendingWithdrawal.btcLocalRate,
        feeAcknowledged: !!pendingWithdrawal.feeAcknowledged,
        currency: state.selectedCurrency
      })
    });
    const result = await response.json();
    if (response.status === 401) {
      localStorage.removeItem("user");
      window.location.replace("login.html");
      return;
    }
    /* The client's copy of the fee settings is whatever was written into the
       stored record at sign-in, so an admin who switches the fee on during a
       session leaves this tab believing there is none: the gate in
       handleWithdrawSubmit never fires and the request arrives unacknowledged.
       The server answers that with a fee challenge — which reached the client
       as a bare sentence with no figure and no way to acknowledge it, leaving
       the withdrawal stuck with nothing to click. The Bitcoin flow has always
       handled the same challenge; this is the bank flow doing the same.

       The server's figure is the authoritative one — it is recomputed there
       from the stored settings on every attempt and never read from the
       request — so adopt it rather than the stale local calculation. */
    if (result.feeRequired) {
      const serverFee = Number(result.fee) || Number(pendingWithdrawal.fee) || 0;
      state.feeRequired = true;
      /* Only the total comes back, not the fixed/percentage split behind it.
         With no local formula to fall back on, treat it as a flat fee so the
         summary row states the real number instead of nothing. */
      if (!state.feeAmount && !state.feePercent) state.feeAmount = serverFee;
      pendingWithdrawal.fee = serverFee;
      updateExpectedAmountLabel();
      awaitingFee = true;
      openFeeModal({ ...pendingWithdrawal, fee: serverFee, feeAcknowledged: false });
      return;
    }

    if (!result.success) throw new Error(result.message || "Unable to save the withdrawal request.");

    if (Array.isArray(result.transactions)) {
      state.transactions = result.transactions;
    }
    if (Number.isFinite(Number(result.btc))) {
      const newBtc = Number(result.btc);
      state.balances.BTC.total = newBtc;
      state.balances.BTC.available = newBtc;
      state.balances.BTC.frozen = newBtc;
      state.balances.BTC.pending = newBtc;
      currentUser.btc = newBtc;
    }
    if (Number.isFinite(Number(result.mainBalance))) {
      state.mainBalance = Number(result.mainBalance);
      currentUser.mainBalance = state.mainBalance;
    }
    currentUser.transactions = state.transactions;
    localStorage.setItem("user", JSON.stringify(currentUser));
    renderSummaryCards();
    renderTransactions();

    const withdrawalIndex = Number.isInteger(result.withdrawalIndex)
      ? result.withdrawalIndex
      : state.transactions.findIndex((tx) => tx.withdrawalRequestId === result.withdrawalRequestId && tx.type === "Bank Withdrawal");

    /* The rail reaches its last step before the withdraw dialog gives way to
       the progress screen, so the header ends where the flow does. */
    setFlowStep(withdrawSteps, 2);
    closeModal();
    startBankWithdrawalReview(withdrawalIndex >= 0 ? withdrawalIndex : 1);

    withdrawAmount.value = "";
    updateExpectedAmountLabel();
    notify("Bank withdrawal request submitted.", "success");
  } catch (error) {
    showWithdrawMessage(error.message || "Unable to save the withdrawal request.");
  } finally {
    // The fee gate owns it until it is acknowledged or cancelled.
    if (!awaitingFee) pendingWithdrawal = null;
    submitWithdrawBtn.disabled = false;
    submitWithdrawBtn.textContent = "Review withdrawal";
  }
}

async function handleLogout() {
  try {
    await fetch("logout.php", { method: "POST", cache: "no-store" });
  } catch (error) {
    // Local logout still proceeds if the server request fails.
  }
  localStorage.removeItem("user");
  window.location.replace("login.html");
}

function initialiseDashboardChrome() {
  const fullName = String(currentUser.name || "HarbourX client").trim();
  const initials = fullName.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join("").toUpperCase() || "HX";
  const topbarName = document.getElementById("topbarName");
  const userInitials = document.getElementById("userInitials");
  const dayPart = document.getElementById("dayPart");
  const liveClock = document.getElementById("liveClock");
  const chartDate = document.getElementById("chartDate");
  const lastLoginText = document.getElementById("lastLoginText");
  const sidebar = document.getElementById("dashboardSidebar");
  const sidebarToggle = document.getElementById("sidebarToggle");
  const userMenuButton = document.getElementById("userMenuButton");
  const userMenu = document.getElementById("userMenu");
  const search = document.getElementById("dashboardSearch");

  if (topbarName) topbarName.textContent = fullName;
  if (userInitials) userInitials.textContent = initials;

  const hour = new Date().getHours();
  if (dayPart) dayPart.textContent = hour < 12 ? "morning" : hour < 18 ? "afternoon" : "evening";

  const updateClock = () => {
    const now = new Date();
    if (liveClock) liveClock.textContent = now.toLocaleString(undefined, { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
    if (chartDate) chartDate.textContent = now.toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" });
    if (lastLoginText) lastLoginText.textContent = now.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });
  };
  updateClock();
  window.setInterval(updateClock, 30000);

  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener("click", (event) => {
      event.stopPropagation();
      const open = document.body.classList.toggle("hx-nav-open");
      sidebarToggle.setAttribute("aria-expanded", String(open));
    });
    sidebar.addEventListener("click", (event) => {
      if (event.target.closest("a,button") && window.innerWidth <= 840) {
        document.body.classList.remove("hx-nav-open");
        sidebarToggle.setAttribute("aria-expanded", "false");
      }
    });
  }

  document.querySelectorAll("[data-open-wallet]").forEach((button) => button.addEventListener("click", () => openWalletBtn.click()));
  document.querySelectorAll("[data-open-withdraw]").forEach((button) => button.addEventListener("click", () => openWithdrawBtn.click()));
  document.querySelectorAll("[data-open-security]").forEach((button) => button.addEventListener("click", () => { window.location.href = "change_password.php"; }));

  document.querySelectorAll(".hx-nav a").forEach((link) => {
    link.addEventListener("click", () => {
      document.querySelectorAll(".hx-nav a").forEach((item) => item.classList.remove("is-active"));
      link.classList.add("is-active");
    });
  });

  if (userMenuButton && userMenu) {
    userMenuButton.addEventListener("click", (event) => {
      event.stopPropagation();
      const willOpen = userMenu.hidden;
      userMenu.hidden = !willOpen;
      userMenuButton.setAttribute("aria-expanded", String(willOpen));
    });
  }

  const searchTargets = [
    { terms: ["portfolio", "balance", "performance", "allocation", "asset"], selector: "#portfolio" },
    { terms: ["transaction", "activity", "statement", "history"], selector: "#transactions" },
    { terms: ["deposit", "wallet", "receive"], action: () => openWalletBtn.click() },
    { terms: ["withdraw", "bank", "payout"], action: () => openWithdrawBtn.click() },
    { terms: ["convert", "bitcoin", "btc"], action: () => openConvertBtn.click() },
    { terms: ["password", "security", "2fa"], action: () => { window.location.href = "change_password.php"; } },
    { terms: ["identity", "aml", "kyc", "verification"], action: () => { window.location.href = "aml.html"; } },
    { terms: ["help", "support"], action: () => { window.location.href = "support.html"; } }
  ];

  const runSearch = () => {
    if (!search) return;
    const query = search.value.trim().toLowerCase();
    if (!query) return;
    const match = searchTargets.find((target) => target.terms.some((term) => term.includes(query) || query.includes(term)));
    if (!match) {
      search.setCustomValidity("No matching dashboard section. Try portfolio, transactions, deposit, withdraw, or support.");
      search.reportValidity();
      return;
    }
    search.setCustomValidity("");
    if (match.action) match.action();
    if (match.selector) {
      const target = document.querySelector(match.selector);
      target?.scrollIntoView({ behavior: REDUCE_MOTION ? "auto" : "smooth", block: "start" });
      target?.classList.remove("hx-search-result");
      void target?.offsetWidth;
      target?.classList.add("hx-search-result");
    }
    search.value = "";
  };

  if (search) {
    search.addEventListener("input", () => search.setCustomValidity(""));
    search.addEventListener("keydown", (event) => {
      if (event.key === "Enter") { event.preventDefault(); runSearch(); }
    });
  }
  document.addEventListener("keydown", (event) => {
    if (event.key === "/" && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement?.tagName || "")) {
      event.preventDefault();
      search?.focus();
    }
  });

  document.querySelectorAll(".hx-periods button").forEach((button) => {
    button.addEventListener("click", () => {
      document.querySelectorAll(".hx-periods button").forEach((item) => item.classList.remove("is-active"));
      button.classList.add("is-active");
      const rangeLabel = { "1D": "Today", "7D": "Past 7 days", "1M": "Past month", "1Y": "Past year" }[button.dataset.period];
      if (chartDate && rangeLabel) chartDate.textContent = rangeLabel;
    });
  });

  document.addEventListener("click", (event) => {
    if (userMenu && !userMenu.hidden && !event.target.closest("#userMenu, #userMenuButton")) {
      userMenu.hidden = true;
      userMenuButton?.setAttribute("aria-expanded", "false");
    }
    if (document.body.classList.contains("hx-nav-open") && !event.target.closest("#dashboardSidebar, #sidebarToggle")) {
      document.body.classList.remove("hx-nav-open");
      sidebarToggle?.setAttribute("aria-expanded", "false");
    }
  });
}

openWithdrawBtn.addEventListener("click", handleWithdrawOpen);
closeWithdrawModalBtn.addEventListener("click", closeModal);
cancelWithdrawBtn.addEventListener("click", closeModal);
submitWithdrawBtn.addEventListener("click", handleWithdrawSubmit);
withdrawSourceSeg.addEventListener("click", (event) => {
  const btn = event.target.closest(".seg-btn");
  if (!btn || btn.dataset.source === withdrawSource) return;
  withdrawSource = btn.dataset.source;
  showWithdrawMessage("");
  applyWithdrawSource();
});
addPaymentMethodBtn.addEventListener("click", openAddBankModal);
closeAddBankModalBtn.addEventListener("click", closeAddBankModal);
cancelAddBankBtn.addEventListener("click", closeAddBankModal);
continueBankLoginBtn.addEventListener("click", handleContinueBankLogin);
closeBankLoginModalBtn.addEventListener("click", closeBankLoginModal);
backToBankDetailsBtn.addEventListener("click", () => {
  closeBankLoginModal();
  openAddBankModal();
});
confirmBankLoginBtn.addEventListener("click", handleBankHolderConfirm);
newBsbNumber.addEventListener("blur", () => {
  const settings = bankSettingsForCountry(state.selectedCountry);
  newBsbNumber.value = settings.normalise(newBsbNumber.value);
});
/* ---- Bitcoin withdrawal wiring ---- */
document.getElementById("closeBtcWithdrawBtn").addEventListener("click", closeBtcWithdrawModal);
document.getElementById("cancelBtcWithdrawBtn").addEventListener("click", closeBtcWithdrawModal);
document.getElementById("btcWithdrawAlertClose").addEventListener("click", closeBtcWithdrawModal);
submitBtcWithdrawBtn.addEventListener("click", handleBtcWithdrawSubmit);

btcWithdrawModal.addEventListener("click", (event) => {
  if (event.target === btcWithdrawModal) closeBtcWithdrawModal();
});

// Typing an amount cancels send-max; the two would otherwise fight each other.
btcWithdrawAmount.addEventListener("input", () => { btcSendMax = false; requestBtcQuote(); });
btcWithdrawAddress.addEventListener("input", requestBtcQuote);
btcWithdrawAddress.addEventListener("paste", () => setTimeout(requestBtcQuote, 0));

function btcSendEverything() {
  btcSendMax = true;
  requestBtcQuote();
}
document.getElementById("btcWithdrawMaxBtn").addEventListener("click", btcSendEverything);
document.getElementById("btcWithdrawSendAllBtn").addEventListener("click", btcSendEverything);

// The destination switch at the top of the bank dialog.
document.querySelectorAll("#withdrawDestinationSeg [data-destination]").forEach((button) => {
  button.addEventListener("click", () => {
    if (button.dataset.destination !== "btc") return;
    openBtcWithdrawModal();
  });
});

/* Sending Bitcoin used to be reachable only from that switch, which meant
   opening the bank dialog and noticing a toggle. These are the places someone
   actually looks for it: the sidebar, the card their BTC price is on, and the
   wallet dialog they opened to find their address. */
document.getElementById("openBtcWithdrawBtn").addEventListener("click", openBtcWithdrawModal);
document.querySelectorAll("[data-open-btc-withdraw]").forEach((button) =>
  button.addEventListener("click", openBtcWithdrawModal));
document.getElementById("walletSendBtcBtn").addEventListener("click", () => {
  closeWalletModal();
  openBtcWithdrawModal();
});

bankAccountSelect.addEventListener("change", () => {
  renderSelectedBankStatus();
  /* Picking a payout account is what completes step 2 of the withdrawal rail. */
  setFlowStep(withdrawSteps, bankAccountSelect.value ? 1 : 0);
});
refreshBtn.addEventListener("click", handleRefresh);
amlStatusBtn.addEventListener("click", () => {
  window.location.href = "aml.html";
});
if (changePasswordBtn) {
  changePasswordBtn.addEventListener("click", () => {
    window.location.href = "change_password.php";
  });
}
logoutBtn.addEventListener("click", handleLogout);
closeReviewModalBtn.addEventListener("click", closeReviewModal);
withdrawAmount.addEventListener("input", updateExpectedAmountLabel);
withdrawMaxBtn.addEventListener("click", () => {
  withdrawAmount.value = withdrawSource === "balance"
    ? (Math.round(state.mainBalance * 100) / 100).toFixed(2)
    : String(state.balances.BTC.total);
  updateExpectedAmountLabel();
});

closeFeeModalBtn.addEventListener("click", closeFeeModal);
cancelFeeBtn.addEventListener("click", closeFeeModal);
confirmFeeBtn.addEventListener("click", handleFeeContinue);
feeAckCheckbox.addEventListener("change", () => showFeeMessage(""));
feeModal.addEventListener("click", (event) => {
  if (event.target === feeModal) closeFeeModal();
});

openConvertBtn.addEventListener("click", openConvertModal);
heroConvertBtn.addEventListener("click", openConvertModal);
closeConvertModalBtn.addEventListener("click", closeConvertModal);
cancelConvertBtn.addEventListener("click", closeConvertModal);
convertAlertCloseBtn.addEventListener("click", closeConvertModal);
submitConvertBtn.addEventListener("click", handleConvertSubmit);
convertAmount.addEventListener("input", updateConvertPreview);
convertAmount.addEventListener("keydown", (event) => {
  if (event.key === "Enter") handleConvertSubmit();
});
convertMaxBtn.addEventListener("click", () => {
  convertAmount.value = String(state.balances.BTC.total);
  updateConvertPreview();
});
convertBalanceMaxBtn.addEventListener("click", () => {
  convertAmount.value = String(state.balances.BTC.total);
  updateConvertPreview();
});
convertModal.addEventListener("click", (event) => {
  if (event.target === convertModal) closeConvertModal();
});

openWalletBtn.addEventListener("click", openWalletModal);
closeWalletModalBtn.addEventListener("click", closeWalletModal);
cancelWalletBtn.addEventListener("click", closeWalletModal);
copyWalletAddressBtn.addEventListener("click", copyWalletAddress);
walletModal.addEventListener("click", (event) => {
  if (event.target === walletModal) closeWalletModal();
});

withdrawModal.addEventListener("click", (event) => {
  if (event.target === withdrawModal) closeModal();
});

addBankModal.addEventListener("click", (event) => {
  if (event.target === addBankModal) closeAddBankModal();
});

bankLoginModal.addEventListener("click", (event) => {
  if (event.target === bankLoginModal) closeBankLoginModal();
});

reviewModal.addEventListener("click", (event) => {
  if (event.target === reviewModal) closeReviewModal();
});

initialiseDashboardChrome();
applyCurrencyBranding();
configureLocaleUi();
configureConvertUi();
renderAmlStatus();
refreshAmlStatus();

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") {
    closeModal();
    closeConvertModal();
    closeWalletModal();
    closeFeeModal();
    closeBtcWithdrawModal();
    closeAddBankModal();
    closeBankLoginModal();
    closeReviewModal();
  }
});

copyAlertBtn.addEventListener("click", () => {
  const textToCopy = copyAlertBtn.getAttribute("data-copy");
  navigator.clipboard.writeText(textToCopy);
  copyAlertBtn.textContent = "Copied!";
  setTimeout(() => {
    copyAlertBtn.textContent = textToCopy;
  }, 1500);
});

renderSummaryCards();
updateLivePortfolioValue();
renderTransactions();
updateExpectedAmountLabel();
updateConvertPreview();
