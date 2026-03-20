/* =============================================================
   EduCore ÔÇö api.js  v6.0
   Central HTTP client for all pages at the frontend root:
     index.html, login.html, signup.html,
     student-login.html, student-signup.html,
     pages/dashboard.html and all /pages/* lecturer pages.

   Exposes two globals:
     API          ÔÇö full raw client (lecturer + student routes)
     EduCoreAPI   ÔÇö convenience namespace used by HTML inline scripts
                    EduCoreAPI.lecturer.*  (login, signup)
                    EduCoreAPI.student.*   (student-login, student-signup)
   ============================================================= */

/* ÔöÇÔöÇ APIError ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ */
class APIError extends Error {
  constructor(message, status = 0) {
    super(message);
    this.name   = 'APIError';
    this.status = status;
    this.errors = {};
  }
}

/* ÔöÇÔöÇ Core API client (IIFE) ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ */
const API = (() => {

  // ÔöÇÔöÇ BASE URL resolution ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  // api.js lives at: <appRoot>/assets/js/api.js
  // Used by pages at both <appRoot>/login.html and <appRoot>/assets/pages/*
  // Strategy:
  // 1. Calculate from script location (most reliable)
  // 2. Calculate from current page location and remove /frontend/ if present
  // 3. Final fallback with correct path
  const BASE = (() => {
    // ── 1. Manual override (highest priority) ────────────────────────────
    if (window.EDUCORE_API_BASE) return window.EDUCORE_API_BASE.replace(/\/?$/, '/');

    // ── 2. Cross-origin detection (Vercel → InfinityFree) ────────────────
    // If this JS is running from a different origin than the backend,
    // use the hardcoded InfinityFree backend URL directly.
    const BACKEND = 'http://ustededucore.rf.gd';
    if (window.location.origin !== BACKEND) {
      return BACKEND + '/backend/api.php/';
    }

    // ── 3. Same-origin: auto-detect from script location ─────────────────
    try {
      const src = (document.currentScript || {}).src || '';
      if (src && src.startsWith('http')) {
        const url   = new URL(src);
        const parts = url.pathname.split('/').filter(Boolean);
        parts.splice(-3); // remove api.js, js, assets
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

  // ÔöÇÔöÇ Device fingerprint (stable per browser/device) ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  // Builds a SHA-256 hash from stable browser signals so the same
  // physical device always produces the same hash across sessions.
  // Falls back to a cached random UUID only if SubtleCrypto is
  // unavailable (very old browsers / non-HTTPS).
  async function getDeviceHash() {
    const KEY = 'ec_device_hash';
    const cached = localStorage.getItem(KEY);
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

      const buf    = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
      hash = Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
    } catch {
      // SubtleCrypto unavailable (non-HTTPS or very old browser) ÔÇö fall back to random UUID
      hash = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
      });
    }

    localStorage.setItem(KEY, hash);
    return hash;
  }

  // Synchronous shim used by legacy callers ÔÇö returns cached hash or
  // empty string on first load (async getDeviceHash() resolves it).
  function getDeviceUUID() {
    return localStorage.getItem('ec_device_hash') || '';
  }

  // ÔöÇÔöÇ Token stores ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const lecturerToken = {
    get   : ()  => localStorage.getItem('ec_token'),
    set   : (t) => localStorage.setItem('ec_token', t),
    clear : ()  => {
      localStorage.removeItem('ec_token');
      localStorage.removeItem('ec_lecturer');
    },
  };

  const studentToken = {
    get   : ()  => localStorage.getItem('ec_student_token'),
    set   : (t) => localStorage.setItem('ec_student_token', t),
    clear : ()  => {
      localStorage.removeItem('ec_student_token');
      localStorage.removeItem('ec_student');
      localStorage.removeItem('ec_student_id');
    },
  };

  // ÔöÇÔöÇ Core fetch ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  async function request(method, endpoint, body = null, opts = {}) {
    const headers = { 'Content-Type': 'application/json' };

    // Auto-attach the right token depending on namespace
    const tk = opts.useStudentToken
      ? studentToken.get()
      : lecturerToken.get();
    if (tk) headers['Authorization'] = `Bearer ${tk}`;

    const config = { method, headers };
    if (body !== null && method !== 'GET') {
      config.body = JSON.stringify(body);
    }

    let res;
    try {
      // InfinityFree strips Authorization headers — append _token as fallback
      const _url = tk ? BASE + endpoint + (endpoint.includes('?') ? '&' : '?') + '_token=' + encodeURIComponent(tk) : BASE + endpoint;
      res = await fetch(_url, config);
    } catch {
      throw new APIError('Network error ÔÇö please check your connection.', 0);
    }

    if (res.status === 401 && !opts.skipAuthRedirect) {
      // Only auto-redirect if there was a token (session expired)
      // If no token, it's likely invalid credentials from login attempt
      const hasToken = opts.useStudentToken 
        ? studentToken.get() 
        : lecturerToken.get();
      
      if (hasToken) {
        // Token exists but API returned 401 = token expired
        if (opts.useStudentToken) {
          studentToken.clear();
          window.location.href = window.location.origin === 'http://ustededucore.rf.gd' ? '/frontend/student-login.html?expired=1' : '/student-login.html?expired=1';
        } else {
          lecturerToken.clear();
          window.location.href = window.location.origin === 'http://ustededucore.rf.gd' ? '/frontend/login.html?expired=1' : '/login.html?expired=1';
        }
        throw new APIError('Session expired. Please log in again.', 401);
      }
      // No token but got 401 = bad credentials, let error pass through below
    }

    let data;
    try { data = await res.json(); }
    catch { throw new APIError('Invalid server response.', res.status); }

    if (!res.ok) {
      const msg = data?.error || data?.message || `Error ${res.status}`;
      const err = new APIError(msg, res.status);
      err.errors = data?.errors || {};
      throw err;
    }
    return data;
  }

  // ÔöÇÔöÇ Multipart upload ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  async function upload(endpoint, formData, opts = {}) {
    const headers = {};
    const tk = opts.useStudentToken
      ? studentToken.get()
      : lecturerToken.get();
    if (tk) headers['Authorization'] = `Bearer ${tk}`;

    let res;
    try {
      const _uploadUrl = tk ? BASE + endpoint + (endpoint.includes('?') ? '&' : '?') + '_token=' + encodeURIComponent(tk) : BASE + endpoint;
      res = await fetch(_uploadUrl, { method: 'POST', headers, body: formData });
    } catch {
      throw new APIError('Upload failed ÔÇö check your connection.', 0);
    }
    if (res.status === 401) {
      lecturerToken.clear();
      window.location.href = window.location.origin === 'http://ustededucore.rf.gd' ? '/frontend/login.html?expired=1' : '/login.html?expired=1';
      throw new APIError('Session expired.', 401);
    }
    const data = await res.json();
    if (!res.ok) throw new APIError(data?.error || 'Upload error.', res.status);
    return data;
  }

  // ÔöÇÔöÇ HTTP sugar ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const get   = (ep, params, opts)  => {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return request('GET', ep + qs, null, opts || {});
  };
  const post  = (ep, body, opts)    => request('POST',   ep, body,  opts || {});
  const put   = (ep, body, opts)    => request('PUT',    ep, body,  opts || {});
  const patch = (ep, body, opts)    => request('PATCH',  ep, body,  opts || {});
  const del   = (ep, opts)          => request('DELETE', ep, null,  opts || {});

  // ÔöÇÔöÇ Student-scoped sugar (auto-adds useStudentToken flag) ÔöÇÔöÇÔöÇÔöÇ
  const SOPT = { useStudentToken: true };
  const sget   = (ep, params) => get(ep, params, SOPT);
  const spost  = (ep, body)   => post(ep, body, SOPT);
  const spatch = (ep, body)   => patch(ep, body, SOPT);

  // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
  // LECTURER ENDPOINTS
  // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ

  // ÔöÇÔöÇ Lecturer auth ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const auth = {
    register           : body  => post('auth/register',             body),
    login              : body  => post('auth/login',                body),
    logout             : ()    => post('auth/logout',               {}),
    me                 : ()    => get('auth/me'),
    resendVerification : body  => post('auth/resend-verification',  body),
    forgotPassword     : body  => post('auth/forgot-password',      body),
    resetPassword      : body  => post('auth/reset-password',       body),
  };

  // ÔöÇÔöÇ Dashboard ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const dashboard = {
    index : () => get('dashboard'),
  };

  // ÔöÇÔöÇ Classes ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const classes = {
    list      : ()           => get('classes'),
    get       : id           => get(`classes/${id}`),
    create    : body         => post('classes', body),
    update    : (id, body)   => put(`classes/${id}`, body),
    delete    : id           => del(`classes/${id}`),
    regenCode : id           => post(`classes/${id}/regen`, {}),
    students  : (id, params) => get(`classes/${id}/students`, params),
  };

  // ÔöÇÔöÇ Sessions (live) ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const sessions = {
    list            : params => get('sessions', params),
    open            : body  => post('attendance/session/open', body),
    close           : id    => post(`sessions/${id}/close`, {}),
    getOpen         : ()    => get('attendance/session/open'),
    detail          : id    => get(`sessions/${id}`),
    extend          : id    => post(`sessions/${id}/extend`, {}),
    refreshGeofence : (id, body) => post(`sessions/${id}/refresh-geofence`, body),
    rotateQR        : id    => patch(`sessions/${id}`, { rotate_qr: true }),
    currentCode     : id    => get(`sessions/${id}/current-code`),
    recentCheckins  : id    => get(`sessions/${id}/recent-checkins`),
    liveStats       : id    => get(`sessions/${id}/live-stats`),
  };

  // ÔöÇÔöÇ Attendance records ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const attendance = {
    record      : body         => post('attendance/record', body),
    edit        : (id, body)   => patch(`attendance/record/${id}`, body),
    summary     : params       => get('attendance/summary', params),
    heatmap     : params       => get('attendance/heatmap', params),
    syncPending : ()           => get('attendance/sync/pending'),
    syncResolve : body         => post('attendance/sync/resolve', body),
  };

  // ÔöÇÔöÇ Student roster (lecturer-facing) ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const students = {
    list              : params      => get('students', params),
    get               : id          => get(`students/${id}`),
    lookup            : generatedId => get('students/lookup', { generated_id: generatedId }),
    update            : (id, body)  => patch(`students/${id}`, body),
    biometricStatus   : id          => get(`students/${id}/biometrics`),
    enrollFingerprint : (id, body)  => post(`students/${id}/biometrics/fingerprint`, body),
    enrollFace        : (id, body)  => post(`students/${id}/biometrics/face`, body),
    deleteBiometrics  : id          => del(`students/${id}/biometrics`),
  };

  // ÔöÇÔöÇ Master list ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const masterList = {
    list   : params   => get('master-list', params),
    upload : formData => upload('master-list/upload', formData),
    delete : id       => del(`master-list/${id}`),
  };

  // ÔöÇÔöÇ Override (lecturer actions) ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const override = {
    request        : body       => post('override/request', body),
    getPending     : ()         => get('override/pending'),
    approve        : (id, body) => post(`override/${id}/approve`, body ?? {}),
    reject         : (id, body) => post(`override/${id}/reject`,  body ?? {}),
    sessionHistory : sessId     => get(`override/session/${sessId}`),
  };

  // ÔöÇÔöÇ Offline sync ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const sync = {
    index       : ()   => get('sync'),
    push        : body => post('sync', body),
    retry       : id   => post(`sync/${id}/retry`, {}),
    clearFailed : ()   => post('sync/clear-failed', {}),
  };

  // ÔöÇÔöÇ Reports ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const reports = {
    summary : params => get('reports/summary', params),
    heatmap : params => get('reports/heatmap', params),
    risk    : params => get('reports/risk', params),
  };

  // ÔöÇÔöÇ Settings ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const settings = {
    get  : ()     => get('settings'),
    save : body   => post('settings', body),
  };

  // ÔöÇÔöÇ Lecturer profile photo ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  // POST   lecturer/photo  ÔÇö multipart upload; returns { photo_url, photo_updated_at }
  // DELETE lecturer/photo  ÔÇö removes the file from disk and NULLs the DB column
  const photo = {
    upload : (formData) => upload('lecturer/photo', formData),
    remove : ()         => del('lecturer/photo'),
  };

  // ÔöÇÔöÇ Lecturer profile (editable fields) ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  // GET   lecturer/profile  ÔÇö fetch full_name, email, phone, title, bio
  // PATCH lecturer/profile  ÔÇö update those same editable fields
  const lecturerProfile = {
    get    : ()     => get('lecturer/profile'),
    update : (body) => patch('lecturer/profile', body),
  };

  // ÔöÇÔöÇ Audit log ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const audit = {
    list : params => get('audit', params),
  };

  // ÔöÇÔöÇ Notifications (lecturer) ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const notifications = {
    list        : params => get('notifications', params),
    markRead    : id     => post(`notifications/${id}/read`, {}),
    markAllRead : ()     => post('notifications/read-all', {}),
  };

  // ÔöÇÔöÇ Course Messages (lecturer) ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  // Endpoints: messages/{class_id}
  //   GET  ?since={lastId}&limit=40  ÔÇö cursor-paginated history
  //   POST                           ÔÇö send a message (body, is_broadcast, parent_id)
  //   POST messages/{class_id}/read  ÔÇö mark messages read up to message_id
  //   GET  messages/unread-counts    ÔÇö { channels: [{ class_id, unread }] }
  //   POST messages/{msg_id}/react   ÔÇö toggle emoji reaction
  //   DELETE messages/{msg_id}       ÔÇö soft-delete (lecturer only)
  const messages = {
    /**
     * List messages for a channel (cursor-paginated).
     * params: { since: lastMessageId, limit: 40 }
     * Returns: { messages: [...] }  or  [ ... ]
     */
    list : (classId, since = 0, limit = 40) =>
      get(`messages/${classId}`, { since, limit }),

    /**
     * Send a message to a course channel.
     * body: { body: string, is_broadcast: 0|1, parent_id: int|null }
     * Returns the new message object.
     */
    send : (classId, body) =>
      post(`messages/${classId}`, body),

    /**
     * Batch-mark messages as read.
     * Inserts read records up to (and including) upToMessageId.
     */
    markRead : (classId, upToMessageId) =>
      post(`messages/${classId}/read`, { up_to_message_id: upToMessageId }),

    /**
     * Get unread counts for all channels.
     * Returns: { channels: [{ class_id, unread }] }
     */
    unreadCounts : () =>
      get('messages/unread-counts'),

    /**
     * Toggle an emoji reaction on a message.
     * Adds the reaction if absent; removes it if present.
     * body: { emoji: '­ƒæì' }
     */
    react : (messageId, emoji) =>
      post(`messages/${messageId}/react`, { emoji }),

    /**
     * Soft-delete a message (lecturer only).
     * Sets is_deleted = 1, deleted_by = 'lecturer'.
     */
    delete : (messageId) =>
      del(`messages/${messageId}`),
  };

  // ÔöÇÔöÇ Course activities ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const activities = {
    list   : params       => get('activities', params),
    get    : id           => get(`activities/${id}`),
    create : body         => post('activities', body),
    update : (id, body)   => put(`activities/${id}`, body),
    delete : id           => del(`activities/${id}`),
  };

  // ÔöÇÔöÇ Institutions (public, no auth) ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  const institutions = {
    search : q => get('institutions/search', { q }),
  };

  // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
  // STUDENT ENDPOINTS (via api.php student/* routes)
  // Used by student-login.html, student-signup.html,
  // and students/dashboard.html via this file
  // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ

  const studentAuth = {
    register           : body  => spost('student/auth/register',            body),
    login              : body  => spost('student/auth/login',               body),
    resendVerification : email => spost('student/auth/resend-verification',  { email }),
    forgotPassword     : email => spost('student/auth/forgot-password',      { email }),
    resetPassword      : body  => spost('student/auth/reset-password',       body),
  };

  const studentProfile = {
    getMe         : ()         => sget('student/me'),
    get           : ()         => sget('student/profile'),
    update        : body       => spatch('student/profile', body),
    getSecurity   : ()         => sget('student/security'),
    removeDevice  : (deviceId) => spost('student/device/remove', { device_id: deviceId }),
    requestUnbind : ()         => spost('student/device/unbind-request', {}),
    getAudit      : params     => sget('student/audit', params),
  };

  const studentClasses = {
    list       : ()           => sget('student/classes'),
    get        : id           => sget(`student/classes/${id}`),
    attendance : (id, params) => sget(`student/classes/${id}/attendance`, params),
  };

  const studentSession = {
    getActive   : ()  => sget('student/session/active'),
    getGeofence : id  => sget(`student/session/${id}/geofence-map`),
    getQR       : id  => sget(`student/session/${id}/qr-current`),
  };

  const studentCheckin = {
    submit : body => spost('student/checkin', {
      ...body,
      device_uuid : getDeviceUUID(),
    }),
    getReceipt : attId  => sget(`student/checkin/receipt/${attId}`),
    getHistory : params => sget('student/checkin/history', params),
  };

  const studentAttendance = {
    list   : params => sget('student/attendance', params),
    stats  : ()     => sget('student/attendance/stats'),
    streak : ()     => sget('student/attendance/streak'),
    risk   : ()     => sget('student/attendance/risk'),
  };

  const studentOverride = {
    submit  : body   => spost('student/override/request', {
      ...body,
      device_uuid : getDeviceUUID(),
    }),
    history : params => sget('student/override/history', params),
  };

  const studentNotifications = {
    list        : params => sget('student/notifications', params),
    markRead    : id     => spost(`student/notifications/${id}/read`, {}),
    markAllRead : ()     => spost('student/notifications/read-all', {}),
    unreadCount : ()     => sget('student/notifications/unread-count'),
  };

  const studentGeofence = {
    logs : params => sget('student/geofence/logs', params),
  };

  // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
  // SESSION HELPERS
  // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ

  function saveLecturerSession(tokenStr, lecturerObj) {
    lecturerToken.set(tokenStr);
    localStorage.setItem('ec_lecturer', JSON.stringify(lecturerObj));
  }

  function saveStudentSession(tokenStr, studentObj) {
    studentToken.set(tokenStr);
    localStorage.setItem('ec_student', JSON.stringify(studentObj));
    if (studentObj?.student_id) {
      localStorage.setItem('ec_student_id', String(studentObj.student_id));
    }
  }

  function getStoredLecturer() {
    try { return JSON.parse(localStorage.getItem('ec_lecturer')); }
    catch { return null; }
  }

  function getStoredStudent() {
    try { return JSON.parse(localStorage.getItem('ec_student')); }
    catch { return null; }
  }

  function clearLecturerSession() { lecturerToken.clear(); }
  function clearStudentSession()  { studentToken.clear();  }

  function isLecturerLoggedIn() { return !!lecturerToken.get(); }
  function isStudentLoggedIn()  { return !!studentToken.get();  }

  // ÔöÇÔöÇ Public surface ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
  return {
    // HTTP primitives
    get, post, put, patch, del, upload, request,

    // Lecturer modules
    auth, dashboard,
    classes, sessions, attendance,
    students, masterList,
    override, sync,
    reports, settings, audit,
    notifications, messages, activities,
    institutions, photo, lecturerProfile,

    // Student modules
    studentAuth, studentProfile,
    studentClasses, studentSession,
    studentCheckin, studentAttendance,
    studentOverride, studentNotifications,
    studentGeofence,

    // Session helpers
    saveLecturerSession, saveStudentSession,
    getStoredLecturer,   getStoredStudent,
    clearLecturerSession, clearStudentSession,
    isLecturerLoggedIn,  isStudentLoggedIn,
    getDeviceUUID,
    getDeviceHash,

    // Token handles (for advanced use)
    lecturerToken, studentToken,
  };

})();

/* ÔöÇÔöÇ Device detection helpers (shared by EduCoreAPI.student.login/register) ÔöÇÔöÇ */
function _guessDeviceName() {
  const ua = navigator.userAgent || '';
  let os = 'Unknown';
  if (/Android/i.test(ua))        os = 'Android';
  else if (/iPhone/i.test(ua))    os = 'iPhone';
  else if (/iPad/i.test(ua))      os = 'iPad';
  else if (/Windows/i.test(ua))   os = 'Windows';
  else if (/Macintosh/i.test(ua)) os = 'Mac';
  else if (/Linux/i.test(ua))     os = 'Linux';

  let br = 'Browser';
  if (/Edg\//i.test(ua))          br = 'Edge';
  else if (/OPR\//i.test(ua))     br = 'Opera';
  else if (/Chrome/i.test(ua))    br = 'Chrome';
  else if (/Firefox/i.test(ua))   br = 'Firefox';
  else if (/Safari/i.test(ua))    br = 'Safari';

  return `${os} / ${br}`;
}

function _guessDeviceType() {
  const ua = navigator.userAgent || '';
  if (/Mobi|Android.*Mobile|iPhone/i.test(ua)) return 'mobile';
  if (/iPad|Android(?!.*Mobile)/i.test(ua))    return 'tablet';
  return 'desktop';
}

/* ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
   EduCoreAPI ÔÇö compatibility namespace
   HTML inline scripts call:
     EduCoreAPI.lecturer.login(email, pw)
     EduCoreAPI.lecturer.register({...})
     EduCoreAPI.lecturer.forgotPassword(email)
     EduCoreAPI.lecturer.redirectIfLoggedIn(url)
     EduCoreAPI.student.login(sid, pw)
     EduCoreAPI.student.register({...})
     EduCoreAPI.student.forgotPassword(email)
     EduCoreAPI.student.redirectIfLoggedIn(url)
   ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ */
const EduCoreAPI = {

  /* ÔöÇÔöÇ Lecturer ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ */
  lecturer: {
    /**
     * Redirect to `url` if a valid lecturer token exists in localStorage.
     * Called at page-load on login.html to skip the form for active sessions.
     */
    redirectIfLoggedIn(url) {
      if (localStorage.getItem('ec_token')) {
        window.location.href = url;
      }
    },

    isLoggedIn() {
      return !!localStorage.getItem('ec_token');
    },

    /**
     * login.html calls: EduCoreAPI.lecturer.login(email, pw)
     * On success: stores token + lecturer object, resolves.
     * On failure: rejects with Error(message) so the form can show it.
     */
    async login(email, password) {
      const data = await API.auth.login({ email, password });
      // Backend returns { token, lecturer: { ... } }
      API.saveLecturerSession(data.token, data.lecturer);
      return data;
    },

    /**
     * signup.html calls: EduCoreAPI.lecturer.register({ name, staff_id, email, password })
     * On success: resolves (step 3 screen shown by HTML).
     * On failure: rejects with Error(message).
     */
    async register(body) {
      return API.auth.register(body);
    },

    /**
     * login.html calls: EduCoreAPI.lecturer.forgotPassword(email)
     * Returns the API response (success/error handled by modal).
     */
    async forgotPassword(email) {
      return API.auth.forgotPassword({ email });
    },

    async logout() {
      try { await API.auth.logout(); } catch { /* ignore */ }
      API.clearLecturerSession();
      window.location.href = '/frontend/login.html';
    },
  },

  /* ÔöÇÔöÇ Student ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ */
  student: {
    /**
     * Redirect to `url` if a valid student token exists.
     * Called at page-load on student-login.html.
     */
    redirectIfLoggedIn(url) {
      if (localStorage.getItem('ec_student_token')) {
        window.location.href = url;
      }
    },

    isLoggedIn() {
      return !!localStorage.getItem('ec_student_token');
    },

    /**
     * student-login.html calls: EduCoreAPI.student.login(sid, pw)
     * sid = index_number or email (the input field labelled "Student ID or email")
     * Injects device_uuid automatically.
     */
    async login(sid, password) {
      const data = await API.studentAuth.login({
        index_number : sid,
        password,
        device_uuid  : await API.getDeviceHash(),
        device_name  : _guessDeviceName(),
        device_type  : _guessDeviceType(),
        browser      : navigator.userAgent || '',
      });
      API.saveStudentSession(data.token, data.student);
      return data;
    },

    async register(body) {
      return API.studentAuth.register({
        ...body,
        device_uuid : await API.getDeviceHash(),
        device_name : _guessDeviceName(),
        device_type : _guessDeviceType(),
        browser     : navigator.userAgent || '',
      });
    },

    /**
     * student-login.html calls: EduCoreAPI.student.forgotPassword(email)
     */
    async forgotPassword(email) {
      return API.studentAuth.forgotPassword(email);
    },

    /**
     * Resend verification email.
     */
    async resendVerification(email) {
      return API.studentAuth.resendVerification(email);
    },

    async logout() {
      API.clearStudentSession();
      window.location.href = '/frontend/student-login.html';
    },
  },
};

// Make EduCoreAPI globally accessible (for inline scripts that run after this file)
window.EduCoreAPI = EduCoreAPI;
window.API        = API;