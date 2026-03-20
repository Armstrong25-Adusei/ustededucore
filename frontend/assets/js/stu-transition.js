/**
 * EduCore Student — Smooth Page Transitions
 * ─────────────────────────────────────────
 * Replaces the incomplete per-page navTo() functions with a single,
 * consistent system. Covers:
 *   • <a href> clicks in the sidebar and content
 *   • onclick="navTo('...')"  (existing pattern)
 *   • onclick="location.href='...'"  (existing pattern)
 *   • window.location.assign()
 *
 * Drop this file beside your stu-*.html files. Each page already has
 * <script src="stu-transition.js"></script> injected at the bottom.
 */

(function () {
  'use strict';

  /* ── Config ────────────────────────────────────────────────── */
  const DURATION   = 220;            // ms — each half (out + in)
  const SLIDE_PX   = 10;             // px — upward shift on page enter
  const INTERNAL   = /^stu-.*\.html/i; // matches our student pages

  /* ── One-time style injection ──────────────────────────────── */
  const STYLE_ID = '__stu_tx_style__';
  if (!document.getElementById(STYLE_ID)) {
    const s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = `
      /* ── Entrance animation on the main content column ── */
      .main, [class*="content"], .cnt {
        animation: __stuFadeIn__ ${DURATION}ms cubic-bezier(.23,1,.32,1) both;
      }
      @keyframes __stuFadeIn__ {
        from { opacity: 0; transform: translateY(${SLIDE_PX}px); }
        to   { opacity: 1; transform: translateY(0); }
      }

      /* ── Full-viewport exit veil ── */
      #__stu_veil__ {
        position: fixed;
        inset: 0;
        z-index: 99999;
        pointer-events: none;
        background: var(--bg, #0f0d0b);
        opacity: 0;
        transition: opacity ${DURATION}ms cubic-bezier(.23,1,.32,1);
        will-change: opacity;
      }
      #__stu_veil__.active {
        opacity: 1;
        pointer-events: all;
      }

      /* ── Indigo progress bar ── */
      #__stu_bar__ {
        position: fixed;
        top: 0; left: 0;
        height: 2px;
        width: 0%;
        z-index: 100000;
        background: linear-gradient(90deg,
          var(--indigo, #5b6bd4),
          var(--in2,    #7b8ef0));
        box-shadow: 0 0 10px rgba(91,107,212,.55);
        opacity: 0;
        border-radius: 0 2px 2px 0;
        transition:
          width   ${DURATION * 2}ms cubic-bezier(.23,1,.32,1),
          opacity ${DURATION * 0.25}ms ease;
      }

      /* ── Shimmer on clicked nav item ── */
      .ni a.__stu_pending__ {
        position: relative;
        overflow: hidden;
      }
      .ni a.__stu_pending__::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg,
          transparent 0%,
          rgba(91,107,212,.18) 50%,
          transparent 100%);
        animation: __stuShimmer__ 0.85s ease infinite;
      }
      @keyframes __stuShimmer__ {
        from { transform: translateX(-100%); }
        to   { transform: translateX(100%); }
      }

      /* ── Override the old page-exit style so it doesn't conflict ── */
      .page-exit {
        animation: none !important;
      }
    `;
    document.head.appendChild(s);
  }

  /* ── DOM elements ──────────────────────────────────────────── */
  let veil = document.getElementById('__stu_veil__');
  if (!veil) {
    veil = document.createElement('div');
    veil.id = '__stu_veil__';
    document.body.appendChild(veil);
  }

  let bar = document.getElementById('__stu_bar__');
  if (!bar) {
    bar = document.createElement('div');
    bar.id = '__stu_bar__';
    document.body.appendChild(bar);
  }

  /* ── Re-trigger entrance animation on every page load ─────── */
  // Handles browsers that cache animation state on back-nav
  document.querySelectorAll('.main, .cnt').forEach(el => {
    el.style.animation = 'none';
    void el.offsetWidth; // force reflow
    el.style.animation  = '';
  });

  /* ── Progress bar helpers ──────────────────────────────────── */
  function barStart() {
    bar.style.transition = `width ${DURATION * 2}ms cubic-bezier(.23,1,.32,1)`;
    bar.style.opacity    = '1';
    bar.style.width      = '0%';
    requestAnimationFrame(() => { bar.style.width = '78%'; });
  }

  function barFinish() {
    bar.style.transition = `width 120ms ease, opacity 180ms ease 120ms`;
    bar.style.width      = '100%';
    setTimeout(() => {
      bar.style.opacity = '0';
      setTimeout(() => { bar.style.width = '0%'; }, 200);
    }, 120);
  }

  /* ── Core navigation ───────────────────────────────────────── */
  let _busy = false;

  function navigate(href) {
    if (_busy) return;
    _busy = true;

    // Shimmer the matching nav link
    const link = document.querySelector(`.ni a[href="${href}"]`);
    if (link) link.classList.add('__stu_pending__');

    barStart();
    veil.classList.add('active');

    setTimeout(() => {
      barFinish();
      window.location.href = href;
    }, DURATION);
  }

  /* ── URL classifier ────────────────────────────────────────── */
  function isInternal(href) {
    if (!href || href.startsWith('javascript') || href.startsWith('#')) return false;
    const clean = href.split('?')[0].split('#')[0].replace(/^\.?\//, '');
    return INTERNAL.test(clean) || INTERNAL.test(href);
  }

  /* ── Expose navTo() globally (replaces all per-page versions) ─ */
  window.navTo = function (url) {
    if (isInternal(url)) navigate(url);
    else window.location.href = url;
  };

  /* ── Intercept <a href> clicks ─────────────────────────────── */
  document.addEventListener('click', function (e) {
    const a = e.target.closest('a[href]');
    if (!a) return;
    const href = a.getAttribute('href');
    if (!isInternal(href)) return;
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    navigate(href);
  }, true);

  /* ── Intercept location.href = '...' assignments ───────────── */
  try {
    const proto = window.Location.prototype;
    const desc  = Object.getOwnPropertyDescriptor(proto, 'href');
    if (desc && desc.set) {
      Object.defineProperty(window.location, 'href', {
        configurable: true,
        enumerable:   true,
        get: desc.get,
        set(val) {
          if (isInternal(val) && !_busy) navigate(val);
          else desc.set.call(window.location, val);
        }
      });
    }
  } catch (_) { /* some browsers restrict this — graceful fallback */ }

  /* ── Intercept location.assign() where browser allows it ──── */
  try {
    const _origAssign = window.location.assign.bind(window.location);
    window.location.assign = function (url) {
      if (isInternal(url) && !_busy) navigate(url);
      else _origAssign(url);
    };
  } catch (_) {
    // Some browsers expose Location.assign as read-only.
    // Navigation still works via anchor interception and navTo().
  }

  /* ── Reset on pageshow (back/forward cache) ────────────────── */
  window.addEventListener('pageshow', function (e) {
    veil.classList.remove('active');
    _busy = false;
    // Re-run entrance animation after bfcache restore
    if (e.persisted) {
      document.querySelectorAll('.main, .cnt').forEach(el => {
        el.style.animation = 'none';
        void el.offsetWidth;
        el.style.animation = '';
      });
    }
  });

})();
