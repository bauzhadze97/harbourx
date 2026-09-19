/* ============================================================================
   HarbourX public site behaviour
   ----------------------------------------------------------------------------
   Shared by the home page and the policy pages, which carry the same header,
   drawer and footer. Three small things, none of which a page depends on: the
   header's solid state once it leaves the banner, the phone menu, and the year
   in the footer. Every page renders and reads correctly with this file blocked.
   ========================================================================== */
(function () {
  "use strict";

  var yr = document.getElementById("year");
  if (yr) yr.textContent = new Date().getFullYear();

  /* ---------------------------------------------- solid header on scroll */
  var head = document.getElementById("siteHead");
  var ticking = false;

  function syncHead() {
    ticking = false;
    /* 40px is far enough that a nudge does not flip it, and short enough that
       the bar is opaque before the banner's headline scrolls under it. */
    head.classList.toggle("is-stuck", window.scrollY > 40);
  }
  function onScroll() {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(syncHead);
  }
  if (head) {
    syncHead();
    window.addEventListener("scroll", onScroll, { passive: true });
  }

  /* ------------------------------------------------------- the phone menu */
  var drawer = document.getElementById("navDrawer");
  var toggle = document.getElementById("navToggle");

  function setMenu(open) {
    if (!drawer || !toggle) return;
    drawer.classList.toggle("is-open", open);
    document.body.classList.toggle("nav-open", open);
    toggle.setAttribute("aria-expanded", String(open));
    if (open) {
      var first = drawer.querySelector("a, button");
      if (first) first.focus();
    } else {
      toggle.focus();
    }
  }

  if (toggle && drawer) {
    toggle.addEventListener("click", function () {
      setMenu(!drawer.classList.contains("is-open"));
    });

    /* The scrim and every link inside close it — a link that only scrolls the
       page would otherwise leave the menu sitting over the destination. */
    drawer.addEventListener("click", function (event) {
      if (event.target.closest("[data-nav-close]")) setMenu(false);
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && drawer.classList.contains("is-open")) setMenu(false);
    });

    /* Reaching the desktop breakpoint hides the toggle, so the menu must not
       be left open behind it. */
    if (window.matchMedia) {
      var wide = window.matchMedia("(min-width: 1081px)");
      var onWide = function (e) { if (e.matches) setMenu(false); };
      if (wide.addEventListener) wide.addEventListener("change", onWide);
      else if (wide.addListener) wide.addListener(onWide);
    }
  }
})();
