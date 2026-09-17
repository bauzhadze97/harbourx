/* ============================================================================
   HarbourX motion controller
   ----------------------------------------------------------------------------
   Drives the effects declared in hx-motion.css. No dependencies, safe to load
   with `defer` on any page, and every entry point is a no-op when the visitor
   asks for reduced motion.

   Markup hooks
   ------------
     [data-reveal]                 ease in when scrolled into view
     [data-reveal-once="false"]    re-play the reveal on every entry
     .hx-stagger                   cascade the children of a container
     [data-ripple]                 ink ripple on press
     .hx-sheen                     pointer-tracked highlight
     [data-count-to]               count a number up on first view
     .hx-draw                      trace an SVG path
     [data-steps]                  progress rail, driven by data-step

   Script API (window.hxMotion)
   ----------------------------
     reveal(scope)        scan a subtree for new [data-reveal] nodes
     count(el, value, o)  animate an element to a new number
     flash(el)            highlight a value that just changed
     shake(el)            reject a field
     toast(text, tone)    transient message, bottom right
     steps(el, index)     move a progress rail
     draw(scope)          trace any .hx-draw paths in a subtree
     busy(el, on)         spinner state on a button
   ========================================================================== */

(function () {
  "use strict";

  var root = document.documentElement;
  var motionQuery = window.matchMedia
    ? window.matchMedia("(prefers-reduced-motion: reduce)")
    : null;

  function reduced() {
    return !!(motionQuery && motionQuery.matches);
  }

  /* theme.js normally adds this before first paint; set it here as well so the
     controller still works on a page that forgot the pre-paint snippet. */
  root.classList.add("hx-js");

  function each(list, fn) {
    Array.prototype.forEach.call(list || [], fn);
  }

  function $all(selector, scope) {
    return (scope || document).querySelectorAll(selector);
  }

  /* ------------------------------------------------------------ reveal ---- */

  var revealObserver = null;

  function ensureObserver() {
    if (revealObserver || !("IntersectionObserver" in window)) return revealObserver;
    revealObserver = new IntersectionObserver(function (entries) {
      each(entries, function (entry) {
        var el = entry.target;
        if (entry.isIntersecting) {
          el.classList.add("is-revealed");
          if (el.getAttribute("data-reveal-once") !== "false") {
            revealObserver.unobserve(el);
          }
        } else if (el.getAttribute("data-reveal-once") === "false") {
          el.classList.remove("is-revealed");
        }
      });
    }, { rootMargin: "0px 0px -8% 0px", threshold: 0.08 });
    return revealObserver;
  }

  function reveal(scope) {
    var nodes = $all("[data-reveal]", scope);
    if (reduced() || !("IntersectionObserver" in window)) {
      each(nodes, function (el) { el.classList.add("is-revealed"); });
      return;
    }
    var observer = ensureObserver();
    each(nodes, function (el) {
      if (el.__hxRevealBound) return;
      el.__hxRevealBound = true;
      observer.observe(el);
    });
  }

  /* Children past the tenth need their stagger index written in: the CSS only
     spells out :nth-child up to 10. */
  function indexStaggers(scope) {
    each($all(".hx-stagger", scope), function (container) {
      var kids = container.children;
      for (var i = 10; i < kids.length; i++) {
        kids[i].style.setProperty("--hx-i", String(i));
      }
    });
  }

  /* ------------------------------------------------------------- counting - */

  function easeOutCubic(t) {
    return 1 - Math.pow(1 - t, 3);
  }

  function defaultFormat(value, decimals) {
    return value.toLocaleString(undefined, {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals
    });
  }

  /**
   * Animate `el` from its current numeric value to `value`.
   * options: { decimals, prefix, suffix, duration, format(value) }
   */
  function count(el, value, options) {
    if (!el) return;
    var opts = options || {};
    var decimals = opts.decimals != null ? Number(opts.decimals) : 2;
    var prefix = opts.prefix || "";
    var suffix = opts.suffix || "";
    var format = typeof opts.format === "function"
      ? opts.format
      : function (v) { return prefix + defaultFormat(v, decimals) + suffix; };

    var to = Number(value) || 0;
    var from = Number(el.__hxCountValue);
    if (!isFinite(from)) from = 0;

    if (el.__hxCountFrame) cancelAnimationFrame(el.__hxCountFrame);
    el.__hxCountValue = to;

    if (reduced() || from === to) {
      el.textContent = format(to);
      return;
    }

    var duration = opts.duration != null ? Number(opts.duration) : 900;
    var start = 0;

    function step(now) {
      if (!start) start = now;
      var t = Math.min(1, (now - start) / duration);
      el.textContent = format(from + (to - from) * easeOutCubic(t));
      if (t < 1) {
        el.__hxCountFrame = requestAnimationFrame(step);
      } else {
        el.__hxCountFrame = 0;
        el.textContent = format(to);
      }
    }
    el.__hxCountFrame = requestAnimationFrame(step);
  }

  /* Declarative counters: <strong data-count-to="286617.60" data-count-prefix="A$"> */

  function runCounter(el) {
    count(el, el.getAttribute("data-count-to"), {
      decimals: el.getAttribute("data-count-decimals"),
      prefix: el.getAttribute("data-count-prefix") || "",
      suffix: el.getAttribute("data-count-suffix") || "",
      duration: el.getAttribute("data-count-duration")
    });
  }

  var counterObserver = null;

  function bindCounters(scope) {
    var nodes = $all("[data-count-to]", scope);
    if (!nodes.length) return;

    if (reduced() || !("IntersectionObserver" in window)) {
      each(nodes, runCounter);
      return;
    }

    if (!counterObserver) {
      counterObserver = new IntersectionObserver(function (entries) {
        each(entries, function (entry) {
          if (!entry.isIntersecting) return;
          runCounter(entry.target);
          counterObserver.unobserve(entry.target);
        });
      }, { threshold: 0.25 });
    }

    each(nodes, function (el) {
      if (el.__hxCountBound) return;
      el.__hxCountBound = true;
      counterObserver.observe(el);
    });
  }

  /* --------------------------------------------------------------- flash -- */

  function replay(el, className) {
    if (!el || reduced()) return;
    el.classList.remove(className);
    /* force a reflow so the animation restarts even on a rapid second call */
    void el.offsetWidth;
    el.classList.add(className);
    window.setTimeout(function () { el.classList.remove(className); }, 900);
  }

  function flash(el) { replay(el, "hx-flash"); }
  function shake(el) { replay(el, "hx-shake"); }

  /* -------------------------------------------------------------- ripple -- */

  function addRipple(event) {
    var host = event.currentTarget;
    if (reduced() || host.disabled) return;

    var rect = host.getBoundingClientRect();
    var size = Math.max(rect.width, rect.height);
    var x = (event.clientX || rect.left + rect.width / 2) - rect.left;
    var y = (event.clientY || rect.top + rect.height / 2) - rect.top;

    var ink = document.createElement("span");
    ink.className = "hx-ripple-ink";
    ink.style.width = ink.style.height = size + "px";
    ink.style.left = (x - size / 2) + "px";
    ink.style.top = (y - size / 2) + "px";

    host.appendChild(ink);
    window.setTimeout(function () {
      if (ink.parentNode) ink.parentNode.removeChild(ink);
    }, 620);
  }

  function bindRipples(scope) {
    each($all("[data-ripple]", scope), function (el) {
      if (el.__hxRippleBound) return;
      el.__hxRippleBound = true;
      el.classList.add("hx-ripple-host");
      el.addEventListener("pointerdown", addRipple);
    });
  }

  /* --------------------------------------------------------------- sheen -- */

  function bindSheen(scope) {
    if (!window.matchMedia || !window.matchMedia("(hover: hover)").matches) return;

    each($all(".hx-sheen", scope), function (el) {
      if (el.__hxSheenBound) return;
      el.__hxSheenBound = true;

      var frame = 0;
      var pending = null;

      el.addEventListener("pointermove", function (event) {
        if (reduced()) return;
        pending = event;
        if (frame) return;
        frame = requestAnimationFrame(function () {
          frame = 0;
          var rect = el.getBoundingClientRect();
          el.style.setProperty("--hx-mx", ((pending.clientX - rect.left) / rect.width * 100) + "%");
          el.style.setProperty("--hx-my", ((pending.clientY - rect.top) / rect.height * 100) + "%");
        });
      });

      el.addEventListener("pointerleave", function () {
        if (frame) { cancelAnimationFrame(frame); frame = 0; }
        el.style.removeProperty("--hx-mx");
        el.style.removeProperty("--hx-my");
      });
    });
  }

  /* ---------------------------------------------------------------- draw -- */

  /* Measure each path so the dash animation matches its real length instead of
     a guessed constant. */
  function draw(scope) {
    each($all(".hx-draw", scope), function (path) {
      if (path.__hxDrawBound || typeof path.getTotalLength !== "function") return;
      try {
        var length = Math.ceil(path.getTotalLength());
        /* A card skipped by content-visibility measures as zero; leave it
           unbound so a later scan can try again once it is rendered. */
        if (!length) return;
        path.style.setProperty("--hx-len", length);
        path.__hxDrawBound = true;
      } catch (e) { /* detached or display:none — leave the CSS default */ }
    });
  }

  /* --------------------------------------------------------------- steps -- */

  /**
   * Move a progress rail. `index` is zero-based; the fill spans from the first
   * marker to the active one.
   */
  function steps(container, index) {
    if (!container) return;
    var markers = container.querySelectorAll("[data-step-marker]");
    var total = markers.length;
    var active = Math.max(0, Math.min(Number(index) || 0, total - 1));

    container.style.setProperty(
      "--hx-step-progress",
      total > 1 ? (active / (total - 1)).toFixed(4) : "0"
    );
    container.setAttribute("data-step", String(active));

    each(markers, function (marker, i) {
      marker.classList.toggle("is-active", i === active);
      marker.classList.toggle("is-done", i < active);
      marker.setAttribute("aria-current", i === active ? "step" : "false");
    });
  }

  function bindSteps(scope) {
    each($all("[data-steps]", scope), function (container) {
      if (container.__hxStepsBound) return;
      container.__hxStepsBound = true;
      steps(container, container.getAttribute("data-step") || 0);
    });
  }

  /* ---------------------------------------------------------------- busy -- */

  function busy(el, on) {
    if (!el) return;
    el.classList.toggle("is-busy", on !== false);
    if (on !== false) {
      el.setAttribute("aria-busy", "true");
    } else {
      el.removeAttribute("aria-busy");
    }
  }

  /* --------------------------------------------------------------- toast -- */

  var toastStack = null;

  function toast(text, tone, ms) {
    if (!text) return null;
    if (!toastStack) {
      toastStack = document.createElement("div");
      toastStack.className = "hx-toast-stack";
      toastStack.setAttribute("role", "status");
      toastStack.setAttribute("aria-live", "polite");
      document.body.appendChild(toastStack);
    }

    var node = document.createElement("div");
    node.className = "hx-toast";
    node.setAttribute("data-tone", tone || "info");

    var dot = document.createElement("span");
    dot.className = "hx-toast-dot";
    dot.setAttribute("aria-hidden", "true");

    var label = document.createElement("span");
    label.textContent = text;

    node.appendChild(dot);
    node.appendChild(label);
    toastStack.appendChild(node);

    window.setTimeout(function () {
      node.classList.add("is-leaving");
      window.setTimeout(function () {
        if (node.parentNode) node.parentNode.removeChild(node);
      }, 220);
    }, Number(ms) || 3800);

    return node;
  }

  /* ----------------------------------------------------- scroll progress -- */

  function bindScrollProgress() {
    var bar = document.querySelector(".hx-scroll-progress");
    if (!bar) return;

    var frame = 0;
    function update() {
      frame = 0;
      var doc = document.documentElement;
      var max = doc.scrollHeight - doc.clientHeight;
      bar.style.setProperty("--hx-scroll", max > 0 ? (doc.scrollTop / max).toFixed(4) : "0");
    }
    window.addEventListener("scroll", function () {
      if (frame) return;
      frame = requestAnimationFrame(update);
    }, { passive: true });
    update();
  }

  /* --------------------------------------------------------------- setup -- */

  function scan(scope) {
    indexStaggers(scope);
    reveal(scope);
    bindCounters(scope);
    bindRipples(scope);
    bindSheen(scope);
    bindSteps(scope);
    draw(scope);
  }

  function init() {
    scan(document);
    bindScrollProgress();

    /* Content injected after load (transaction rows, bank lists, flow steps)
       picks up the same behaviour without every caller remembering to ask. */
    if ("MutationObserver" in window) {
      var pending = false;
      new MutationObserver(function (records) {
        if (pending) return;

        /* Text-only changes — a ticking clock, a refreshed price — can never
           introduce a new motion hook, and the dashboard produces one every
           second. Only an added element is worth a rescan. */
        var sawElement = false;
        for (var i = 0; i < records.length && !sawElement; i++) {
          var added = records[i].addedNodes;
          for (var j = 0; j < added.length; j++) {
            if (added[j].nodeType === 1) { sawElement = true; break; }
          }
        }
        if (!sawElement) return;

        pending = true;
        requestAnimationFrame(function () {
          pending = false;
          scan(document);
        });
      }).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (motionQuery && motionQuery.addEventListener) {
    motionQuery.addEventListener("change", function () {
      if (reduced()) {
        each($all("[data-reveal]"), function (el) { el.classList.add("is-revealed"); });
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  window.hxMotion = {
    reveal: reveal,
    scan: scan,
    count: count,
    flash: flash,
    shake: shake,
    toast: toast,
    steps: steps,
    draw: draw,
    busy: busy,
    reduced: reduced
  };
})();
