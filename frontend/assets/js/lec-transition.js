/**
 * EduCore — Smooth Page Transitions
 * Intercepts all internal navigation and applies a fade+slide transition
 * so moving between dashboard pages feels instant and professional.
 *
 * Drop this script at the bottom of every lec-*.html page.
 * No build step, no framework — pure vanilla JS.
 */

(function () {
  'use strict';

  /* ── Config ─────────────────────────────────────────────── */
  const DURATION = 240;          // ms for each half (out + in)
  const SLIDE_PX = 10;           // px — subtle upward shift on enter
  const INTERNAL = /^lec-.*\.html/i; // pattern that matches our pages

  /* ── Inject base styles once ────────────────────────────── */
  const STYLE_ID = '__lec_tx_style__';
  if (!document.getElementById(STYLE_ID)) {
    const s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = `
      /* Page-level transition wrapper */
      #__lec_main__ {
        animation: __lecFadeIn__ ${DURATION}ms cubic-bezier(.23,1,.32,1) both;
      }
      @keyframes __lecFadeIn__ {
        from { opacity: 0; transform: translateY(${SLIDE_PX}px); }
        to   { opacity: 1; transform: translateY(0); }
      }

      /* Outgoing overlay — fills the viewport on navigate-away */
      #__lec_veil__ {
        position: fixed;
        inset: 0;
        z-index: 99999;
        pointer-events: none;
        background: var(--bg, #0f0d0b);
        opacity: 0;
        transition: opacity ${DURATION}ms cubic-bezier(.23,1,.32,1);
      }
      #__lec_veil__.out {
        opacity: 1;
        pointer-events: all;
      }

      /* Progress bar along the top */
      #__lec_bar__ {
        position: fixed;
        top: 0; left: 0;
        height: 2px;
        width: 0%;
        z-index: 100000;
        background: linear-gradient(90deg, var(--gold,#c8963c), var(--go2,#e0ae50));
        box-shadow: 0 0 8px rgba(200,150,60,.6);
        transition: width ${DURATION * 0.8}ms cubic-bezier(.23,1,.32,1),
                    opacity ${DURATION * 0.3}ms ease;
        opacity: 0;
        border-radius: 0 2px 2px 0;
      }

      /* Nav item active-during-transition shimmer */
      .ni a.__lec_loading__ {
        position: relative;
        overflow: hidden;
      }
      .ni a.__lec_loading__::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg,
          transparent 0%,
          rgba(200,150,60,.15) 50%,
          transparent 100%);
        animation: __lecShimmer__ 0.9s infinite;
      }
      @keyframes __lecShimmer__ {
        from { transform: translateX(-100%); }
        to   { transform: translateX(100%); }
      }
    `;
    document.head.appendChild(s);
  }

  /* ── Create veil + progress bar elements ────────────────── */
  let veil = document.getElementById('__lec_veil__');
  if (!veil) {
    veil = document.createElement('div');
    veil.id = '__lec_veil__';
    document.body.appendChild(veil);
  }

  let bar = document.getElementById('__lec_bar__');
  if (!bar) {
    bar = document.createElement('div');
    bar.id = '__lec_bar__';
    document.body.appendChild(bar);
  }

  /* ── Wrap the main content for entrance animation ────────── */
  // We animate `.main` (the content column beside the sidebar)
  const mainEl = document.querySelector('.main');
  if (mainEl && !mainEl.id) {
    mainEl.id = '__lec_main__';
  } else if (mainEl) {
    // Re-trigger animation each time the page loads
    mainEl.style.animation = 'none';
    // Force reflow
    void mainEl.offsetWidth;
    mainEl.style.animation = '';
  }

  /* ── Sidebar stays static (no animation — it's persistent) ─ */

  /* ── Show progress bar ───────────────────────────────────── */
  function startBar() {
    bar.style.opacity = '1';
    bar.style.width = '0%';
    // Animate to ~80% quickly, then hold until navigation completes
    requestAnimationFrame(() => {
      bar.style.transition = `width ${DURATION * 2.5}ms cubic-bezier(.23,1,.32,1)`;
      bar.style.width = '80%';
    });
  }

  function finishBar() {
    bar.style.transition = `width ${DURATION * 0.3}ms ease, opacity ${DURATION * 0.5}ms ease ${DURATION * 0.3}ms`;
    bar.style.width = '100%';
    setTimeout(() => {
      bar.style.opacity = '0';
      setTimeout(() => { bar.style.width = '0%'; }, DURATION * 0.5);
    }, DURATION * 0.3);
  }

  /* ── Core navigate function ──────────────────────────────── */
  function navigateTo(href) {
    if (navigateTo._busy) return;
    navigateTo._busy = true;

    // Highlight the clicked nav link with a shimmer while loading
    const clickedLink = document.querySelector(`a[href="${href}"]`);
    if (clickedLink) clickedLink.classList.add('__lec_loading__');

    startBar();

    // Fade out the current page
    veil.classList.add('out');

    setTimeout(() => {
      finishBar();
      window.location.href = href;
    }, DURATION);
  }
  navigateTo._busy = false;

  /* ── Intercept sidebar nav links ─────────────────────────── */
  function isInternal(href) {
    if (!href) return false;
    // Strip leading ./ or /
    const clean = href.replace(/^\.?\//, '');
    return INTERNAL.test(clean) || INTERNAL.test(href);
  }

  document.addEventListener('click', function (e) {
    const a = e.target.closest('a[href]');
    if (!a) return;
    const href = a.getAttribute('href');
    if (!isInternal(href)) return;
    // Let middle-click / ctrl-click open in new tab
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;

    e.preventDefault();
    navigateTo(href);
  }, true);

  /* ── Intercept location.href assignments used in onclick ──── */
  // We patch window.location by wrapping common navigation patterns
  // used in buttons like onclick="location.href='lec-courses.html'"
  const _origAssign = window.location.assign.bind(window.location);
  const _origReplace = window.location.replace.bind(window.location);

  // Monkey-patch location setter (works in most browsers via a
  // descriptor on the Location prototype)
  try {
    const desc = Object.getOwnPropertyDescriptor(window.Location.prototype, 'href');
    if (desc && desc.set) {
      Object.defineProperty(window.location, 'href', {
        set(val) {
          if (isInternal(val) && !navigateTo._busy) {
            navigateTo(val);
          } else {
            desc.set.call(window.location, val);
          }
        },
        get: desc.get
      });
    }
  } catch (_) {
    // Browsers that disallow this — fall back to no-op (links still work normally)
  }

  /* ── Intercept window.location.assign where allowed ─────── */
  try {
    window.location.assign = function (url) {
      if (isInternal(url) && !navigateTo._busy) {
        navigateTo(url);
      } else {
        _origAssign(url);
      }
    };
  } catch (_) {
    // Some browsers expose Location.assign as read-only.
    // Navigation still works via link interception and navigateTo().
  }

  /* ── Handle back/forward (popstate) ─────────────────────── */
  window.addEventListener('popstate', () => {
    if (navigateTo._busy) return;
    navigateTo._busy = true;
    veil.classList.add('out');
    setTimeout(() => {
      navigateTo._busy = false;
      // Browser will handle the actual navigation
    }, DURATION);
  });

  /* ── Announce entrance when page loads ────────────────────── */
  // Ensure the veil starts hidden on every page load
  // (handles back-nav where browser may restore scroll state)
  window.addEventListener('pageshow', () => {
    veil.classList.remove('out');
    navigateTo._busy = false;
  });

})();