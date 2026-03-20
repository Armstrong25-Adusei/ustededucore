/**
 * EduCore — lec-dashboard.js  v1.0
 * ─────────────────────────────────────────────────────────────────────────────
 * Shared runtime for all lecturer dashboard pages.
 *
 * Responsibilities:
 *   • Auth guard + token expiry handling
 *   • Theme persistence (dark / light)
 *   • Sidebar toggle (mobile)
 *   • Sidebar profile hydration (name, avatar, initials)
 *   • Live-session pip (nav indicator)
 *   • Pending-requests badge (nav-req)
 *   • Toast notification system
 *   • Profile dropdown (dashboard page only — graceful no-op on other pages)
 *   • Page-specific boot functions exposed on window.LecPage
 *
 * Pages supported:
 *   lec-dashboard.html   lec-live.html       lec-courses.html
 *   lec-students.html    lec-attendance.html lec-requests.html
 *   lec-settings.html    lec-analytics.html
 *
 * Usage (replace the inline <script> block in each HTML page):
 *   <script src="assets/js/api.js"></script>
 *   <script src="assets/js/lec-dashboard.js"></script>
 *   <script>
 *     // Page-specific boot, called automatically after shared init
 *     window.LecPage = {
 *       async boot(lecturer) {
 *         // receives hydrated lecturer object
 *         await myPageSpecificLoad();
 *       }
 *     };
 *   </script>
 * ─────────────────────────────────────────────────────────────────────────────
 */

(function () {
  'use strict';

  // ─── 1. AUTH GUARD ────────────────────────────────────────────────────────
  if (!localStorage.getItem('ec_token')) {
    location.href = '../../login.html';
    return;
  }

  // ─── 2. THEME ─────────────────────────────────────────────────────────────
  const _theme = localStorage.getItem('ec_theme') || 'dark';
  document.documentElement.setAttribute('data-theme', _theme);
  _applyThemeIcons(_theme);

  const thBtn = document.getElementById('th-btn');
  if (thBtn) {
    thBtn.onclick = () => {
      const cur = document.documentElement.getAttribute('data-theme');
      const next = cur === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('ec_theme', next);
      _applyThemeIcons(next);
    };
  }

  function _applyThemeIcons(t) {
    const dk = document.getElementById('ico-dk');
    const lt = document.getElementById('ico-lt');
    if (dk) dk.style.display = t === 'dark' ? 'block' : 'none';
    if (lt) lt.style.display = t === 'light' ? 'block' : 'none';
  }

  // ─── 3. SIDEBAR TOGGLE (mobile) ───────────────────────────────────────────
  const SB = document.getElementById('sb');
  const OV = document.getElementById('ov');
  const mbt = document.getElementById('mbt');
  const sbx = document.getElementById('sb-x');

  if (mbt && SB && OV) mbt.onclick = () => { SB.classList.add('on'); OV.classList.add('on'); };
  if (sbx) sbx.onclick = _closeSidebar;
  if (OV)  OV.onclick  = _closeSidebar;

  function _closeSidebar() {
    SB?.classList.remove('on');
    OV?.classList.remove('on');
  }

  // ─── 4. LOGOUT ────────────────────────────────────────────────────────────
  const lgBtn = document.getElementById('lg-btn');
  if (lgBtn) lgBtn.onclick = () => _doLogout();

  // Profile-dropdown logout (dashboard page)
  const ddLogout = document.getElementById('dd-logout');
  if (ddLogout) ddLogout.onclick = () => _doLogout();

  async function _doLogout() {
    try { await API.auth.logout(); } catch (_) { /* token already dead */ }
    API.clearLecturerSession();
    location.href = '../../login.html';
  }

  // ─── 5. PROFILE DROPDOWN (dashboard page) ─────────────────────────────────
  const profBtn = document.getElementById('prof-btn');
  const profDD  = document.getElementById('prof-dd');
  if (profBtn && profDD) {
    profBtn.onclick = (ev) => {
      ev.stopPropagation();
      profBtn.classList.toggle('open');
      profDD.classList.toggle('open');
    };
    document.addEventListener('click', () => {
      profBtn.classList.remove('open');
      profDD.classList.remove('open');
    });
  }

  // ─── 6. TOAST ─────────────────────────────────────────────────────────────
  const SVG_ICONS = {
    ok : '<polyline points="20 6 9 17 4 12"/>',
    er : '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/>',
    in : '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>',
  };

  /**
   * window.toast(message, type = 'ok' | 'er' | 'in')
   * Exposed globally so page-inline scripts can call it.
   */
  window.toast = function toast(msg, type = 'ok') {
    const tw = document.getElementById('tw');
    if (!tw) { console.warn('[EduCore]', msg); return; }
    const d = document.createElement('div');
    d.className = `t ${type}`;
    d.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:13px;height:13px;flex-shrink:0">${SVG_ICONS[type] || SVG_ICONS.in}</svg><span>${_esc(msg)}</span>`;
    tw.appendChild(d);
    d.onclick = () => d.remove();
    setTimeout(() => d.remove(), 4000);
  };

  // ─── 7. UTILITY HELPERS (global) ─────────────────────────────────────────
  /** XSS-safe HTML escape */
  window.esc = _esc;
  function _esc(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /** Initials from a full name */
  window.ini = _ini;
  function _ini(name) {
    if (!name) return '?';
    return name.trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase();
  }

  /** Relative time label */
  window.relTime = _relTime;
  function _relTime(dateStr) {
    if (!dateStr) return '—';
    const days = Math.floor((Date.now() - new Date(dateStr)) / 86400000);
    if (days === 0) return 'Today';
    if (days === 1) return 'Yesterday';
    return days + 'd ago';
  }

  // ─── 8. APPLY LECTURER PROFILE ────────────────────────────────────────────
  /**
   * Populates every element that shows lecturer identity across all pages.
   * Targets (by id): sb-ava, sb-nm, sb-id,
   *                  prof-av, prof-nm (dashboard),
   *                  dd-av, dd-nm, dd-id, dd-dept (dashboard dropdown),
   *                  pc-av, pc-nm, pc-id, pc-dept, pc-inst-tag (dashboard profile card),
   *                  av-big (settings page)
   */
  window.applyLecturerProfile = _applyProfile;
  function _applyProfile(lec) {
    if (!lec) return;
    const name   = lec.full_name || 'Lecturer';
    const initls = _ini(name);
    // Resolve the photo URL — DB stores relative path in profile_photo,
    // legacy code uses `photo`. Build a cache-busted URL when available.
    const photoPath = lec.profile_photo || lec.photo || null;
    let resolvedPath = photoPath;
    if (resolvedPath && !resolvedPath.startsWith('http') && !resolvedPath.startsWith('/')) {
      // Build absolute path from root: /EduCore/backend/uploads/lecturers/...
      resolvedPath = '/EduCore/backend/' + resolvedPath;
    }
    const photoUrl  = resolvedPath
      ? (resolvedPath.startsWith('http') ? resolvedPath : resolvedPath +
         (lec.profile_photo_updated_at
           ? '?v=' + new Date(lec.profile_photo_updated_at).getTime()
           : ''))
      : null;

    const avatarIds = ['sb-ava', 'prof-av', 'dd-av', 'pc-av', 'av-big'];
    avatarIds.forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      // Clear existing content
      el.textContent = '';
      if (photoUrl) {
        const img = new Image();
        img.src = photoUrl;
        img.alt = initls;
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:inherit';
        img.onerror = () => { el.textContent = initls; }; // fallback on broken image
        el.appendChild(img);
      } else {
        el.textContent = initls;
      }
    });

    _setText('sb-nm',        name);
    _setText('sb-id',        lec.staff_id || lec.email || '');
    _setText('prof-nm',      name.split(' ')[0]);
    _setText('dd-nm',        name);
    _setText('dd-id',        lec.staff_id || '');
    _setText('dd-dept',      lec.department_name || '');
    _setText('pc-nm',        name);
    _setText('pc-id',        '#' + (lec.staff_id || lec.lecturer_id || '—'));
    _setText('pc-dept',      lec.department_name || '—');
    _setText('pc-inst-tag',  lec.institution_name || '—');

    // Settings page pre-fills
    _setVal('f-name',    name);
    _setVal('f-email',   lec.email || '');
    _setVal('f-staffid', lec.staff_id || '');
    _setVal('f-phone',   lec.phone || '');
    _setVal('f-title',   lec.title || '');
    _setVal('f-bio',     lec.bio || '');
  }

  function _setText(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  }
  function _setVal(id, val) {
    const el = document.getElementById(id);
    if (el && el.tagName === 'INPUT') el.value = val;
  }

  function _ensureNotificationIcon() {
    const right = document.querySelector('.tb-r');
    if (!right) return;

    let btn = document.getElementById('notif-btn');
    if (!btn) {
      btn = document.createElement('div');
      btn.className = 'ib';
      btn.id = 'notif-btn';
      btn.title = 'Notifications';
      btn.innerHTML =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor">' +
        '<path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>' +
        '<path d="M13.73 21a2 2 0 01-3.46 0"/>' +
        '</svg>' +
        '<span class="nbd" id="notif-badge" style="display:none">0</span>';

      const themeBtn = document.getElementById('th-btn');
      if (themeBtn && themeBtn.parentNode === right) {
        right.insertBefore(btn, themeBtn);
      } else {
        right.insertBefore(btn, right.firstChild);
      }
    }

    btn.onclick = () => {
      if (location.pathname.endsWith('/lec-dashboard.html')) {
        const target = document.getElementById('notif-panel') || document.getElementById('activity');
        if (target) {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          return;
        }
      }
      location.href = 'lec-dashboard.html';
    };
  }

  function _setNotificationBadge(count) {
    const n = Math.max(0, Number(count) || 0);
    const ids = ['notif-badge', 'notif-count'];
    ids.forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      el.textContent = n > 9 ? '9+' : String(n);
      el.style.display = n > 0 ? 'flex' : 'none';
    });
  }

  function _refreshNotificationBadge() {
    if (!API.notifications) return;

    if (typeof API.notifications.unreadCount === 'function') {
      API.notifications.unreadCount()
        .then(res => {
          _setNotificationBadge(Number(res?.count || 0));
        })
        .catch(() => {
          _setNotificationBadge(0);
        });
      return;
    }

    API.notifications.list({ limit: 50 })
      .then(res => {
        const items = Array.isArray(res) ? res : (res.notifications || res.data || []);
        const unread = items.filter(n => Number(n.is_read || 0) === 0).length;
        _setNotificationBadge(unread);
      })
      .catch(() => {
        _setNotificationBadge(0);
      });
  }

  // ─── 9. NAV INDICATORS ────────────────────────────────────────────────────
  function _refreshNavIndicators() {
    _ensureNotificationIcon();

    // Live session pip
    API.sessions.getOpen()
      .then(() => {
        const pip = document.getElementById('nav-live');
        if (pip) pip.style.display = 'inline-block';
      })
      .catch(() => {});

    // Pending requests badge
    API.override.getPending()
      .then(res => {
        const reqs = Array.isArray(res) ? res : (res.requests || res.data || []);
        const pending = reqs.filter(r => !r.status || r.status === 'pending');
        const badge = document.getElementById('nav-req');
        if (badge) {
          const n = pending.length;
          badge.textContent = n > 9 ? '9+' : (n || '0');
          badge.style.display = n > 0 ? 'flex' : 'none';
        }
      })
      .catch(() => {});

    // Messages unread badge
    if (API.messages) {
      API.messages.unreadCounts()
        .then(res => {
          const total = (res.channels || []).reduce((s, c) => s + (c.unread || 0), 0);
          const badge = document.getElementById('nav-msg');
          if (badge) {
            badge.textContent = total > 9 ? '9+' : total;
            badge.style.display = total > 0 ? 'flex' : 'none';
          }
        })
        .catch(() => {});
    }

      // Notifications unread badge (bell icon)
      _refreshNotificationBadge();
  }

  // ─── 10. BOOT SEQUENCE ────────────────────────────────────────────────────
  (async function _boot() {
    // Paint from cache immediately for instant feel
    const cached = API.getStoredLecturer();
    if (cached) _applyProfile(cached);

    // Fetch fresh profile
    let lecturer = cached;
    try {
      const data = await API.auth.me();
      lecturer = data.lecturer || data;
      localStorage.setItem('ec_lecturer', JSON.stringify(lecturer));
      _applyProfile(lecturer);
    } catch (err) {
      if (err.status === 401) {
        API.clearLecturerSession();
        location.href = '../../login.html';
        return;
      }
    }

    // Nav indicators (fire-and-forget)
    _refreshNavIndicators();

    // Hand off to page-specific boot
    if (typeof window.LecPage?.boot === 'function') {
      try { await window.LecPage.boot(lecturer); }
      catch (e) { console.error('[EduCore] Page boot error:', e); }
    }
  })();

  // ─── 11. PERIODIC REFRESH ─────────────────────────────────────────────────
  // Refresh nav indicators every 30s so the live pip and request count stay fresh
  setInterval(_refreshNavIndicators, 30_000);

})();