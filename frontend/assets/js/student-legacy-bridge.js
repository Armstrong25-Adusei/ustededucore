/* Legacy endpoint bridge for stu-* pages.
   Translates old api/student/*.php calls to StudentAPI routes. */
(function(){
  if (!window.StudentAPI || window.__studentLegacyBridgeInstalled) return;
  window.__studentLegacyBridgeInstalled = true;

  const nativeFetch = window.fetch.bind(window);

  const jsonResponse = (data, status) => ({
    ok: status >= 200 && status < 300,
    status,
    json: async () => data,
    text: async () => JSON.stringify(data),
    headers: new Headers({ 'Content-Type': 'application/json' })
  });

  const toObject = (searchParams) => {
    const out = {};
    for (const [k, v] of searchParams.entries()) out[k] = v;
    return out;
  };

  const parseBody = (init) => {
    if (!init || init.body == null) return {};
    if (typeof init.body === 'string') {
      try { return JSON.parse(init.body); } catch (_) { return {}; }
    }
    if (init.body instanceof FormData) {
      const out = {};
      init.body.forEach((v, k) => { out[k] = v; });
      return out;
    }
    return init.body || {};
  };

  const mapProfile = (d) => ({
    name: d.student_name || 'Student',
    index_number: d.index_number || '',
    generated_id: d.generated_id || '',
    program: d.program || '',
    level: d.level || '',
    semester: d.semester || '',
    institution: d.institution_name || '',
    photo_url: d.profile_photo || '',
    course_count: d.course_count || 0,
  });

  const mapLive = (d) => {
    const s = (d && d.session) ? d.session : d;
    if (!s || !s.session_id) return { active: false };
    return {
      active: true,
      session_id: s.session_id,
      class_name: s.class_name || '',
      course_code: s.course_code || '',
      lecturer: s.lecturer_name || '',
      date: s.session_date || '',
      time: s.start_time || '',
      session_num: s.session_number || 1,
      qr_expires_at: s.qr_code_expires_at || null,
      location: s.location_name || '',
    };
  };

  window.fetch = async function(input, init){
    const urlText = typeof input === 'string' ? input : (input && input.url ? input.url : '');
    if (!urlText) return nativeFetch(input, init);

    const u = new URL(urlText, window.location.href);
    const path = u.pathname.replace(/^\//, '');
    const legacyMatch = path.match(/(?:^|\/)(api\/(?:student\/.*|logout\.php))$/i);
    const legacyPath = legacyMatch ? legacyMatch[1].toLowerCase() : '';
    const method = ((init && init.method) || 'GET').toUpperCase();

    const isLegacy = legacyPath === 'api/logout.php' || legacyPath.startsWith('api/student/');
    if (!isLegacy) return nativeFetch(input, init);

    try {
      const params = toObject(u.searchParams);
      const body = parseBody(init);

      if (legacyPath === 'api/logout.php') {
        StudentAPI.store.clear();
        return jsonResponse({ success: true }, 200);
      }

      if (legacyPath === 'api/student/profile.php') {
        const d = await StudentAPI.profile.getMe();
        return jsonResponse(mapProfile(d), 200);
      }

      if (legacyPath === 'api/student/courses.php') {
        const rows = await StudentAPI.classes.list();
        const list = (Array.isArray(rows) ? rows : (rows.classes || rows.data || [])).map(c => ({
          class_id: c.class_id,
          class_name: c.class_name,
          course_code: c.course_code,
          lecturer_name: c.lecturer_name,
          attendance_pct: Math.round(parseFloat(c.attendance_rate || c.attendance_percentage || 0)),
        }));
        return jsonResponse(list, 200);
      }

      if (legacyPath === 'api/student/live-session.php') {
        try {
          const d = await StudentAPI.session.getActive();
          return jsonResponse(mapLive(d), 200);
        } catch (err) {
          if (err && err.status === 404) return jsonResponse({ active: false }, 200);
          throw err;
        }
      }

      if (legacyPath === 'api/student/checkin.php' && method === 'POST') {
        const payload = {
          session_id: body.session_id,
          latitude: body.latitude,
          longitude: body.longitude,
          manual_code: body.method === 'manual_code' ? body.value : body.manual_code,
          qr_code: body.method === 'qr_code' ? body.value : body.qr_code,
          password: body.password,
        };
        const d = await StudentAPI.checkin.submit(payload);
        return jsonResponse({
          success: true,
          check_in_time: d.check_in_time,
          gps_result: d.distance_meters != null ? (Math.round(d.distance_meters) + 'm') : 'Recorded',
          ...d,
        }, 200);
      }

      if (legacyPath === 'api/student/attendance.php') {
        const d = await StudentAPI.attendance.list(params);
        const rows = Array.isArray(d) ? d : (d.records || d.data || []);
        const onlyAbsent = String(params.absent_only || '') === '1';
        const filtered = onlyAbsent ? rows.filter(r => String(r.status || '').toLowerCase() === 'absent') : rows;
        return jsonResponse(filtered, 200);
      }

      if (legacyPath === 'api/student/requests.php') {
        const d = await StudentAPI.override.history(params);
        const rows = Array.isArray(d) ? d : (d.requests || d.records || d.data || []);
        return jsonResponse(rows, 200);
      }

      if (legacyPath === 'api/student/submit-request.php' && method === 'POST') {
        const d = await StudentAPI.override.submit(body);
        return jsonResponse({ success: true, ...d }, 200);
      }

      if (legacyPath === 'api/student/update-profile.php' && method === 'POST') {
        const payload = {};
        if (Object.prototype.hasOwnProperty.call(body, 'phone')) {
          payload.phone = body.phone;
        }

        // Current backend profile endpoint supports phone/profile_photo via PATCH.
        // Legacy form fields like name/email/photo are ignored here to avoid 404.
        if (Object.keys(payload).length) {
          await StudentAPI.profile.update(payload);
        }

        return jsonResponse({
          success: true,
          message: 'Profile update request processed.',
        }, 200);
      }

      if (legacyPath === 'api/student/devices.php' && method === 'GET') {
        const sec = await StudentAPI.profile.getSecurity();
        const currentHash = (StudentAPI.getDeviceUUID && StudentAPI.getDeviceUUID()) || '';
        const rows = Array.isArray(sec?.devices) ? sec.devices : [];
        const list = rows
          .filter(d => String(d.status || 'active') === 'active')
          .map(d => ({
            device_id: d.device_id,
            device_name: d.device_name || d.browser || 'Unknown device',
            browser: d.browser || '',
            registered_at: d.first_login || '',
            last_active: d.last_login || '',
            is_current: !!currentHash && String(d.device_hash || '') === String(currentHash),
          }));
        return jsonResponse(list, 200);
      }

      if (legacyPath === 'api/student/revoke-device.php' && method === 'POST') {
        const did = Number(body.device_id || 0);
        if (!did) return jsonResponse({ success: false, message: 'device_id is required' }, 422);
        const d = await StudentAPI.profile.removeDevice(did);
        return jsonResponse({
          success: d?.success !== false,
          message: d?.message || 'Device revoked',
        }, 200);
      }

      if (legacyPath === 'api/student/notifications-prefs.php') {
        const PREF_KEY = 'ec_student_notification_prefs';
        const defaults = {
          session_alerts: true,
          attendance_receipts: true,
          override_updates: true,
          course_messages: true,
          low_attendance_warning: true,
          system_announcements: true,
        };

        if (method === 'GET') {
          let saved = {};
          try { saved = JSON.parse(localStorage.getItem(PREF_KEY) || '{}') || {}; } catch (_) {}
          return jsonResponse({ ...defaults, ...saved }, 200);
        }

        if (method === 'POST') {
          const next = { ...defaults };
          Object.keys(defaults).forEach(k => {
            if (Object.prototype.hasOwnProperty.call(body, k)) next[k] = !!body[k];
          });
          localStorage.setItem(PREF_KEY, JSON.stringify(next));
          return jsonResponse({ success: true, message: 'Preferences saved' }, 200);
        }
      }

      if (legacyPath === 'api/student/stats.php') {
        const [attendanceRes, classesRes, overrideRes, unreadRes] = await Promise.all([
          StudentAPI.attendance.stats(),
          StudentAPI.classes.list(),
          StudentAPI.override.history({ limit: 100 }),
          StudentAPI.notifications.unreadCount(),
        ]);
        const classes = Array.isArray(classesRes) ? classesRes : (classesRes.classes || classesRes.data || []);
        const requests = Array.isArray(overrideRes) ? overrideRes : (overrideRes.requests || overrideRes.records || overrideRes.data || []);
        const pending = requests.filter(r => String(r.status || '').toLowerCase() === 'pending').length;
        return jsonResponse({
          attendance_pct: Math.round(attendanceRes.rate || 0),
          course_count: classes.length,
          pending_requests: pending,
          unread_messages: Number(unreadRes.count || 0),
        }, 200);
      }

      if (legacyPath === 'api/student/activity.php') {
        const d = await StudentAPI.checkin.getHistory({ limit: 5 });
        const rows = Array.isArray(d) ? d : (d.records || d.data || []);
        const out = rows.map(r => ({
          type: 'checkin',
          title: r.class_name || 'Class check-in',
          subtitle: (r.status || 'present').toUpperCase(),
          time: r.check_in_time ? new Date(r.check_in_time).toLocaleString() : '',
        }));
        return jsonResponse(out, 200);
      }

      // Generic fallback: api/student/foo.php -> student/foo
      const endpoint = legacyPath.replace(/^api\/student\//, 'student/').replace(/\.php$/, '');
      let result;
      if (method === 'GET') {
        result = await StudentAPI.get(endpoint, params);
      } else if (method === 'PATCH') {
        result = await StudentAPI.patch(endpoint, body);
      } else {
        result = await StudentAPI.post(endpoint, body);
      }
      return jsonResponse(result, 200);

    } catch (err) {
      const status = err && err.status ? err.status : 400;
      const message = err && err.message ? err.message : 'Request failed';
      return jsonResponse({ success: false, error: message, message }, status);
    }
  };
})();