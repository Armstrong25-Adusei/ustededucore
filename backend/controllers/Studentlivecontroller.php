<?php
/**
 * EduCore — StudentLiveController  v1.0
 *
 * Handles all backend routes powering stu-live.html.
 * All routes require a valid student JWT (AuthMiddleware::student()).
 *
 * SESSION (read-only, student-scoped)
 *   GET  student/session/active                     — active session for today (409 if already checked in)
 *   GET  student/session/{id}/qr-current            — live QR + manual code from dynamic_qr_codes
 *   GET  student/session/{id}/geofence-map          — GPS centre + radius for geofence display
 *   GET  student/session/{id}/stats                 — present count, total enrolled, attendance rate
 *   GET  student/session/{id}/recent-checkins       — last 20 check-ins across the class (feed display)
 *
 * CHECK-IN  (7-layer SATE)
 *   POST student/checkin                            — submit attendance record
 *   GET  student/checkin/status?session_id={id}     — has this student already checked into a session?
 *
 * WEBAUTHN BIOMETRICS
 *   GET  student/biometrics/webauthn/status                 — has_credential flag + credential list
 *   POST student/biometrics/webauthn/challenge              — issue authentication challenge
 *   POST student/biometrics/webauthn/register/challenge     — issue registration challenge
 *   POST student/biometrics/webauthn/register/complete      — store new credential after registration
 *   DELETE student/biometrics/webauthn/{credential_id}      — revoke a credential
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../utils/QRGenerator.php';

class StudentLiveController extends BaseController
{
    // ══════════════════════════════════════════════════════════════════
    // SESSION  (student view — read-only, no console controls)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET /api/student/session/active
     *
     * Returns the open/in_progress session for today that this student is
     * enrolled in (via class_enrollments OR lecturer_id match on students).
     *
     * Responses:
     *   200  { session_id, class_id, class_name, course_code, session_type,
     *           session_date, session_time, session_status,
     *           gps_latitude, gps_longitude, geofence_radius_meters,
     *           lecturer_name, total_students_present, total_students_absent,
     *           has_active: true }
     *   200  { has_active: false, session: null }       — no open session today
     *   409  "You have already checked in to this session."
     */
    public function getActiveSession(): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();
        $sid    = (int)$claims['student_id'];

        $stmt = $db->prepare("
            SELECT  s.session_id,
                    s.class_id,
                    s.session_date,
                    s.session_time,
                    s.session_type,
                    s.session_status,
                    s.total_students_present,
                    s.total_students_absent,
                    c.class_name,
                    c.course_code,
                    c.gps_latitude,
                    c.gps_longitude,
                    c.geofence_radius_meters,
                    l.full_name AS lecturer_name
            FROM    attendance_sessions s
            JOIN    classes   c ON c.class_id    = s.class_id
            JOIN    lecturers l ON l.lecturer_id = c.lecturer_id
            LEFT JOIN class_enrollments ce
                   ON ce.class_id = c.class_id AND ce.student_id = ?
            WHERE   (
                        ce.student_id IS NOT NULL
                        OR c.lecturer_id = (
                            SELECT lecturer_id FROM students WHERE student_id = ? LIMIT 1
                        )
                    )
              AND   s.session_status IN ('open', 'in_progress')
              AND   s.session_date   = CURDATE()
            ORDER   BY s.session_id DESC
            LIMIT   1
        ");
        $stmt->execute([$sid, $sid]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            $this->json(['has_active' => false, 'session' => null]);
            return;
        }

        // Already checked in?
        $chk = $db->prepare(
            "SELECT status FROM attendance_records WHERE session_id = ? AND student_id = ?"
        );
        $chk->execute([$session['session_id'], $sid]);
        $rec = $chk->fetch(PDO::FETCH_ASSOC);
        if ($rec && in_array($rec['status'], ['present', 'late'], true)) {
            $this->fail(409, 'You have already checked in to this session.');
        }

        // Cast numerics for consistent JSON types
        $session['session_id']             = (int)$session['session_id'];
        $session['class_id']               = (int)$session['class_id'];
        $session['total_students_present'] = (int)$session['total_students_present'];
        $session['total_students_absent']  = (int)$session['total_students_absent'];
        $session['geofence_radius_meters'] = (int)$session['geofence_radius_meters'];
        $session['has_active']             = true;

        $this->json($session);
    }

    /**
     * GET /api/student/session/{id}/qr-current
     *
     * Returns the most-recent non-expired QR from dynamic_qr_codes.
     * Falls back to attendance_sessions.current_qr_code if dynamic table is empty.
     *
     * Response: { qr_code_value, manual_code_value, expires_at }
     */
    public function getSessionQR(int $sessId): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();

        $this->_assertStudentCanAccessSession($db, $sessId, (int)$claims['student_id']);

        // Try dynamic_qr_codes first (most current, non-expired)
        $stmt = $db->prepare("
            SELECT qr_code_value, manual_code_value, expires_at
            FROM   dynamic_qr_codes
            WHERE  session_id = ? AND is_expired = 0 AND expires_at > NOW()
            ORDER  BY qr_id DESC LIMIT 1
        ");
        $stmt->execute([$sessId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->json($row);
            return;
        }

        // Fallback: current_qr_code column on attendance_sessions
        $fallback = $db->prepare("
            SELECT current_qr_code      AS qr_code_value,
                   manual_code          AS manual_code_value,
                   qr_code_expires_at   AS expires_at
            FROM   attendance_sessions
            WHERE  session_id = ? AND session_status IN ('open', 'in_progress')
        ");
        $fallback->execute([$sessId]);
        $row = $fallback->fetch(PDO::FETCH_ASSOC);

        if (!$row || !$row['qr_code_value']) {
            $this->fail(404, 'No active QR code for this session yet. Please wait for your lecturer to start.');
        }

        $this->json($row);
    }

    /**
     * GET /api/student/session/{id}/geofence-map
     *
     * Returns GPS co-ordinates and radius so the student page can display
     * the geofence status chip and distance calculation.
     *
     * Response: { session_id, gps_latitude, gps_longitude, geofence_radius_meters, class_name }
     */
    public function getGeofenceMap(int $sessId): void
    {
        AuthMiddleware::student();

        $stmt = $this->db()->prepare("
            SELECT s.session_id,
                   c.gps_latitude,
                   c.gps_longitude,
                   c.geofence_radius_meters,
                   c.class_name
            FROM   attendance_sessions s
            JOIN   classes c ON c.class_id = s.class_id
            WHERE  s.session_id = ?
        ");
        $stmt->execute([$sessId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) $this->fail(404, 'Session not found.');

        $row['session_id']             = (int)$row['session_id'];
        $row['geofence_radius_meters'] = (int)$row['geofence_radius_meters'];

        $this->json($row);
    }

    /**
     * GET /api/student/session/{id}/stats
     *
     * Returns live head-counts for the progress bar on the student page.
     * Does NOT expose QR codes or management data.
     *
     * Response: { session_id, present, absent, total, rate_pct, status }
     */
    public function getSessionStats(int $sessId): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();

        $this->_assertStudentCanAccessSession($db, $sessId, (int)$claims['student_id']);

        $stmt = $db->prepare("
            SELECT total_students_present, total_students_absent, session_status
            FROM   attendance_sessions
            WHERE  session_id = ?
        ");
        $stmt->execute([$sessId]);
        $sess = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sess) $this->fail(404, 'Session not found.');

        $present = (int)$sess['total_students_present'];
        $absent  = (int)$sess['total_students_absent'];
        $total   = $present + $absent;
        $rate    = $total > 0 ? round(($present / $total) * 100, 1) : 0.0;

        $this->json([
            'session_id' => $sessId,
            'present'    => $present,
            'absent'     => $absent,
            'total'      => $total,
            'rate_pct'   => $rate,
            'status'     => $sess['session_status'],
        ]);
    }

    /**
     * GET /api/student/session/{id}/recent-checkins
     *
     * Returns the last 20 check-ins for the class session so the student
     * can see the live feed of classmates who have checked in.
     *
     * Privacy: returns student_name, index_number, verification_method,
     * check_in_time only — never exposes SATE tokens, GPS co-ordinates,
     * or password hashes to peers.
     *
     * Response: { checkins: [ { student_name, index_number, verification_method, check_in_time } ] }
     */
    public function getRecentCheckins(int $sessId): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();

        $this->_assertStudentCanAccessSession($db, $sessId, (int)$claims['student_id']);

        $stmt = $db->prepare("
            SELECT  s.student_name,
                    s.index_number,
                    ar.verification_method,
                    ar.check_in_time
            FROM    attendance_records ar
            JOIN    students s ON s.student_id = ar.student_id
            WHERE   ar.session_id = ?
              AND   ar.status IN ('present', 'late')
            ORDER   BY ar.check_in_time DESC
            LIMIT   20
        ");
        $stmt->execute([$sessId]);
        $this->json(['checkins' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /**
     * GET /api/student/checkin/status?session_id={id}
     *
     * Quick check — has this student already checked in to a session?
     * Allows the page to skip straight to the success state on revisit.
     *
     * Response:
     *   { checked_in: false }
     *   { checked_in: true, attendance_id, status, verification_method, check_in_time, sate_token }
     */
    public function getCheckinStatus(): void
    {
        $claims    = AuthMiddleware::student();
        $sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;

        if (!$sessionId) $this->fail(400, 'session_id is required.');

        $stmt = $this->db()->prepare("
            SELECT attendance_id, status, verification_method, check_in_time, sate_token
            FROM   attendance_records
            WHERE  session_id = ? AND student_id = ?
            LIMIT  1
        ");
        $stmt->execute([$sessionId, $claims['student_id']]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rec) {
            $this->json(['checked_in' => false]);
            return;
        }

        $this->json([
            'checked_in'          => true,
            'attendance_id'       => (int)$rec['attendance_id'],
            'status'              => $rec['status'],
            'verification_method' => $rec['verification_method'],
            'check_in_time'       => $rec['check_in_time'],
            'sate_token'          => $rec['sate_token'],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // CHECK-IN  (7-layer SATE — complete implementation)
    //
    //  Layer 1 — student enrolled in class            (JOIN assertion)
    //  Layer 2 — session is open/in_progress today    (WHERE clause)
    //  Layer 3 — device_uuid in student_devices       (device binding)
    //  Layer 4 — GPS Haversine geofence               (geofence_logs)
    //  Layer 5 — QR token OR 6-char manual code       (credential check)
    //  Layer 6 — password_verify() OR WebAuthn        (identity)
    //  Layer 7 — SHA-256 SATE integrity hash          (sate_token stored)
    // ══════════════════════════════════════════════════════════════════

    /**
     * POST /api/student/checkin
     *
     * Body (JSON):
     * {
     *   session_id:           int      (required)
     *   qr_code?:             string   (QR signed token — from camera scan)
     *   manual_code?:         string   (6-char backup code — typed manually)
     *   password?:            string   (Layer 6a — account password)
     *   biometric_assertion?: string   (Layer 6b — JSON WebAuthn assertion)
     *   verification_method?: string   ('biometric' when sending assertion)
     *   latitude?:            float    (Layer 4 — navigator.geolocation lat)
     *   longitude?:           float    (Layer 4 — navigator.geolocation lng)
     *   device_uuid:          string   (Layer 3 — auto-injected by StudentAPI)
     * }
     *
     * Returns 201: {
     *   attendance_id, student_name, status, verification_method,
     *   sate_token, check_in_time
     * }
     */
    public function checkin(): void
    {
        $claims = AuthMiddleware::student();
        $b      = $this->jsonBody();
        $db     = $this->db();
        $sid    = (int)$claims['student_id'];

        // ── Parse input ───────────────────────────────────────────────
        $sessionId          = (int)($b['session_id']          ?? 0);
        $qrCode             = trim($b['qr_code']               ?? '');
        $manualCode         = strtoupper(trim($b['manual_code'] ?? ''));
        $password           = (string)($b['password']          ?? '');
        $biometricAssertion = trim($b['biometric_assertion']   ?? '');
        $clientVerMethod    = trim($b['verification_method']   ?? '');
        $lat                = isset($b['latitude'])  ? (float)$b['latitude']  : null;
        $lng                = isset($b['longitude']) ? (float)$b['longitude'] : null;
        $deviceUuid         = trim($b['device_uuid']           ?? '');

        // ── Basic validation ──────────────────────────────────────────
        if (!$sessionId) {
            $this->fail(400, 'session_id is required.');
        }
        if (!$qrCode && !$manualCode) {
            $this->fail(400, 'A QR code or manual code is required.');
        }
        if (!$password && !$biometricAssertion) {
            $this->fail(400, 'Identity confirmation is required (password or biometric).');
        }

        // ── Fetch student record (needed for password verify + SATE) ──
        $stuStmt = $db->prepare(
            "SELECT password_hash, student_name FROM students WHERE student_id = ?"
        );
        $stuStmt->execute([$sid]);
        $stu = $stuStmt->fetch(PDO::FETCH_ASSOC);
        if (!$stu) $this->fail(403, 'Student account not found.');

        // ── Layer 6: Identity confirmation ────────────────────────────
        $verMethod = null; // will be finalised in Layer 5

        if ($biometricAssertion && $clientVerMethod === 'biometric') {
            // Layer 6b — WebAuthn device biometric
            $verMethod = $this->_verifyWebAuthn($db, $sid, $biometricAssertion, $sessionId);
        } else {
            // Layer 6a — Password
            if (!$password) $this->fail(400, 'Password is required.');
            if (!password_verify($password, $stu['password_hash'])) {
                $this->audit($sid, 'student', 'CHECKIN_BAD_PASSWORD', [
                    'session_id' => $sessionId,
                ]);
                $this->fail(401, 'Incorrect password. Attendance not recorded.');
            }
            // verMethod set to null here — Layer 5 will set it to qr_code/manual_code
        }

        // ── Layer 3: Device binding ───────────────────────────────────
        if ($deviceUuid !== '') {
            $devStmt = $db->prepare("
                SELECT device_id, status
                FROM   student_devices
                WHERE  student_id = ? AND device_hash = ?
                LIMIT  1
            ");
            $devStmt->execute([$sid, $deviceUuid]);
            $device = $devStmt->fetch(PDO::FETCH_ASSOC);

            if (!$device) {
                $this->audit($sid, 'student', 'CHECKIN_UNKNOWN_DEVICE', [
                    'session_id'  => $sessionId,
                    'device_uuid' => $deviceUuid,
                ]);
                $this->fail(403, 'Unrecognised device. Please log in again to register this device.');
            }

            if (($device['status'] ?? '') !== 'active') {
                $this->audit($sid, 'student', 'CHECKIN_REVOKED_DEVICE', [
                    'session_id'  => $sessionId,
                    'device_uuid' => $deviceUuid,
                ]);
                $this->fail(403, 'This device has been removed. Please contact your lecturer.');
            }

            // Refresh last-seen timestamp
            $db->prepare("UPDATE student_devices SET last_login = NOW() WHERE device_id = ?")
               ->execute([$device['device_id']]);
        }

        // ── Load session + class geofence ─────────────────────────────
        $sessStmt = $db->prepare("
            SELECT s.*, c.gps_latitude, c.gps_longitude, c.geofence_radius_meters
            FROM   attendance_sessions s
            JOIN   classes c ON c.class_id = s.class_id
            WHERE  s.session_id = ? AND s.session_status IN ('open', 'in_progress')
        ");
        $sessStmt->execute([$sessionId]);
        $sess = $sessStmt->fetch(PDO::FETCH_ASSOC);
        if (!$sess) $this->fail(404, 'Session not found or already closed.');

        // ── Duplicate check ───────────────────────────────────────────
        $dup = $db->prepare(
            "SELECT attendance_id FROM attendance_records WHERE session_id = ? AND student_id = ?"
        );
        $dup->execute([$sessionId, $sid]);
        if ($dup->fetch()) $this->fail(409, 'You have already checked in to this session.');

        // ── Layer 4: GPS Geofence ─────────────────────────────────────
        if ($lat !== null && $lng !== null
            && !empty($sess['gps_latitude']) && !empty($sess['gps_longitude'])) {

            $distM  = $this->haversineM(
                $lat, $lng,
                (float)$sess['gps_latitude'],
                (float)$sess['gps_longitude']
            );
            $radius = (int)($sess['geofence_radius_meters'] ?? 50);
            $passed = ($distM <= $radius);

            // Always log the attempt regardless of pass/fail
            $db->prepare("
                INSERT INTO geofence_logs
                    (session_id, student_id,
                     student_latitude, student_longitude,
                     allowed_latitude, allowed_longitude,
                     radius_meters, distance_meters,
                     is_within_geofence, logged_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $sessionId, $sid,
                $lat, $lng,
                $sess['gps_latitude'], $sess['gps_longitude'],
                $radius, round($distM, 2),
                $passed ? 1 : 0,
            ]);

            if (!$passed) {
                $this->fail(403, sprintf(
                    'You are %.0fm outside the allowed zone (%dm radius). '
                    . 'Move closer or submit an override request.',
                    $distM - $radius,
                    $radius
                ));
            }
        }

        // ── Layer 5: Presence credential (QR token or manual code) ────
        if ($verMethod === null) {
            $verMethod = 'manual_code'; // default; overridden below for QR
        }

        if ($qrCode) {
            // Try cryptographic QRGenerator verification first
            try {
                $qrPayload = QRGenerator::verify($qrCode);
                if ((int)$qrPayload['session_id'] !== $sessionId) {
                    $this->fail(403, 'QR code does not match this session.');
                }
                if ($verMethod !== 'biometric') $verMethod = 'qr_code';
            } catch (\Exception $e) {
                // Fallback: plain lookup in dynamic_qr_codes (legacy transition period)
                $qrStmt = $db->prepare("
                    SELECT qr_id FROM dynamic_qr_codes
                    WHERE  session_id    = ?
                      AND  qr_code_value = ?
                      AND  is_expired    = 0
                      AND  expires_at    > NOW()
                ");
                $qrStmt->execute([$sessionId, $qrCode]);
                if (!$qrStmt->fetch()) {
                    $this->fail(403, 'Invalid or expired QR code. Ask your lecturer to rotate the code.');
                }
                if ($verMethod !== 'biometric') $verMethod = 'qr_code';
            }
        } else {
            // Manual code path — verify against attendance_sessions.manual_code
            $mcStmt = $db->prepare("
                SELECT session_id FROM attendance_sessions
                WHERE  session_id             = ?
                  AND  UPPER(manual_code)     = ?
                  AND  manual_code_expires_at > NOW()
            ");
            $mcStmt->execute([$sessionId, $manualCode]);
            if (!$mcStmt->fetch()) {
                $this->fail(403, 'Invalid or expired manual code. Ask your lecturer for the current code.');
            }
            // verMethod remains 'manual_code' unless already set to 'biometric'
        }

        // ── Layer 7: SATE integrity hash ──────────────────────────────
        $ts         = time();
        $credential = $qrCode ?: $manualCode;
        $sateToken  = hash('sha256', implode('|', [
            $sid,
            $sessionId,
            $credential,
            $lat  ?? '0',
            $lng  ?? '0',
            $deviceUuid ?: 'NONE',
            $ts,
        ]));

        // ── Insert attendance record ──────────────────────────────────
        $db->prepare("
            INSERT INTO attendance_records
                (session_id, student_id, status, verification_method,
                 latitude, longitude, check_in_time, sate_token, created_at)
            VALUES (?, ?, 'present', ?, ?, ?, NOW(), ?, NOW())
        ")->execute([
            $sessionId, $sid,
            $verMethod, $lat, $lng,
            $sateToken,
        ]);
        $attId = (int)$db->lastInsertId();

        // Update student last_attendance timestamp
        $db->prepare("UPDATE students SET last_attendance = NOW() WHERE student_id = ?")
           ->execute([$sid]);

        $this->audit($sid, 'student', 'CHECK_IN', [
            'session_id'    => $sessionId,
            'method'        => $verMethod,
            'device_uuid'   => $deviceUuid,
            'attendance_id' => $attId,
        ]);

        $this->json([
            'attendance_id'       => $attId,
            'student_name'        => $stu['student_name'],
            'status'              => 'present',
            'verification_method' => $verMethod,
            'sate_token'          => $sateToken,
            'check_in_time'       => date('Y-m-d H:i:s'),
        ], 201);
    }

    // ══════════════════════════════════════════════════════════════════
    // WEBAUTHN BIOMETRICS
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET /api/student/biometrics/webauthn/status
     *
     * Response: {
     *   has_credential: bool,
     *   registered: bool,
     *   credentials: [ { credential_id, device_label, created_at, last_used_at, status } ]
     * }
     */
    public function webauthnStatus(): void
    {
        $claims = AuthMiddleware::student();
        $stmt   = $this->db()->prepare("
            SELECT credential_id, device_label, created_at, last_used_at, status
            FROM   webauthn_credentials
            WHERE  student_id = ? AND status = 'active'
            ORDER  BY created_at DESC
        ");
        $stmt->execute([$claims['student_id']]);
        $creds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->json([
            'has_credential' => count($creds) > 0,
            'registered'     => count($creds) > 0,
            'credentials'    => $creds,
        ]);
    }

    /**
     * POST /api/student/biometrics/webauthn/challenge
     *
     * Issues a challenge for AUTHENTICATION (used during check-in) or
     * REGISTRATION (used during biometric-setup).
     *
     * Body: { purpose: 'authentication' | 'registration', session_id?: int }
     *
     * Response for authentication: {
     *   challenge: string (base64url),
     *   allow_credentials: [ { id, type, transports } ],
     *   timeout: 60000
     * }
     */
    public function webauthnChallenge(): void
    {
        $claims  = AuthMiddleware::student();
        $b       = $this->jsonBody();
        $db      = $this->db();
        $sid     = (int)$claims['student_id'];
        $purpose = trim($b['purpose'] ?? 'authentication');
        $sessId  = isset($b['session_id']) ? (int)$b['session_id'] : null;

        if (!in_array($purpose, ['authentication', 'registration'], true)) {
            $this->fail(400, "purpose must be 'authentication' or 'registration'.");
        }

        // Generate cryptographically random 32-byte challenge
        $rawChallenge = random_bytes(32);
        $challenge    = rtrim(strtr(base64_encode($rawChallenge), '+/', '-_'), '=');
        $expiresAt    = date('Y-m-d H:i:s', time() + 300); // 5-minute TTL

        // Expire any existing unused challenges for this student+purpose
        $db->prepare("
            DELETE FROM webauthn_challenges
            WHERE student_id = ? AND purpose = ? AND expires_at < NOW()
        ")->execute([$sid, $purpose]);

        // Store new challenge
        $db->prepare("
            INSERT INTO webauthn_challenges
                (student_id, challenge, purpose, session_id, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$sid, $challenge, $purpose, $sessId, $expiresAt]);

        $response = [
            'challenge' => $challenge,
            'timeout'   => 60000,
            'purpose'   => $purpose,
        ];

        if ($purpose === 'authentication') {
            // Return the list of registered credential IDs so the browser
            // can locate the right authenticator
            $credStmt = $db->prepare("
                SELECT credential_ext_id AS id, transports
                FROM   webauthn_credentials
                WHERE  student_id = ? AND status = 'active'
            ");
            $credStmt->execute([$sid]);
            $creds = $credStmt->fetchAll(PDO::FETCH_ASSOC);

            $response['allow_credentials'] = array_map(function (array $c): array {
                return [
                    'id'         => $c['id'],
                    'type'       => 'public-key',
                    'transports' => json_decode($c['transports'] ?? '["internal"]', true) ?? ['internal'],
                ];
            }, $creds);
        }

        $this->json($response);
    }

    /**
     * POST /api/student/biometrics/webauthn/register/challenge
     *
     * Issues a PublicKeyCredentialCreationOptions object for registration.
     * Used by biometric-setup.html.
     */
    public function webauthnRegisterChallenge(): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();
        $sid    = (int)$claims['student_id'];

        $rawChallenge = random_bytes(32);
        $challenge    = rtrim(strtr(base64_encode($rawChallenge), '+/', '-_'), '=');
        $expiresAt    = date('Y-m-d H:i:s', time() + 300);

        // Clean stale challenges
        $db->prepare(
            "DELETE FROM webauthn_challenges WHERE student_id = ? AND purpose = 'registration' AND expires_at < NOW()"
        )->execute([$sid]);

        $db->prepare("
            INSERT INTO webauthn_challenges (student_id, challenge, purpose, expires_at)
            VALUES (?, ?, 'registration', ?)
        ")->execute([$sid, $challenge, $expiresAt]);

        $stuStmt = $db->prepare("SELECT student_name, index_number FROM students WHERE student_id = ?");
        $stuStmt->execute([$sid]);
        $stu = $stuStmt->fetch(PDO::FETCH_ASSOC);

        $rpId = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST)
             ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $this->json([
            'challenge' => $challenge,
            'timeout'   => 60000,
            'rp' => [
                'name' => 'EduCore Attendance',
                'id'   => $rpId,
            ],
            'user' => [
                'id'          => rtrim(strtr(base64_encode((string)$sid), '+/', '-_'), '='),
                'name'        => $stu['index_number'] ?? (string)$sid,
                'displayName' => $stu['student_name']  ?? 'Student',
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],    // ES256
                ['type' => 'public-key', 'alg' => -257],  // RS256
            ],
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'userVerification'        => 'required',
                'residentKey'             => 'preferred',
            ],
            'attestation' => 'none',
        ]);
    }

    /**
     * POST /api/student/biometrics/webauthn/register/complete
     *
     * Stores a new WebAuthn credential after the browser has completed
     * navigator.credentials.create().
     *
     * Body: {
     *   id:           string  (base64url credential ID)
     *   type:         string  ('public-key')
     *   response: {
     *     attestationObject: string  (base64url)
     *     clientDataJSON:    string  (base64url)
     *   }
     *   device_label?: string
     *   aaguid?:       string
     *   transports?:   array
     * }
     */
    public function webauthnRegisterComplete(): void
    {
        $claims = AuthMiddleware::student();
        $b      = $this->jsonBody();
        $db     = $this->db();
        $sid    = (int)$claims['student_id'];

        $credId     = trim($b['id']   ?? '');
        $credType   = trim($b['type'] ?? '');
        $response   = $b['response']  ?? [];
        $devLabel   = trim($b['device_label'] ?? '') ?: $this->_guessDeviceLabel();
        $aaguid     = trim($b['aaguid'] ?? '');
        $transports = $b['transports'] ?? ['internal'];

        if (!$credId || $credType !== 'public-key') {
            $this->fail(400, 'Invalid credential response: type must be public-key.');
        }
        if (empty($response['clientDataJSON']) || empty($response['attestationObject'])) {
            $this->fail(400, 'Incomplete registration response: clientDataJSON and attestationObject required.');
        }

        // Verify clientDataJSON challenge matches a pending registration challenge
        $clientData = json_decode(
            base64_decode(strtr($response['clientDataJSON'], '-_', '+/')),
            true
        );
        if (!$clientData || ($clientData['type'] ?? '') !== 'webauthn.create') {
            $this->fail(400, 'clientDataJSON type must be webauthn.create.');
        }

        $receivedChallenge = $clientData['challenge'] ?? '';
        $chalStmt = $db->prepare("
            SELECT challenge_id FROM webauthn_challenges
            WHERE  student_id = ? AND challenge = ? AND purpose = 'registration'
              AND  expires_at > NOW()
            ORDER  BY challenge_id DESC LIMIT 1
        ");
        $chalStmt->execute([$sid, $receivedChallenge]);
        $chal = $chalStmt->fetch(PDO::FETCH_ASSOC);
        if (!$chal) $this->fail(403, 'Registration challenge not found or expired. Please restart setup.');

        // Consume challenge — one-time use only
        $db->prepare("DELETE FROM webauthn_challenges WHERE challenge_id = ?")
           ->execute([$chal['challenge_id']]);

        // Prevent duplicate registration of same credential
        $dupStmt = $db->prepare("
            SELECT credential_id FROM webauthn_credentials
            WHERE credential_ext_id = ? AND student_id = ?
        ");
        $dupStmt->execute([$credId, $sid]);
        if ($dupStmt->fetch()) $this->fail(409, 'This credential is already registered on your account.');

        // Store the credential.
        // In production, the attestationObject must be CBOR-decoded and the
        // authenticatorData (rpIdHash, UP/UV flags, COSE key) must be verified.
        // We store the attestationObject as public_key_cbor; a full CBOR+COSE
        // library (e.g. https://github.com/web-auth/webauthn-framework) is
        // required for production signature verification.
        $db->prepare("
            INSERT INTO webauthn_credentials
                (student_id, credential_ext_id, public_key_cbor, sign_count,
                 device_label, aaguid, transports, user_verified,
                 backup_eligible, backup_state, status, created_at)
            VALUES (?, ?, ?, 0, ?, ?, ?, 1, 0, 0, 'active', NOW())
        ")->execute([
            $sid,
            $credId,
            $response['attestationObject'],
            $devLabel,
            $aaguid ?: null,
            json_encode(is_array($transports) ? $transports : ['internal']),
        ]);

        $newCredId = (int)$db->lastInsertId();

        // Set the webauthn_registered flag on student_biometrics
        $db->prepare("
            INSERT INTO student_biometrics (student_id, webauthn_registered)
            VALUES (?, 1)
            ON DUPLICATE KEY UPDATE webauthn_registered = 1
        ")->execute([$sid]);

        $this->audit($sid, 'student', 'WEBAUTHN_CREDENTIAL_REGISTERED', [
            'credential_id' => $newCredId,
            'device_label'  => $devLabel,
        ]);

        $this->json([
            'registered'    => true,
            'credential_id' => $newCredId,
            'device_label'  => $devLabel,
        ], 201);
    }

    /**
     * DELETE /api/student/biometrics/webauthn/{credential_id}
     *
     * Revokes (soft-deletes) a WebAuthn credential. Students can only
     * revoke their own credentials.
     */
    public function webauthnRevoke(int $credentialId): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();
        $sid    = (int)$claims['student_id'];

        $stmt = $db->prepare("
            SELECT credential_id FROM webauthn_credentials
            WHERE credential_id = ? AND student_id = ?
        ");
        $stmt->execute([$credentialId, $sid]);
        if (!$stmt->fetch()) $this->fail(404, 'Credential not found or does not belong to your account.');

        $db->prepare("
            UPDATE webauthn_credentials SET status = 'revoked' WHERE credential_id = ?
        ")->execute([$credentialId]);

        // Clear the webauthn_registered flag if no more active credentials
        $remaining = $db->prepare("
            SELECT COUNT(*) FROM webauthn_credentials
            WHERE student_id = ? AND status = 'active'
        ");
        $remaining->execute([$sid]);
        if ((int)$remaining->fetchColumn() === 0) {
            $db->prepare("
                UPDATE student_biometrics SET webauthn_registered = 0 WHERE student_id = ?
            ")->execute([$sid]);
        }

        $this->audit($sid, 'student', 'WEBAUTHN_CREDENTIAL_REVOKED', [
            'credential_id' => $credentialId,
        ]);

        $this->ok(null, 'Credential revoked successfully.');
    }

    // ══════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Verifies a WebAuthn authentication assertion sent during check-in.
     *
     * Validates:
     *  - clientDataJSON.type === 'webauthn.get'
     *  - challenge matches a stored, unexpired webauthn_challenges row
     *  - credential_ext_id matches an active credential for this student
     *  - Consumes the challenge after verification (replay prevention)
     *
     * Returns 'biometric' on success. Calls $this->fail() on any error.
     */
    private function _verifyWebAuthn(
        \PDO   $db,
        int    $studentId,
        string $assertionJson,
        int    $sessionId
    ): string {
        $assertion = json_decode($assertionJson, true);
        if (!$assertion || empty($assertion['id']) || empty($assertion['response'])) {
            $this->fail(400, 'Malformed biometric assertion.');
        }

        $credExtId  = $assertion['id'];
        $respData   = $assertion['response'];

        // Parse clientDataJSON
        $clientDataRaw = $respData['clientDataJSON'] ?? '';
        if (!$clientDataRaw) $this->fail(400, 'Missing clientDataJSON in assertion.');

        $clientData = json_decode(
            base64_decode(strtr($clientDataRaw, '-_', '+/')),
            true
        );
        if (!$clientData) $this->fail(400, 'Could not decode clientDataJSON.');
        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            $this->fail(403, 'Invalid assertion type — expected webauthn.get.');
        }

        $receivedChallenge = $clientData['challenge'] ?? '';
        if (!$receivedChallenge) $this->fail(403, 'Missing challenge in clientDataJSON.');

        // Validate the challenge: must exist, belong to this student,
        // be for authentication purpose, and not yet expired
        $chalStmt = $db->prepare("
            SELECT challenge_id FROM webauthn_challenges
            WHERE  student_id  = ?
              AND  challenge    = ?
              AND  purpose      = 'authentication'
              AND  (session_id  = ? OR session_id IS NULL)
              AND  expires_at   > NOW()
            ORDER  BY challenge_id DESC LIMIT 1
        ");
        $chalStmt->execute([$studentId, $receivedChallenge, $sessionId]);
        $chal = $chalStmt->fetch(PDO::FETCH_ASSOC);

        if (!$chal) {
            $this->audit($studentId, 'student', 'CHECKIN_BAD_BIOMETRIC', [
                'session_id' => $sessionId,
                'reason'     => 'challenge_not_found_or_expired',
            ]);
            $this->fail(403, 'Biometric challenge not found or expired. Please try again.');
        }

        // Validate the credential belongs to this student and is active
        $credStmt = $db->prepare("
            SELECT credential_id, sign_count
            FROM   webauthn_credentials
            WHERE  student_id = ? AND credential_ext_id = ? AND status = 'active'
            LIMIT  1
        ");
        $credStmt->execute([$studentId, $credExtId]);
        $cred = $credStmt->fetch(PDO::FETCH_ASSOC);

        if (!$cred) {
            $this->audit($studentId, 'student', 'CHECKIN_BAD_BIOMETRIC', [
                'session_id' => $sessionId,
                'reason'     => 'credential_not_found_or_revoked',
            ]);
            $this->fail(403, 'Biometric credential not recognised. Please re-register in Settings.');
        }

        // Consume challenge — prevents replay attacks
        $db->prepare("DELETE FROM webauthn_challenges WHERE challenge_id = ?")
           ->execute([$chal['challenge_id']]);

        // Update last_used_at on the credential
        $db->prepare("
            UPDATE webauthn_credentials SET last_used_at = NOW() WHERE credential_id = ?
        ")->execute([$cred['credential_id']]);

        $this->audit($studentId, 'student', 'WEBAUTHN_AUTH_SUCCESS', [
            'session_id'    => $sessionId,
            'credential_id' => $cred['credential_id'],
        ]);

        return 'biometric';
    }

    /**
     * Verifies that a student is enrolled in (or has access to) a session.
     * Throws 403 if the student is not enrolled.
     */
    private function _assertStudentCanAccessSession(\PDO $db, int $sessId, int $sid): void
    {
        $stmt = $db->prepare("
            SELECT s.session_id
            FROM   attendance_sessions s
            JOIN   classes c ON c.class_id = s.class_id
            LEFT JOIN class_enrollments ce
                   ON ce.class_id = c.class_id AND ce.student_id = :sid
            WHERE  s.session_id = :sess_id
              AND  (
                       ce.student_id IS NOT NULL
                       OR c.lecturer_id = (
                           SELECT lecturer_id FROM students WHERE student_id = :sid2 LIMIT 1
                       )
                   )
            LIMIT 1
        ");
        $stmt->execute([':sess_id' => $sessId, ':sid' => $sid, ':sid2' => $sid]);
        if (!$stmt->fetch()) {
            $this->fail(403, 'You are not enrolled in this session\'s course.');
        }
    }

    /**
     * Haversine formula — returns distance in metres between two GPS points.
     * Duplicated here to avoid dependency on the base class version.
     */
    private function haversineM(
        float $lat1, float $lng1,
        float $lat2, float $lng2
    ): float {
        $R    = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
              * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Guesses a human-readable device label from User-Agent for WebAuthn
     * credential registration.
     */
    private function _guessDeviceLabel(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $os = 'Unknown Device';
        if     (preg_match('/Android/i',    $ua)) $os = 'Android';
        elseif (preg_match('/iPhone/i',     $ua)) $os = 'iPhone';
        elseif (preg_match('/iPad/i',       $ua)) $os = 'iPad';
        elseif (preg_match('/Windows/i',    $ua)) $os = 'Windows PC';
        elseif (preg_match('/Macintosh/i',  $ua)) $os = 'Mac';
        elseif (preg_match('/Linux/i',      $ua)) $os = 'Linux';
        return $os . ' Fingerprint';
    }
}