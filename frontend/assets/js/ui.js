/* =============================================================
   EduCore — ui.js  v5.5
   Shared UI utilities loaded by every page via:
     <script src="assets/js/ui.js"></script>

   Exports (all global):
     showBanner(containerId, type, message)
     clearBanner(containerId)
     clearFieldErrors(idArray)
     setFieldError(errSpanId, message)
     markFieldInvalid(inputId)
     clearFieldInvalid(inputId)
     setBtnLoading(btn, isLoading, loadingLabel?)
     attachEyeToggle(btnId, inputId, iconId?)
     attachPasswordStrength(inputId, barId?)
     initForgotPasswordModal(opts)
     esc(str)
     initScrollReveal(selector?)
   ============================================================= */

/* ── XSS-safe escape ──────────────────────────────────────────── */
function esc(str) {
  if (str == null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
window.esc = esc; // expose globally so inline scripts can use it

/* ── Banner ───────────────────────────────────────────────────── */
/**
 * showBanner(containerId, type, htmlMessage)
 *   type: 'success' | 'error' | 'info' | 'warning'
 *   message: HTML string (use esc() for user-supplied content)
 */
function showBanner(containerId, type, message) {
  const wrap = document.getElementById(containerId);
  if (!wrap) return;

  // Icon paths per type
  const icons = {
    success : '<polyline points="20 6 9 17 4 12"/>',
    error   : '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
    warning : '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    info    : '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
  };
  const icon = icons[type] || icons.info;

  wrap.innerHTML = `
    <div class="banner ${esc(type)}" role="alert">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        ${icon}
      </svg>
      <span>${message}</span>
    </div>`;
}

/**
 * clearBanner(containerId)
 */
function clearBanner(containerId) {
  const wrap = document.getElementById(containerId);
  if (wrap) wrap.innerHTML = '';
}

/* ── Field error helpers ──────────────────────────────────────── */
/**
 * setFieldError(errSpanId, message)
 * Writes message into the <span class="fe"> error span.
 */
function setFieldError(errSpanId, message) {
  const el = document.getElementById(errSpanId);
  if (el) el.textContent = message;
}

/**
 * markFieldInvalid(inputId)
 * Adds the "err" class to the input element for red-border styling.
 */
function markFieldInvalid(inputId) {
  const el = document.getElementById(inputId);
  if (el) el.classList.add('err');
}

/**
 * clearFieldInvalid(inputId)
 * Removes the "err" class from an input.
 */
function clearFieldInvalid(inputId) {
  const el = document.getElementById(inputId);
  if (el) el.classList.remove('err');
}

/**
 * clearFieldErrors(idArray)
 * Clears text from error spans and removes err class from inputs.
 * Accepts error-span IDs; strips "-err" suffix to find the sibling input.
 *
 * e.g. clearFieldErrors(['email-err', 'pw-err'])
 */
function clearFieldErrors(idArray) {
  (idArray || []).forEach(id => {
    // Clear the error span text
    const span = document.getElementById(id);
    if (span) span.textContent = '';

    // Remove err class from the paired input (strip '-err' suffix)
    const inputId = id.replace(/-err$/, '');
    const input = document.getElementById(inputId);
    if (input) input.classList.remove('err');
  });
}

/* ── Button loading state ─────────────────────────────────────── */
/**
 * setBtnLoading(btn, isLoading, loadingLabel?)
 * Disables the button and shows a spinner while loading.
 * Restores original HTML when isLoading = false.
 */
function setBtnLoading(btn, isLoading, loadingLabel) {
  if (!btn) return;
  if (isLoading) {
    btn.disabled = true;
    btn.dataset.ecOrigHtml = btn.innerHTML;
    const label = loadingLabel || 'Loading...';
    btn.innerHTML = `<div class="spinner" aria-hidden="true"></div><span>${esc(label)}</span>`;
  } else {
    btn.disabled = false;
    if (btn.dataset.ecOrigHtml !== undefined) {
      btn.innerHTML = btn.dataset.ecOrigHtml;
      delete btn.dataset.ecOrigHtml;
    }
  }
}

/* ── Eye (show/hide password) toggle ─────────────────────────── */
/**
 * attachEyeToggle(btnId, inputId, iconId?)
 * Toggles input type between 'password' and 'text'.
 * Optionally swaps SVG path on the icon element.
 */
function attachEyeToggle(btnId, inputId, iconId) {
  const btn   = document.getElementById(btnId);
  const input = document.getElementById(inputId);
  if (!btn || !input) return;

  btn.addEventListener('click', () => {
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');

    // Update icon if provided
    const iconEl = iconId ? document.getElementById(iconId) : btn.querySelector('svg');
    if (iconEl) {
      iconEl.innerHTML = isHidden
        // "eye-off" — show when password is visible
        ? '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
        // "eye" — show when password is hidden
        : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
  });
}

/* ── Password strength meter ──────────────────────────────────── */
/**
 * attachPasswordStrength(inputId, barId?)
 * Listens to keyup on the password input and updates an optional
 * strength bar element (barId). Sets data-strength='weak|fair|strong|very-strong'.
 */
function attachPasswordStrength(inputId, barId) {
  const input = document.getElementById(inputId);
  if (!input) return;

  function score(pw) {
    let s = 0;
    if (pw.length >= 8)  s++;
    if (pw.length >= 12) s++;
    if (/[A-Z]/.test(pw)) s++;
    if (/[0-9]/.test(pw)) s++;
    if (/[^A-Za-z0-9]/.test(pw)) s++;
    return s;
  }

  const levels = ['', 'weak', 'fair', 'strong', 'very-strong', 'very-strong'];
  const labels = ['', 'Weak', 'Fair', 'Strong', 'Very strong', 'Very strong'];

  input.addEventListener('input', () => {
    const s   = score(input.value);
    const bar = barId ? document.getElementById(barId) : null;
    if (bar) {
      bar.dataset.strength = input.value ? levels[s] : '';
      bar.title = input.value ? labels[s] : '';
    }
  });
}

/* ── Forgot-password modal ────────────────────────────────────── */
/**
 * initForgotPasswordModal(opts)
 *
 * opts = {
 *   modalId   : 'fp-modal',
 *   closeId   : 'fp-close',
 *   triggerId : 'forgot-link',
 *   emailId   : 'fp-email',
 *   errId     : 'fp-err',
 *   statusId  : 'fp-banner-wrap',  // OR 'fp-status'
 *   submitId  : 'fp-submit',       // OR 'fp-btn'
 *   onSubmit  : async (email) => { ... }   // returns Promise
 * }
 */
function initForgotPasswordModal(opts) {
  const modal   = document.getElementById(opts.modalId);
  const close   = document.getElementById(opts.closeId);
  const trigger = document.getElementById(opts.triggerId);
  const submit  = document.getElementById(opts.submitId);
  const emailEl = document.getElementById(opts.emailId);
  const errEl   = document.getElementById(opts.errId);
  // Support both 'statusId' and 'fp-status' naming conventions
  const statusId = opts.statusId || opts.statusId;

  if (!modal) return;

  // Open
  if (trigger) {
    trigger.addEventListener('click', e => {
      e.preventDefault();
      modal.style.display = 'flex';
      if (emailEl) emailEl.focus();
      if (errEl)   errEl.textContent = '';
      if (statusId) clearBanner(statusId);
    });
  }

  // Close
  const doClose = () => {
    modal.style.display = 'none';
    if (emailEl) emailEl.value = '';
    if (errEl)   errEl.textContent = '';
    if (statusId) clearBanner(statusId);
  };

  if (close) close.addEventListener('click', doClose);

  // Close on overlay click
  modal.addEventListener('click', e => {
    if (e.target === modal) doClose();
  });

  // Escape key
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && modal.style.display !== 'none') doClose();
  });

  // Submit
  if (submit) {
    submit.addEventListener('click', async () => {
      const email = emailEl ? emailEl.value.trim() : '';
      if (errEl)  errEl.textContent = '';
      if (statusId) clearBanner(statusId);

      if (!email) {
        if (errEl) errEl.textContent = 'Please enter your email address.';
        return;
      }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        if (errEl) errEl.textContent = 'Enter a valid email address.';
        return;
      }

      setBtnLoading(submit, true, 'Sending...');
      try {
        await opts.onSubmit(email);
        // Show success in whichever status container the page provides
        if (statusId) {
          showBanner(statusId, 'success', 'If this email is registered, a reset link has been sent.');
        }
        if (submit) submit.disabled = true; // prevent double-send
      } catch (err) {
        const msg = err?.message || 'Failed to send. Please try again.';
        if (statusId) showBanner(statusId, 'error', esc(msg));
        else if (errEl) errEl.textContent = msg;
        setBtnLoading(submit, false);
      }
    });
  }
}

/* ── Scroll reveal ────────────────────────────────────────────── */
/**
 * initScrollReveal(selector?)
 * Adds class "active" to elements matching selector as they scroll into view.
 * Default selector: '.reveal'
 */
function initScrollReveal(selector) {
  const sel = selector || '.reveal';
  const els = document.querySelectorAll(sel);
  if (!els.length) return;

  const obs = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('active');
        obs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

  els.forEach(el => obs.observe(el));
}

/* ── Live clock ───────────────────────────────────────────────── */
/**
 * startClock(elementId)
 * Updates element text with current time every second.
 * Format: "Mon, 12 Mar · 09:45:32 AM"
 */
function startClock(elementId) {
  const el = document.getElementById(elementId);
  if (!el) return;
  const update = () => {
    const now  = new Date();
    const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const d = days[now.getDay()];
    const m = months[now.getMonth()];
    const dt = now.getDate();
    let h  = now.getHours(), min = now.getMinutes(), s = now.getSeconds();
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    const pad = n => String(n).padStart(2, '0');
    el.textContent = `${d}, ${dt} ${m} · ${pad(h)}:${pad(min)}:${pad(s)} ${ampm}`;
  };
  update();
  setInterval(update, 1000);
}