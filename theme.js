/* HarbourX theme controller — load first in <head> so there is no flash of the wrong theme */
(function () {
  var KEY = "hx-theme";
  var root = document.documentElement;

  function stored() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }
  function systemPref() {
    return (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) ? "dark" : "light";
  }
  function apply(theme) {
    root.setAttribute("data-theme", theme === "dark" ? "dark" : "light");
  }

  var initial = stored();
  apply(initial === "dark" || initial === "light" ? initial : systemPref());

  /* Marks the document as script-enabled before the first paint. hx-motion.css
     only hides reveal-on-scroll content behind this class, so a browser with
     scripting off never ends up with content it cannot reveal. */
  root.classList.add("hx-js");

  window.hxCurrentTheme = function () {
    return root.getAttribute("data-theme") === "dark" ? "dark" : "light";
  };

  window.hxSetTheme = function (theme) {
    var t = theme === "dark" ? "dark" : "light";
    apply(t);
    try { localStorage.setItem(KEY, t); } catch (e) {}
    document.dispatchEvent(new CustomEvent("hx-theme-change", { detail: t }));
    return t;
  };

  window.hxToggleTheme = function () {
    return window.hxSetTheme(window.hxCurrentTheme() === "dark" ? "light" : "dark");
  };

  /* keep tabs / windows in sync */
  window.addEventListener("storage", function (e) {
    if (e.key === KEY && (e.newValue === "dark" || e.newValue === "light")) {
      apply(e.newValue);
      document.dispatchEvent(new CustomEvent("hx-theme-change", { detail: e.newValue }));
    }
  });

  /* wire up any element with [data-theme-toggle] once the DOM is ready */
  function bind() {
    document.querySelectorAll("[data-theme-toggle]").forEach(function (el) {
      if (el.__hxBound) return;
      el.__hxBound = true;
      el.addEventListener("click", function () { window.hxToggleTheme(); });
    });
    syncLabels();
  }
  function syncLabels() {
    var dark = window.hxCurrentTheme() === "dark";
    document.querySelectorAll("[data-theme-toggle]").forEach(function (el) {
      el.setAttribute("aria-pressed", String(dark));
      el.setAttribute("aria-label", dark ? "Switch to light theme" : "Switch to dark theme");
      var lbl = el.querySelector("[data-theme-label]");
      if (lbl) lbl.textContent = dark ? "Light" : "Dark";
    });
  }
  document.addEventListener("hx-theme-change", syncLabels);
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bind);
  } else {
    bind();
  }
})();
