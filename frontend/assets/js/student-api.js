/* =============================================================
   EduCore — student-api.js  v5.5
   Student-scoped HTTP client.

   Lives at:  students/assets/js/student-api.js
   Used by:   students/dashboard.html  (and any future student pages)

   Exposes two globals:
     StudentAPI   — full namespaced API client
     esc(str)     — XSS-safe HTML escape
     haversineKm  — Haversine distance helper

   Backend routes (all prefixed with student/):
     auth/*  profile  classes  session  checkin
     attendance  override  notifications  geofence
   ============================================================= */

/* ── StudentAPIError ────────────────────────────────────────── */
class StudentAPIError extends Error {
  constructor(message, status = 0) {
    super(message);
    this.name   = 'StudentAPIError';
    this.status = status;
    this.errors = {};
  }
}

/* ── Main client (IIFE) ─────────────────────────────────────── */
const StudentAPI = (() => {

  // ── BASE URL resolution ──────────────────────────────────────
  // student-api.js lives at: <appRoot>/frontend/assets/js/student-api.js
  // or possibly: <appRoot>/assets/js/student-api.js
  // Strategy:
  // 1. Calculate from script location (most reliable)
  // 2. Calculate from current page location and intelligently remove /frontend/
  // 3. Final fallback with correct path
  const BASE = (() => {
    // ── 1. Manual override (highest priority) ────────────────────────────
    if (window.EDUCORE_API_BASE) return window.EDUCORE_API_BASE.replace(/\/?$/, '/');

    // ── 2. Cross-origin detection (Vercel → InfinityFree) ────────────────
    const BACKEND = 'https://ustededucore.rf.gd';
    if (window.location.origin !== BACKEND) {
      return BACKEND + '/backend/api.php/';
    }

    // ── 3. Same-origin: auto-detect from script location ─────────────────
    try {
      const src = (document.currentScript || {}).src || '';
      if (src && src.startsWith('http')) {
        const url   = new URL(src);
        const parts = url.pathname.split('/').filter(Boolean);
        parts.splice(-3); // remove student-api.js, js, assets/js
        if (parts.length > 0 && parts[parts.length - 1] === 'frontend') parts.pop();
        const root = parts.length ? '/' + parts.join('/') + '/' : '/';
        return root + 'backend/api.php/';
      }
    } catch (_) {}

    // ── 4. Fallback: derive from current page URL ─────────────────────────
    try {
      const parts = window.location.pathname.split('/').filter(Boolean);
      parts.pop();
      while (parts.length > 1 &&
             ['assets','pages','students','lecturers','frontend'].includes(parts[parts.length - 1])) {
        parts.pop();
      }
      return '/' + parts.join('/') + '/backend/api.php/';
    } catch (_) {}

    return '/backend/api.php/';
  })();

  // App root, e.g. '/' derived from '/backend/api.php/'
  const APP_ROOT = BASE.replace(/backend\/api\.php\/?$/, '');

  // ── Storage keys ─────────────────────────────────────────────
  const KEYS = {
    token   : 'ec_student_token',
    student : 'ec_student',
    id      : 'ec_student_id',
    uuid    : 'ec_device_uuid',
  };

  const DEBUG = (() => {
    try {
      return localStorage.getItem('ec_student_api_debug') === '1' || window.__STUDENT_API_DEBUG__ === true;
    } catch (_) {
      return false;
    }
  })();

  function normalizeMediaUrl(path) {
    if (!path) return '';
    const raw = String(path).trim();
    if (!raw) return '';
    if (/^(https?:)?\/\//i.test(raw) || raw.startsWith('data:')) return raw;
    if (raw.startsWith('/')) return raw;

    const clean = raw.replace(/\\/g, '/').replace(/^\.\//, '');
    if (clean.startsWith('uploads/')) {
      return APP_ROOT + 'backend/' + clean;
    }
    return APP_ROOT + clean;
  }

  function normalizeProfilePhoto(data) {
    if (!data || typeof data !== 'object') return data;
    if (data.profile_photo) data.profile_photo = normalizeMediaUrl(data.profile_photo);
    if (data.photo_url) data.photo_url = normalizeMediaUrl(data.photo_url);
    return data;
  }

  // ── Local storage helpers ────────────────────────────────────
  const store = {
    getToken    : ()    => localStorage.getItem(KEYS.token),
    setToken    : (t)   => localStorage.setItem(KEYS.token, t),
    getProfile  : ()    => {
      try { return JSON.parse(localStorage.getItem(KEYS.student)); }
      catch { return null; }
    },
    setProfile  : (p)   => localStorage.setItem(KEYS.student, JSON.stringify(p)),
    getId       : ()    => localStorage.getItem(KEYS.id),
    setId       : (id)  => localStorage.setItem(KEYS.id, String(id)),
    clear       : ()    => Object.values(KEYS).forEach(k => localStorage.removeItem(k)),
  };

  // ── Device fingerprint (stable per browser/device) ────────────
  // Builds a SHA-256 hash from stable browser signals so the same
  // physical device always produces the same hash across sessions.
  // Falls back to a cached random UUID only if SubtleCrypto is
  // unavailable (very old browsers / non-HTTPS).
  async function getDeviceHash() {
    const cached = localStorage.getItem(KEYS.uuid);
    if (cached) return cached;

    let hash;
    try {
      const raw = [
        navigator.userAgent,
        navigator.platform,
        screen.width,
        screen.height,
        screen.colorDepth,
        Intl.DateTimeFormat().resolvedOptions().timeZone,
        navigator.hardwareConcurrency ?? '',
        navigator.language,
      ].join('|');

      const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
      hash = Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
    } catch {
      // SubtleCrypto unavailable (non-HTTPS or very old browser) — fall back to random UUID
      hash = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
      });
    }

    localStorage.setItem(KEYS.uuid, hash);
    return hash;
  }

  // Synchronous shim — returns cached hash or empty string on first load.
  function getDeviceUUID() {
    return localStorage.getItem(KEYS.uuid) || '';
  }

  // ── Login page resolution ─────────────────────────────────────
  function _loginPage(override) {
    if (override) return override;
    // On InfinityFree: /frontend/student-login.html
    // On Vercel: /student-login.html (files are at root)
    const BACKEND = 'https://ustededucore.rf.gd';
    if (window.location.origin === BACKEND) {
      return '/frontend/student-login.html';
    }
    // Vercel or other host — login page is at root
    return '/student-login.html';
  }

  // ── One-shot redirect guard — prevents parallel 401s from each
  //    triggering their own store.clear() + redirect race ────────
  let _redirecting = false;

  // ── Core fetch ───────────────────────────────────────────────
  async function request(method, endpoint, body = null, opts = {}) {
    const headers = { 'Content-Type': 'application/json' };
    const tk = store.getToken();
    if (tk) {
      headers['Authorization'] = `Bearer ${tk}`;
    }

    const config = { method, headers };
    if (body !== null && method !== 'GET') {
      config.body = JSON.stringify(body);
    }

    // InfinityFree strips Authorization headers — append token as _token query param
    // AuthMiddleware on the server accepts ?_token= as fallback
    let url = BASE + endpoint;
    if (tk) {
      const sep = url.includes('?') ? '&' : '?';
      url = url + sep + '_token=' + encodeURIComponent(tk);
    }

    let res;
    try {
      // Debug logging
      if (DEBUG) {
        console.log(`[StudentAPI] ${method} ${BASE}${endpoint}`, {
          hasToken: !!tk,
          tokenLength: tk ? tk.length : 0,
        });
      }
      res = await fetch(url, config);
    } catch (fetchErr) {
      console.error(`[StudentAPI] Network error:`, fetchErr);
      throw new StudentAPIError('Network error — please check your connection.', 0);
    }

    // Auto-redirect on 401 only if token exists (session expired).
    // _redirecting guard prevents parallel requests from each triggering
    // their own store.clear() + redirect after the first one fires.
    if (res.status === 401 && !opts.skipAuthRedirect) {
      const hasToken = store.getToken();
      console.warn(`[StudentAPI] Got 401 from ${endpoint}. hasToken=${!!hasToken}, redirecting=${_redirecting}`);
      if (hasToken && !_redirecting) {
        _redirecting = true;
        console.warn(`[StudentAPI] Clearing token and redirecting to login`);
        store.clear();
        window.location.href = _loginPage() + '?expired=1';
        throw new StudentAPIError('Session expired. Please sign in again.', 401);
      }
      // Either no token (bad credentials) or already redirecting — let fall through
    }

    let data;
    try { data = await res.json(); }
    catch { throw new StudentAPIError('Invalid server response.', res.status); }

    if (!res.ok) {
      const msg = data?.error || data?.message || `Server error ${res.status}`;
      const err = new StudentAPIError(msg, res.status);
      err.errors = data?.errors || {};
      
      // Only log actual errors (5xx, unexpected 4xx like auth failures)
      // Don't log expected 404s like "no active session"
      const isExpectedNotFound = res.status === 404 && (
        msg.includes('session') || 
        msg.includes('not found') || 
        msg.includes('No active')
      );
      
      if (!isExpectedNotFound) {
        console.error(`[StudentAPI] Error from ${endpoint}:`, {
          status: res.status,
          message: msg,
          fullResponse: data, 
        });
      }
      throw err;
    }
    return data;
  }

  // ── HTTP sugar ───────────────────────────────────────────────
  const get = (endpoint, params) => {
    // Filter out null/undefined params before stringifying
    let qs = '';
    if (params) {
      const clean = Object.fromEntries(
        Object.entries(params).filter(([, v]) => v != null)
      );
      if (Object.keys(clean).length) {
        qs = '?' + new URLSearchParams(clean).toString();
      }
    }
    return request('GET', endpoint + qs);
  };
  const post  = (ep, body)  => request('POST',  ep, body);
  const patch = (ep, body)  => request('PATCH', ep, body);

  // ══════════════════════════════════════════════════════════════
  // AUTH
  // ══════════════════════════════════════════════════════════════
  const auth = {
    /**
     * POST student/auth/login
     * Accepts index_number (or email) + password + device_uuid.
     * Stores token and profile on success.
     * 
     * Note: Uses skipAuthRedirect=true because login endpoint doesn't require auth,
     * and we want the actual error message, not session-expired redirect.
     */
    login: async (indexNumberOrEmail, password) => {
      // Clear any stale token first to ensure fresh login
      const staleToken = store.getToken();
      if (staleToken) {
        console.warn('[StudentAPI] Clearing stale token before login attempt', {
          tokenLength: staleToken.length,
        });
        localStorage.removeItem('ec_student_token');
      }
      
      console.log('[StudentAPI] Attempting login for:', indexNumberOrEmail);
      
      try {
      const data = await request('POST', 'student/auth/login', {
          index_number : indexNumberOrEmail,
          password,
          device_uuid  : await getDeviceHash(),
          device_name  : _guessDeviceName(),
          device_type  : _guessDeviceType(),
          browser      : navigator.userAgent || '',
        }, { skipAuthRedirect: true });  // ← Skip the session-expired redirect
        
        console.log('[StudentAPI] Login successful:', {
          hasToken: !!data.token,
          tokenLength: data.token ? data.token.length : 0,
          studentId: data.student?.student_id,
        });
        
        if (data.token) {
          store.setToken(data.token);
          if (data.student) {
            store.setProfile(data.student);
            store.setId(data.student.student_id);
          }
        }
        return data;
      } catch (err) {
        console.error('[StudentAPI] Login failed:', {
          message: err.message,
          status: err.status,
          errors: err.errors,
        });
        throw err;
      }
    },

    /**
     * POST student/auth/register
     * payload: { student_name, index_number, email, password }
     * device_uuid injected automatically.
     */
    register: async (payload) => request('POST', 'student/auth/register', {
      ...payload,
      device_uuid : await getDeviceHash(),
      device_name : _guessDeviceName(),
      device_type : _guessDeviceType(),
      browser     : navigator.userAgent || '',
    }, { skipAuthRedirect: true }),  // ← Skip the session-expired redirect

    /** POST student/auth/forgot-password */
    forgotPassword : (email) => request('POST', 'student/auth/forgot-password', { email }, { skipAuthRedirect: true }),

    /** POST student/auth/reset-password */
    resetPassword  : (body)  => request('POST', 'student/auth/reset-password', body, { skipAuthRedirect: true }),

    /** POST student/auth/resend-verification */
    resendVerification : (email) => request('POST', 'student/auth/resend-verification', { email }, { skipAuthRedirect: true }),

    /** Clear tokens and redirect to login. */
    logout: () => {
      store.clear();
      window.location.href = _loginPage();
    },

    /**
     * Guard: redirect to loginPage if not authenticated.
     * Call this at the top of every protected student page.
     * Returns true if authenticated, false (+ redirects) if not.
     */
    requireAuth: (loginPage) => {
      if (!store.getToken()) {
        window.location.href = _loginPage(loginPage);
        return false;
      }
      return true;
    },

    /**
     * Redirect to dest if already logged in.
     * Use at the top of student-login / student-signup pages.
     */
    redirectIfLoggedIn: (dest) => {
      if (store.getToken()) {
        window.location.href = dest || 'assets/pages/students/stu-dashboard.html';
        return true;
      }
      return false;
    },

    isLoggedIn : () => !!store.getToken(),
  };

  // ══════════════════════════════════════════════════════════════
  // PROFILE
  // ══════════════════════════════════════════════════════════════
  const profile = {
    /** GET student/me — full student row with institution + lecturer joins */
    getMe         : ()     => get('student/me').then(normalizeProfilePhoto),
    /** GET student/profile — alias */
    get           : ()     => get('student/profile').then(normalizeProfilePhoto),
    /** PATCH student/profile — updatable fields: phone, profile_photo */
    update        : (body) => patch('student/profile', body),
    /** Instant paint from localStorage (no network round-trip) */
    getCached     : ()     => store.getProfile(),
    /** GET student/security — full device list from student_devices */
    getSecurity   : ()           => get('student/security'),
    /** POST student/device/remove — student removes one of their own devices */
    removeDevice  : (deviceId)   => post('student/device/remove', { device_id: deviceId }),
    /** POST student/device/unbind-request — ask lecturer to reset ALL devices */
    requestUnbind : ()           => post('student/device/unbind-request', {}),
    /** GET student/audit — student's own audit log */
    getAudit      : (p)    => get('student/audit', p),
  };

  // ══════════════════════════════════════════════════════════════
  // CLASSES
  // ══════════════════════════════════════════════════════════════
  const classes = {
    /** GET student/classes — all classes for this student's lecturer */
    list       : ()            => get('student/classes'),
    /** GET student/classes/{id} */
    get        : (id)          => get(`student/classes/${id}`),
    /** GET student/classes/{id}/attendance — per-session breakdown */
    attendance : (id, params)  => get(`student/classes/${id}/attendance`, params),
  };

  // ══════════════════════════════════════════════════════════════
  // ACTIVE SESSION  (Check-In Hub)
  // ══════════════════════════════════════════════════════════════
  const session = {
    /**
     * GET student/session/active
     * Returns the open session for today if one exists.
     * 404 → no active session right now.
     * 409 → student already checked in.
     */
    getActive   : ()   => get('student/session/active'),
    /**
     * GET student/session/{id}/geofence-map
     * Returns { gps_latitude, gps_longitude, geofence_radius_meters, class_name }
     */
    getGeofence : (id) => get(`student/session/${id}/geofence-map`),
    /**
     * GET student/session/{id}/qr-current
     * Returns { qr_code_value, manual_code_value, expires_at }
     */
    getQR       : (id) => get(`student/session/${id}/qr-current`),
  };

  // ══════════════════════════════════════════════════════════════
  // CHECK-IN  (7-layer SATE flow)
  // ══════════════════════════════════════════════════════════════
  const checkin = {
    /**
     * POST student/checkin
     * body: {
     *   session_id, qr_code?, manual_code?,
     *   password (Layer 6),
     *   latitude?, longitude? (Layer 4)
     * }
     * device_uuid injected automatically (Layer 3).
     *
     * Returns: { attendance_id, sate_token, status, check_in_time, ... }
     */
    // Check-in may return 401 for wrong password confirmation; do not auto-logout.
    submit: (body) => request('POST', 'student/checkin', {
      ...body,
      device_uuid : getDeviceUUID(),
    }, { skipAuthRedirect: true }),

    /**
     * GET student/checkin/receipt/{attendance_id}
     * Returns the full attendance record for download/display.
     */
    getReceipt : (attendanceId) => get(`student/checkin/receipt/${attendanceId}`),

    /**
     * GET student/checkin/history
     * params: { limit? }
     */
    getHistory : (params) => get('student/checkin/history', params),
  };

  // ══════════════════════════════════════════════════════════════
  // ATTENDANCE  (analytics & history)
  // ══════════════════════════════════════════════════════════════
  const attendance = {
    /**
     * GET student/attendance
     * Full history across all classes.
     * params: { limit?, class_id? }
     */
    list   : (params) => get('student/attendance', params),

    /**
     * GET student/attendance/stats
     * Returns: { total, present, absent, late, excused, rate }
     */
    stats  : ()       => get('student/attendance/stats'),

    /**
     * GET student/attendance/streak
     * Returns: { streak: number }
     */
    streak : ()       => get('student/attendance/streak'),

    /**
     * GET student/attendance/risk
     * Returns: { risk_courses: [{ class_id, class_name, course_code,
     *   total_sessions, present_count }] }
     * Only courses where attendance < 75%.
     */
    risk   : ()       => get('student/attendance/risk'),
  };

  // ══════════════════════════════════════════════════════════════
  // OVERRIDE REQUESTS
  // ══════════════════════════════════════════════════════════════
  const override = {
    /**
     * POST student/override/request
     * body: { session_id, class_id?, latitude?, longitude? }
     * device_uuid injected automatically.
     */
    submit : (body)   => post('student/override/request', {
      ...body,
      device_uuid : getDeviceUUID(),
    }),

    /**
     * GET student/override/history
     * params: { limit? }
     */
    history : (params) => get('student/override/history', params),
  };

  // ══════════════════════════════════════════════════════════════
  // NOTIFICATIONS
  // ══════════════════════════════════════════════════════════════
  const notifications = {
    /** GET student/notifications  params: { limit? } */
    list         : (params) => get('student/notifications', params),
    /** POST student/notifications/{id}/read */
    markRead     : (id)     => post(`student/notifications/${id}/read`, {}),
    /** POST student/notifications/read-all */
    markAllRead  : ()       => post('student/notifications/read-all', {}),
    /** GET student/notifications/unread-count → { count: number } */
    unreadCount  : ()       => get('student/notifications/unread-count'),
  };

  // ══════════════════════════════════════════════════════════════
  // GEOFENCE LOGS
  // ══════════════════════════════════════════════════════════════
  const geofence = {
    /**
     * GET student/geofence/logs
     * params: { limit? }
     */
    logs : (params) => get('student/geofence/logs', params),
  };

  // ══════════════════════════════════════════════════════════════
  // DEPT CHAT  (stub — endpoint not yet implemented in backend)
  // ══════════════════════════════════════════════════════════════
  const deptChat = {
    messages : (params) => get('student/dept-chat/messages', params),
    send     : (body)   => post('student/dept-chat/messages', body),
  };

  // ── Device detection helpers ──────────────────────────────────
  // Mirror of PHP's guessDeviceName() so the DB gets a readable label
  // on first login without waiting for PHP to guess from User-Agent.
  function _guessDeviceName() {
    const ua = navigator.userAgent || '';
    let os = 'Unknown';
    if (/Android/i.test(ua))       os = 'Android';
    else if (/iPhone/i.test(ua))   os = 'iPhone';
    else if (/iPad/i.test(ua))     os = 'iPad';
    else if (/Windows/i.test(ua))  os = 'Windows';
    else if (/Macintosh/i.test(ua))os = 'Mac';
    else if (/Linux/i.test(ua))    os = 'Linux';

    let br = 'Browser';
    if (/Edg\//i.test(ua))         br = 'Edge';
    else if (/OPR\//i.test(ua))    br = 'Opera';
    else if (/Chrome/i.test(ua))   br = 'Chrome';
    else if (/Firefox/i.test(ua))  br = 'Firefox';
    else if (/Safari/i.test(ua))   br = 'Safari';

    return `${os} / ${br}`;
  }

  function _guessDeviceType() {
    const ua = navigator.userAgent || '';
    if (/Mobi|Android.*Mobile|iPhone/i.test(ua)) return 'mobile';
    if (/iPad|Android(?!.*Mobile)/i.test(ua))    return 'tablet';
    return 'desktop';
  }

  // ── Public surface ───────────────────────────────────────────
  return {
    // Namespaces
    auth, profile,
    classes, session,
    checkin, attendance,
    override, notifications,
    geofence, deptChat,
    // Utilities
    getDeviceUUID,
    getDeviceHash,
    store,
    // Raw HTTP helpers (for one-off calls in dashboard)
    get, post, patch,
  };

})();

/* ── XSS-safe escape ────────────────────────────────────────── */
function esc(str) {
  if (str == null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/* ── Haversine distance (km) ────────────────────────────────── */
function haversineKm(lat1, lng1, lat2, lng2) {
  const R    = 6371;
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLng = (lng2 - lng1) * Math.PI / 180;
  const a    =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(lat1 * Math.PI / 180) *
    Math.cos(lat2 * Math.PI / 180) *
    Math.sin(dLng / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/* ── Expose globally for dashboard inline scripts ───────────── */
window.StudentAPI   = StudentAPI;
window.esc          = window.esc || esc;
window.haversineKm  = haversineKm;