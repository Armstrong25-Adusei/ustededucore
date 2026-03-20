<?php
/**
 * EduCore ÔÇö StudentDataController  v7.0
 *
 * All routes require a valid student JWT.
 * Device checks use student_devices table (students.device_uuid removed).
 *
 * SESSION
 *   GET   /api/student/session/active
 *   GET   /api/student/session/{id}/geofence-map
 *   GET   /api/student/session/{id}/qr-current
 *
 * CHECK-IN
 *   POST  /api/student/checkin
 *   GET   /api/student/checkin/history
 *   GET   /api/student/checkin/receipt/{id}
 *
 * CLASSES
 *   GET   /api/student/classes
 *   GET   /api/student/classes/{id}
 *   GET   /api/student/classes/{id}/attendance
 *
 * ATTENDANCE ANALYTICS
 *   GET   /api/student/attendance
 *   GET   /api/student/attendance/stats
 *   GET   /api/student/attendance/streak
 *   GET   /api/student/attendance/risk
 *
 * OVERRIDE
 *   POST  /api/student/override/request
 *   GET   /api/student/override/history
 *
 * NOTIFICATIONS
 *   GET   /api/student/notifications
 *   GET   /api/student/notifications/unread-count
 *   POST  /api/student/notifications/{id}/read
 *   POST  /api/student/notifications/read-all
 *
 * GEOFENCE LOGS
 *   GET   /api/student/geofence/logs
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../utils/QRGenerator.php';

class StudentDataController extends BaseController
{
    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
    // SESSION
    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ

    /** GET /api/student/session/active */
    public function getActiveSession(): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();

        $stmt = $db->prepare("
            SELECT  s.session_id, s.class_id, s.session_date, s.session_time,
                    s.session_type, s.session_status,
                    s.current_qr_code, s.qr_code_expires_at,
                    s.manual_code, s.manual_code_expires_at,
                    c.class_name, c.course_code,
                    c.gps_latitude, c.gps_longitude, c.geofence_radius_meters,
                    l.full_name AS lecturer_name
            FROM    attendance_sessions s
            JOIN    classes   c ON c.class_id    = s.class_id
            JOIN    lecturers l ON l.lecturer_id = c.lecturer_id
            LEFT JOIN class_enrollments ce
                   ON ce.class_id = c.class_id
                  AND ce.student_id = ?
            WHERE   (
                        ce.student_id IS NOT NULL
                        OR c.lecturer_id = (
                            SELECT lecturer_id FROM students WHERE student_id = ? LIMIT 1
                        )
                    )
              AND   s.session_status IN ('open','in_progress')
              AND   s.session_date   = CURDATE()
            ORDER   BY s.session_id DESC
            LIMIT   1
        ");
        $stmt->execute([$claims['student_id'], $claims['student_id']]);
        $session = $stmt->fetch();
        if (!$session) {
            $this->json([
                'has_active' => false,
                'session'    => null,
            ]);
            return;
        }

        // Already checked in?
        $done = $db->prepare(
            "SELECT status FROM attendance_records WHERE session_id = ? AND student_id = ?"
        );
        $done->execute([$session['session_id'], $claims['student_id']]);
        $rec = $done->fetch();
        if ($rec && $rec['status'] === 'present') {
            $this->fail(409, 'You have already checked in to this session.');
        }

        $this->json($session);
    }

    /** GET /api/student/session/{id}/geofence-map */
    public function getGeofenceMap(int $sessId): void
    {
        AuthMiddleware::student();
        $stmt = $this->db()->prepare("
            SELECT s.session_id, c.gps_latitude, c.gps_longitude,
                   c.geofence_radius_meters, c.class_name
            FROM   attendance_sessions s
            JOIN   classes c ON c.class_id = s.class_id
            WHERE  s.session_id = ?
        ");
        $stmt->execute([$sessId]);
        $row = $stmt->fetch();
        if (!$row) $this->fail(404, 'Session not found.');
        $this->json($row);
    }

    /** GET /api/student/session/{id}/qr-current */
    public function getSessionQR(int $sessId): void
    {
        AuthMiddleware::student();
        $stmt = $this->db()->prepare("
            SELECT qr_code_value, manual_code_value, expires_at
            FROM   dynamic_qr_codes
            WHERE  session_id = ? AND is_expired = 0
            ORDER  BY qr_id DESC LIMIT 1
        ");
        $stmt->execute([$sessId]);
        $row = $stmt->fetch();
        if (!$row) $this->fail(404, 'No active QR for this session.');
        $this->json($row);
    }

    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
    // CHECK-IN  (7-layer SATE)
    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ

    /**
     * POST /api/student/checkin
     *
     * Body: { session_id, qr_code?, manual_code?, password,
     *         latitude?, longitude?, device_uuid }
     *
    * Layer 3 ÔÇö Device check: device hash must exist in student_devices
    *            with status = 'active' for this student.
     * Layer 4 ÔÇö GPS geofence (Haversine, logged to geofence_logs).
     * Layer 5 ÔÇö Presence credential (QR token OR manual_code).
     * Layer 6 ÔÇö Password confirmation.
     * Layer 7 ÔÇö SATE integrity hash.
     */
    public function checkin(): void
    {
        $claims = AuthMiddleware::student();
        $b      = $this->jsonBody();
        $db     = $this->db();

        $sessionId  = (int)($b['session_id']  ?? 0);
        $qrCode     = trim($b['qr_code']      ?? '');
        $manualCode = strtoupper(trim($b['manual_code'] ?? ''));
        $password   = $b['password']   ?? '';
        $lat        = isset($b['latitude'])  ? (float)$b['latitude']  : null;
        $lng        = isset($b['longitude']) ? (float)$b['longitude'] : null;
        $deviceUuid = trim($b['device_uuid'] ?? '');

        if (!$sessionId)              $this->fail(400, 'session_id is required.');
        if (!$qrCode && !$manualCode) $this->fail(400, 'A QR code or manual code is required.');
        if (!$password)               $this->fail(400, 'Password confirmation is required.');

        // ÔöÇÔöÇ Layer 6: Password confirmation ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
        $stuStmt = $db->prepare(
            "SELECT password_hash, student_name FROM students WHERE student_id = ?"
        );
        $stuStmt->execute([$claims['student_id']]);
        $stu = $stuStmt->fetch();

        if (!$stu || !password_verify($password, $stu['password_hash'])) {
            $this->audit($claims['student_id'], 'student', 'CHECKIN_BAD_PASSWORD',
                ['session_id' => $sessionId]);
            $this->fail(401, 'Incorrect password. Attendance not recorded.');
        }

        // ÔöÇÔöÇ Layer 3: Device binding ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
        // The client sends device_uuid, but it is stored as device_hash.
        if ($deviceUuid) {
            $devStmt = $db->prepare("
                SELECT device_id, status
                FROM   student_devices
                WHERE  student_id  = ? AND device_hash = ?
                LIMIT  1
            ");
            $devStmt->execute([$claims['student_id'], $deviceUuid]);
            $device = $devStmt->fetch();

            if (!$device) {
                // Device not registered to this student at all
                $this->audit($claims['student_id'], 'student', 'CHECKIN_UNKNOWN_DEVICE',
                    ['session_id' => $sessionId, 'device_uuid' => $deviceUuid]);
                $this->fail(403, 'Unrecognised device. Please log in again to bind this device first.');
            }

            if (($device['status'] ?? '') !== 'active') {
                $this->audit($claims['student_id'], 'student', 'CHECKIN_REVOKED_DEVICE',
                    ['session_id' => $sessionId, 'device_uuid' => $deviceUuid]);
                $this->fail(403, 'This device has been removed. Please contact your lecturer.');
            }

            // Update last-seen timestamp on the device
            $db->prepare("UPDATE student_devices SET last_login = NOW() WHERE device_id = ?")
               ->execute([$device['device_id']]);
        }

        // Load session + geofence
        $sessStmt = $db->prepare("
            SELECT s.*, c.gps_latitude, c.gps_longitude, c.geofence_radius_meters
            FROM   attendance_sessions s
            JOIN   classes c ON c.class_id = s.class_id
            WHERE  s.session_id = ? AND s.session_status IN ('open','in_progress')
        ");
        $sessStmt->execute([$sessionId]);
        $sess = $sessStmt->fetch();
        if (!$sess) $this->fail(404, 'Session not found or already closed.');

        // Duplicate check
        $dup = $db->prepare(
            "SELECT attendance_id FROM attendance_records WHERE session_id = ? AND student_id = ?"
        );
        $dup->execute([$sessionId, $claims['student_id']]);
        if ($dup->fetch()) $this->fail(409, 'You have already checked in to this session.');

        // ÔöÇÔöÇ Layer 4: Geofence ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
        if ($lat !== null && $lng !== null
            && $sess['gps_latitude'] && $sess['gps_longitude']) {

            $dist   = $this->haversineM(
                $lat, $lng,
                (float)$sess['gps_latitude'],
                (float)$sess['gps_longitude']
            );
            $radius = (int)($sess['geofence_radius_meters'] ?? 50);
            $passed = $dist <= $radius;

            $db->prepare("
                INSERT INTO geofence_logs
                    (session_id, student_id,
                     student_latitude, student_longitude,
                     allowed_latitude, allowed_longitude,
                     radius_meters, distance_meters,
                     is_within_geofence, logged_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $sessionId, $claims['student_id'],
                $lat, $lng,
                $sess['gps_latitude'], $sess['gps_longitude'],
                $radius, round($dist, 2),
                $passed ? 1 : 0,
            ]);

            if (!$passed) {
                $this->fail(403, sprintf(
                    'You are %.0fm outside the allowed zone (%dm radius). ' .
                    'Move closer or submit an override request.',
                    $dist - $radius, $radius
                ));
            }
        }

        // ÔöÇÔöÇ Layer 5: Presence credential ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
        $verMethod = 'manual_code';
        if ($qrCode) {
            // Verify QR code using cryptographic signature
            try {
                $payload = QRGenerator::verify($qrCode);
                
                // Validate payload matches current session
                if ((int)$payload['session_id'] !== $sessionId) {
                    $this->fail(403, 'QR code does not match this session.');
                }
                
                $verMethod = 'qr_code';
            } catch (Exception $e) {
                // Log the error for debugging
                error_log('QR verification error: ' . $e->getMessage());
                
                // Allow database fallback for transition period
                $qrStmt = $db->prepare("
                    SELECT qr_id FROM dynamic_qr_codes
                    WHERE  session_id    = ?
                      AND  qr_code_value = ?
                      AND  is_expired    = 0
                      AND  expires_at    > NOW()
                ");
                $qrStmt->execute([$sessionId, $qrCode]);
                if (!$qrStmt->fetch()) {
                    $this->fail(403, 'Invalid or expired QR code: ' . $e->getMessage());
                }
                $verMethod = 'qr_code';
            }
        } else {
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
        }

        // ÔöÇÔöÇ Layer 7: SATE token ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
        $ts        = time();
        $sateToken = hash('sha256', implode('|', [
            $claims['student_id'],
            $sessionId,
            $qrCode ?: $manualCode,
            $lat  ?? '0',
            $lng  ?? '0',
            $deviceUuid ?: 'NONE',
            $ts,
        ]));

        // ÔöÇÔöÇ Record ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ
        $db->prepare("
            INSERT INTO attendance_records
                (session_id, student_id, status, verification_method,
                 latitude, longitude, check_in_time, sate_token, created_at)
            VALUES (?, ?, 'present', ?, ?, ?, NOW(), ?, NOW())
        ")->execute([
            $sessionId, $claims['student_id'],
            $verMethod, $lat, $lng, $sateToken,
        ]);
        $attId = (int)$db->lastInsertId();

        $this->audit($claims['student_id'], 'student', 'CHECK_IN', [
            'session_id'  => $sessionId,
            'method'      => $verMethod,
            'device_uuid' => $deviceUuid,
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

    /** GET /api/student/checkin/history   params: limit? */
    public function getHistory(): void
    {
        $claims = AuthMiddleware::student();
        $limit  = min((int)($_GET['limit'] ?? 20), 100);

        $stmt = $this->db()->prepare("
            SELECT  ar.attendance_id, ar.status, ar.verification_method,
                    ar.check_in_time, ar.sate_token,
                    s.session_id, s.session_date, s.session_type,
                    c.class_name, c.course_code
            FROM    attendance_records ar
            JOIN    attendance_sessions s ON s.session_id = ar.session_id
            JOIN    classes c             ON c.class_id   = s.class_id
            WHERE   ar.student_id = ?
            ORDER   BY ar.created_at DESC
            LIMIT   ?
        ");
        $stmt->bindValue(1, $claims['student_id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,                PDO::PARAM_INT);
        $stmt->execute();
        $this->json(['records' => $stmt->fetchAll()]);
    }

    /** GET /api/student/checkin/receipt/{id} */
    public function getReceipt(int $attId): void
    {
        $claims = AuthMiddleware::student();
        $stmt   = $this->db()->prepare("
            SELECT  ar.attendance_id, ar.status, ar.verification_method,
                    ar.check_in_time, ar.sate_token, ar.latitude, ar.longitude,
                    s.session_id, s.session_date, s.session_type,
                    c.class_name, c.course_code,
                    l.full_name AS lecturer_name
            FROM    attendance_records ar
            JOIN    attendance_sessions s ON s.session_id = ar.session_id
            JOIN    classes c             ON c.class_id   = s.class_id
            JOIN    lecturers l           ON l.lecturer_id = c.lecturer_id
            WHERE   ar.attendance_id = ? AND ar.student_id = ?
        ");
        $stmt->execute([$attId, $claims['student_id']]);
        $row = $stmt->fetch();
        if (!$row) $this->fail(404, 'Receipt not found or does not belong to you.');
        $this->json($row);
    }

    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
    // CLASSES
    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ

    /** GET /api/student/classes */
    public function getClasses(): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();

        $lidQ = $db->prepare("SELECT lecturer_id FROM students WHERE student_id = ?");
        $lidQ->execute([$claims['student_id']]);
        $lid = (int)($lidQ->fetchColumn() ?? 0);

        if (!$lid) {
            $this->json(['classes' => []]);
            return;
        }

        $stmt = $db->prepare("
            SELECT  c.class_id, c.class_name, c.course_code, c.level, c.program,
                    c.academic_year, c.semester,
                    l.full_name AS lecturer_name,
                    COUNT(DISTINCT s.session_id) AS total_sessions,
                    COUNT(DISTINCT CASE WHEN ar.status IN ('present','late')
                                        THEN ar.attendance_id END) AS present_count
            FROM    classes c
            JOIN    lecturers l        ON l.lecturer_id  = c.lecturer_id
            LEFT JOIN attendance_sessions s
                      ON s.class_id  = c.class_id
            LEFT JOIN attendance_records ar
                      ON ar.session_id = s.session_id AND ar.student_id = :sid
            WHERE   c.lecturer_id = :lid AND c.is_archived = 0
            GROUP   BY c.class_id
            ORDER   BY c.created_at DESC
        ");
        $stmt->bindValue(':sid', $claims['student_id'], PDO::PARAM_INT);
        $stmt->bindValue(':lid', $lid,                  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $t = (int)$r['total_sessions'];
            $p = (int)$r['present_count'];
            $r['attendance_rate']       = $t > 0 ? round(($p / $t) * 100, 1) : null;
            $r['attendance_percentage'] = $r['attendance_rate'];
        }
        unset($r);

        $this->json(['classes' => $rows]);
    }

    /** GET /api/student/classes/preview?join_code=... */
    public function previewClass(): void
    {
        $claims   = AuthMiddleware::student();
        $joinCode = strtoupper(trim((string)($_GET['join_code'] ?? '')));

        if ($joinCode === '') {
            $this->fail(400, 'join_code is required.');
        }

        $db = $this->db();

        $classStmt = $db->prepare("\n            SELECT  c.class_id, c.class_name, c.course_code, c.join_code,
                    c.level, c.program, c.academic_year, c.semester,
                    c.lecturer_id,
                    l.full_name AS lecturer_name,
                    l.title     AS lecturer_title
            FROM    classes c
            JOIN    lecturers l ON l.lecturer_id = c.lecturer_id
            WHERE   UPPER(c.join_code) = ?
              AND   c.is_archived = 0
            LIMIT   1
        ");
        $classStmt->execute([$joinCode]);
        $class = $classStmt->fetch();
        if (!$class) {
            $this->fail(404, 'Class not found. Please check the join code.');
        }

        $stuStmt = $db->prepare("\n            SELECT student_id, index_number, email, lecturer_id
            FROM   students
            WHERE  student_id = ?
            LIMIT  1
        ");
        $stuStmt->execute([$claims['student_id']]);
        $stu = $stuStmt->fetch();
        if (!$stu) {
            $this->fail(404, 'Student account not found.');
        }

        $onMasterList = false;
        $mlProgram    = null;
        $mlLevel      = null;

        $mlStmt = $db->prepare("\n            SELECT id, program, level
            FROM   master_list
            WHERE  lecturer_id = ?
              AND (
                    (index_number IS NOT NULL AND index_number <> '' AND index_number = ?)
                 OR (email IS NOT NULL AND email <> '' AND LOWER(email) = LOWER(?))
              )
            LIMIT 1
        ");
        $mlStmt->execute([(int)$class['lecturer_id'], (string)($stu['index_number'] ?? ''), (string)($stu['email'] ?? '')]);
        $ml = $mlStmt->fetch();
        if ($ml) {
            $onMasterList = true;
            $mlProgram    = $ml['program'] ?? null;
            $mlLevel      = $ml['level'] ?? null;
        }

        $this->json([
            'class_id'        => (int)$class['class_id'],
            'class_name'      => $class['class_name'],
            'course_code'     => $class['course_code'],
            'join_code'       => $class['join_code'],
            'level'           => $class['level'] ?: $mlLevel,
            'program'         => $class['program'] ?: $mlProgram,
            'academic_year'   => $class['academic_year'],
            'semester'        => $class['semester'],
            'lecturer_id'     => (int)$class['lecturer_id'],
            'lecturer_name'   => $class['lecturer_name'],
            'lecturer_title'  => $class['lecturer_title'],
            'on_master_list'  => $onMasterList,
            'already_joined'  => ((int)($stu['lecturer_id'] ?? 0) === (int)$class['lecturer_id']),
        ]);
    }

    /** POST /api/student/classes/join */
    public function joinClass(): void
    {
        $claims   = AuthMiddleware::student();
        $body     = $this->jsonBody();
        $joinCode = strtoupper(trim((string)($body['join_code'] ?? '')));
        $password = (string)($body['password'] ?? '');

        if ($joinCode === '') {
            $this->fail(400, 'join_code is required.');
        }
        if (trim($password) === '') {
            $this->fail(400, 'password is required.');
        }

        $db = $this->db();

        $classStmt = $db->prepare("\n            SELECT class_id, class_name, course_code, join_code, lecturer_id, is_archived
            FROM   classes
            WHERE  UPPER(join_code) = ?
            LIMIT  1
        ");
        $classStmt->execute([$joinCode]);
        $class = $classStmt->fetch();

        if (!$class || (int)$class['is_archived'] === 1) {
            $this->fail(404, 'Class not found. Please check the join code.');
        }

        $stuStmt = $db->prepare("\n            SELECT student_id, lecturer_id, index_number, email, password_hash
            FROM   students
            WHERE  student_id = ?
            LIMIT  1
        ");
        $stuStmt->execute([$claims['student_id']]);
        $stu = $stuStmt->fetch();
        if (!$stu) {
            $this->fail(404, 'Student account not found.');
        }
        if (empty($stu['password_hash']) || !password_verify($password, $stu['password_hash'])) {
            $this->fail(401, 'Incorrect password.');
        }

        $targetLecturerId = (int)$class['lecturer_id'];
        $currentLecturerId = (int)($stu['lecturer_id'] ?? 0);

        if ($currentLecturerId === $targetLecturerId) {
            $this->json([
                'joined'      => true,
                'already'     => true,
                'class_id'    => (int)$class['class_id'],
                'class_name'  => $class['class_name'],
                'course_code' => $class['course_code'],
            ]);
            return;
        }

        if ($currentLecturerId > 0 && $currentLecturerId !== $targetLecturerId) {
            $this->fail(409, 'You are already linked to another lecturer. Contact support to switch.');
        }

        $mlStmt = $db->prepare("\n            SELECT id, program
            FROM   master_list
            WHERE  lecturer_id = ?
              AND (
                    (index_number IS NOT NULL AND index_number <> '' AND index_number = ?)
                 OR (email IS NOT NULL AND email <> '' AND LOWER(email) = LOWER(?))
              )
            LIMIT 1
        ");
        $mlStmt->execute([$targetLecturerId, (string)($stu['index_number'] ?? ''), (string)($stu['email'] ?? '')]);
        $ml = $mlStmt->fetch();

        $update = $db->prepare("\n            UPDATE students
            SET lecturer_id        = ?,
                master_list_id     = ?,
                enrollment_status  = 'enrolled',
                program            = COALESCE(NULLIF(program, ''), ?)
            WHERE student_id = ?
        ");
        $update->execute([
            $targetLecturerId,
            $ml['id'] ?? null,
            $ml['program'] ?? null,
            $claims['student_id'],
        ]);

        // Enroll the student in this specific class as well.
        // INSERT IGNORE keeps duplicate join attempts idempotent.
        $db->prepare("\n            INSERT IGNORE INTO class_enrollments (student_id, class_id)
            VALUES (?, ?)
        ")->execute([
            (int)$claims['student_id'],
            (int)$class['class_id'],
        ]);

        $this->audit((int)$claims['student_id'], 'student', 'CLASS_JOINED', [
            'class_id'    => (int)$class['class_id'],
            'lecturer_id' => $targetLecturerId,
            'join_code'   => $class['join_code'],
        ]);

        $this->json([
            'joined'      => true,
            'already'     => false,
            'class_id'    => (int)$class['class_id'],
            'class_name'  => $class['class_name'],
            'course_code' => $class['course_code'],
            'join_code'   => $class['join_code'],
        ]);
    }

    /** GET /api/student/classes/{id} */
    public function getClass(int $classId): void
    {
        $claims = AuthMiddleware::student();
        $stmt   = $this->db()->prepare("
            SELECT  c.class_id, c.class_name, c.course_code, c.level, c.program,
                    c.academic_year, c.semester, c.geofence_radius_meters,
                    l.full_name AS lecturer_name,
                    COUNT(DISTINCT s.session_id) AS total_sessions,
                    COUNT(DISTINCT CASE WHEN ar.status IN ('present','late')
                                        THEN ar.attendance_id END) AS present_count
            FROM    classes c
            JOIN    lecturers l ON l.lecturer_id = c.lecturer_id
            LEFT JOIN attendance_sessions s
                      ON s.class_id = c.class_id
            LEFT JOIN attendance_records ar
                      ON ar.session_id = s.session_id AND ar.student_id = ?
            WHERE   c.class_id = ?
            GROUP   BY c.class_id
        ");
        $stmt->execute([$claims['student_id'], $classId]);
        $row = $stmt->fetch();
        if (!$row) $this->fail(404, 'Class not found.');

        $t = (int)$row['total_sessions'];
        $row['attendance_rate'] = $t > 0
            ? round(((int)$row['present_count'] / $t) * 100, 1)
            : null;

        $this->json($row);
    }

    /** GET /api/student/classes/{id}/attendance */
    public function getClassAttendance(int $classId): void
    {
        $claims = AuthMiddleware::student();
        $stmt   = $this->db()->prepare("
            SELECT ar.attendance_id, ar.status, ar.verification_method,
                   ar.check_in_time, ar.sate_token,
                   s.session_id, s.session_date, s.session_type
            FROM   attendance_records ar
            JOIN   attendance_sessions s ON s.session_id = ar.session_id
            WHERE  s.class_id = ? AND ar.student_id = ?
            ORDER  BY s.session_date DESC
        ");
        $stmt->execute([$classId, $claims['student_id']]);
        $this->json(['records' => $stmt->fetchAll()]);
    }

    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
    // ATTENDANCE ANALYTICS
    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ

    /** GET /api/student/attendance   params: limit?, class_id? */
    public function getAttendance(): void
    {
        $claims    = AuthMiddleware::student();
        $studentId = (int)$claims['student_id'];
        $limit     = min((int)($_GET['limit'] ?? 300), 500);
        $classId   = isset($_GET['class_id']) ? (int)$_GET['class_id'] : null;

        $db = $this->db();

        $sql = "
            SELECT
                ass.session_id,
                ass.session_date,
                ass.session_type,
                c.class_name,
                c.course_code,
                COALESCE(ar.status, 'absent') AS status,
                ar.check_in_time,
                ar.verification_method,
                ar.attendance_id
            FROM  class_enrollments ce
            JOIN  classes c ON c.class_id = ce.class_id
            JOIN  attendance_sessions ass ON ass.class_id = c.class_id
            LEFT JOIN attendance_records ar
                   ON ar.session_id = ass.session_id
                  AND ar.student_id = ce.student_id
            WHERE ce.student_id = ?
              AND ass.session_status = 'closed'
        ";

        $params = [$studentId];

        if ($classId) {
            $sql .= " AND c.class_id = ?";
            $params[] = $classId;
        }

        $sql .= " ORDER BY ass.session_date DESC, ass.session_id DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->json(['records' => $records, 'total' => count($records)]);
    }

    /** GET /api/student/attendance/stats */
    public function getAttendanceStats(): void
    {
        $claims    = AuthMiddleware::student();
        $studentId = (int)$claims['student_id'];

        $db = $this->db();

        $stmt = $db->prepare(" 
            SELECT
                COUNT(*)                                            AS total,
                SUM(COALESCE(ar.status, 'absent') = 'present') AS present,
                SUM(COALESCE(ar.status, 'absent') = 'absent')  AS absent,
                SUM(COALESCE(ar.status, 'absent') = 'late')    AS late,
                SUM(COALESCE(ar.status, 'absent') = 'excused') AS excused
            FROM  class_enrollments ce
            JOIN  classes c ON c.class_id = ce.class_id
            JOIN  attendance_sessions ass ON ass.class_id = c.class_id
            LEFT JOIN attendance_records ar
                   ON ar.session_id = ass.session_id
                  AND ar.student_id = ce.student_id
            WHERE ce.student_id = ?
              AND ass.session_status = 'closed'
        ");
        $stmt->execute([$studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $total   = (int)($row['total'] ?? 0);
        $present = (int)($row['present'] ?? 0);
        $absent  = (int)($row['absent'] ?? 0);
        $late    = (int)($row['late'] ?? 0);
        $excused = (int)($row['excused'] ?? 0);

        $denominator = $total - $excused;
        $rate        = $denominator > 0
            ? round((($present + $late) / $denominator) * 100, 2)
            : 0.0;

        $this->json([
            // Backward + forward compatible response shape
            'total'   => $total,
            'present' => $present,
            'absent'  => $absent,
            'late'    => $late,
            'excused' => $excused,
            'rate'    => $rate,
            'stats'   => compact('total', 'present', 'absent', 'late', 'excused', 'rate'),
        ]);
    }

    /** GET /api/student/attendance/streak */
    public function getAttendanceStreak(): void
    {
        $claims    = AuthMiddleware::student();
        $studentId = (int)$claims['student_id'];

        $db = $this->db();

        $stmt = $db->prepare(" 
            SELECT COALESCE(ar.status, 'absent') AS status
            FROM  class_enrollments ce
            JOIN  classes c ON c.class_id = ce.class_id
            JOIN  attendance_sessions ass ON ass.class_id = c.class_id
            LEFT JOIN attendance_records ar
                   ON ar.session_id = ass.session_id
                  AND ar.student_id = ce.student_id
            WHERE ce.student_id = ?
              AND ass.session_status = 'closed'
            ORDER BY ass.session_date DESC, ass.session_id DESC
            LIMIT 500
        ");
        $stmt->execute([$studentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $streak = 0;
        foreach ($rows as $status) {
            if ($status === 'present' || $status === 'late') {
                $streak++;
            } else {
                break;
            }
        }

        $this->json(['streak' => $streak]);
    }

    /** GET /api/student/attendance/risk */
    public function getAttendanceRisk(): void
    {
        $claims    = AuthMiddleware::student();
        $studentId = (int)$claims['student_id'];

        $db = $this->db();

        $stmt = $db->prepare(" 
            SELECT
                c.class_id,
                c.class_name,
                c.course_code,
                COUNT(*)                                            AS total_sessions,
                SUM(COALESCE(ar.status, 'absent') = 'present') AS present_count,
                SUM(COALESCE(ar.status, 'absent') = 'late')    AS late_count,
                SUM(COALESCE(ar.status, 'absent') = 'excused') AS excused_count,
                ROUND(
                  CASE
                    WHEN COUNT(*) - SUM(COALESCE(ar.status, 'absent') = 'excused') = 0
                         THEN 0
                    ELSE (
                        SUM(COALESCE(ar.status, 'absent') IN ('present','late'))
                        /
                        (COUNT(*) - SUM(COALESCE(ar.status, 'absent') = 'excused'))
                    ) * 100
                  END,
                2) AS rate
            FROM  class_enrollments ce
            JOIN  classes c ON c.class_id = ce.class_id
            JOIN  attendance_sessions ass ON ass.class_id = c.class_id
            LEFT JOIN attendance_records ar
                   ON ar.session_id = ass.session_id
                  AND ar.student_id = ce.student_id
            WHERE ce.student_id = ?
              AND ass.session_status = 'closed'
            GROUP BY c.class_id, c.class_name, c.course_code
            HAVING rate < 75
            ORDER BY rate ASC
        ");
        $stmt->execute([$studentId]);
        $risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->json(['risk_courses' => $risks]);
    }

    // Backward-compatible aliases used by current api.php route mappings.
    public function getStats(): void
    {
        $this->getAttendanceStats();
    }

    public function getStreak(): void
    {
        $this->getAttendanceStreak();
    }

    public function getRisk(): void
    {
        $this->getAttendanceRisk();
    }

    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
    // OVERRIDE
    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ

    /** POST /api/student/override/request */
    public function submitOverride(): void
    {
        $claims = AuthMiddleware::student();
        $b      = $this->jsonBody();
        $db     = $this->db();

        $sessionId  = (int)($b['session_id'] ?? 0);
        $classId    = isset($b['class_id'])  ? (int)$b['class_id']   : null;
        $lat        = isset($b['latitude'])  ? (float)$b['latitude']  : null;
        $lng        = isset($b['longitude']) ? (float)$b['longitude'] : null;

        if (!$sessionId) $this->fail(400, 'session_id is required.');

        $dup = $db->prepare(
            "SELECT override_id FROM override_requests WHERE student_id = ? AND session_id = ?"
        );
        $dup->execute([$claims['student_id'], $sessionId]);
        if ($dup->fetch()) $this->fail(409, 'Override already submitted for this session.');

        $sessQ = $db->prepare("
            SELECT c.lecturer_id, c.class_id, c.class_name
            FROM   attendance_sessions s JOIN classes c ON c.class_id = s.class_id
            WHERE  s.session_id = ?
        ");
        $sessQ->execute([$sessionId]);
        $sess = $sessQ->fetch();
        if (!$sess) $this->fail(404, 'Session not found.');

        $db->prepare("
            INSERT INTO override_requests
                (session_id, class_id, student_id, lecturer_id,
                 geofence_passed, student_lat, student_lng, requested_at, status)
            VALUES (?, ?, ?, ?, 0, ?, ?, NOW(), 'pending')
        ")->execute([
            $sessionId, $classId ?? $sess['class_id'],
            $claims['student_id'], $sess['lecturer_id'],
            $lat, $lng,
        ]);

        // Email lecturer
        $stuQ = $db->prepare("SELECT student_name FROM students WHERE student_id = ?");
        $stuQ->execute([$claims['student_id']]);
        $stuName = (string)($stuQ->fetchColumn() ?: 'A student');

        $lQ = $db->prepare("SELECT email, full_name FROM lecturers WHERE lecturer_id = ?");
        $lQ->execute([$sess['lecturer_id']]);
        $lect = $lQ->fetch();
        if ($lect) {
            require_once __DIR__ . '/../utils/MailHelper.php';
            MailHelper::sendOverrideNotification(
                $lect['email'], $lect['full_name'], $stuName, $sess['class_name']
            );
        }

        $this->ok(null, 'Override request submitted. Your lecturer will review it shortly.');
    }

    /** GET /api/student/override/history */
    public function getOverrideHistory(): void
    {
        $claims = AuthMiddleware::student();
        $limit  = min((int)($_GET['limit'] ?? 20), 50);
        $stmt   = $this->db()->prepare("
            SELECT  o.override_id, o.status, o.override_reason,
                    o.requested_at, o.decided_at,
                    s.session_id, s.session_date, s.session_type,
                    c.class_name, c.course_code
            FROM    override_requests o
            JOIN    attendance_sessions s ON s.session_id = o.session_id
            JOIN    classes c             ON c.class_id   = o.class_id
            WHERE   o.student_id = ?
            ORDER   BY o.requested_at DESC LIMIT ?
        ");
        $stmt->bindValue(1, $claims['student_id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,                PDO::PARAM_INT);
        $stmt->execute();
        $this->json(['overrides' => $stmt->fetchAll()]);
    }

    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
    // NOTIFICATIONS
    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ

    /** GET /api/student/notifications   params: limit? */
    public function getNotifications(): void
    {
        $claims = AuthMiddleware::student();
        $limit  = min((int)($_GET['limit'] ?? 20), 50);
        $stmt   = $this->db()->prepare("
            SELECT notification_id, title, body, type,
                   is_read, read_at, created_at
            FROM   notifications
            WHERE  student_id = ?
            ORDER  BY created_at DESC LIMIT ?
        ");
        $stmt->bindValue(1, $claims['student_id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,                PDO::PARAM_INT);
        $stmt->execute();
        $this->json(['notifications' => $stmt->fetchAll()]);
    }

    /** GET /api/student/notifications/unread-count */
    public function unreadCount(): void
    {
        $claims = AuthMiddleware::student();
        $stmt   = $this->db()->prepare(
            "SELECT COUNT(*) AS count FROM notifications WHERE student_id = ? AND is_read = 0"
        );
        $stmt->execute([$claims['student_id']]);
        $this->json($stmt->fetch());
    }

    /** POST /api/student/notifications/{id}/read */
    public function markRead(int $notifId): void
    {
        $claims = AuthMiddleware::student();
        $this->db()->prepare(
            "UPDATE notifications SET is_read = 1, read_at = NOW()
             WHERE notification_id = ? AND student_id = ?"
        )->execute([$notifId, $claims['student_id']]);
        $this->ok();
    }

    /** POST /api/student/notifications/read-all */
    public function markAllRead(): void
    {
        $claims = AuthMiddleware::student();
        $this->db()->prepare(
            "UPDATE notifications SET is_read = 1, read_at = NOW()
             WHERE student_id = ? AND is_read = 0"
        )->execute([$claims['student_id']]);
        $this->ok();
    }

    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
    // GEOFENCE LOGS
    // ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ

    /** GET /api/student/geofence/logs   params: limit? */
    public function getGeofenceLogs(): void
    {
        $claims = AuthMiddleware::student();
        $limit  = min((int)($_GET['limit'] ?? 20), 50);
        $stmt   = $this->db()->prepare("
            SELECT  gl.log_id, gl.student_latitude, gl.student_longitude,
                    gl.distance_meters, gl.is_within_geofence, gl.logged_at,
                    s.session_date, c.class_name
            FROM    geofence_logs gl
            JOIN    attendance_sessions s ON s.session_id = gl.session_id
            JOIN    classes c             ON c.class_id   = s.class_id
            WHERE   gl.student_id = ?
            ORDER   BY gl.logged_at DESC LIMIT ?
        ");
        $stmt->bindValue(1, $claims['student_id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,                PDO::PARAM_INT);
        $stmt->execute();
        $this->json(['logs' => $stmt->fetchAll()]);
    }

    // ══════════════════════════════════════════════════════════════════════════════
    // MESSAGING
    // ══════════════════════════════════════════════════════════════════════════════

    /** GET /student/messages — list message channels (classes) */
    public function getMessageChannels(): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();

        $stmt = $db->prepare("
            SELECT DISTINCT
                c.class_id, c.class_name, c.course_code,
                COUNT(m.message_id) AS total_messages,
                (SELECT COUNT(*) FROM message_reads mr
                 WHERE mr.message_id IN (
                    SELECT message_id FROM course_messages WHERE class_id = c.class_id
                 ) AND mr.reader_type = 'student' AND mr.reader_id = ?) AS read_count,
                (
                    SELECT COUNT(*)
                    FROM course_messages cm
                    WHERE cm.class_id = c.class_id
                      AND cm.is_deleted = 0
                      AND NOT (cm.sender_type = 'student' AND cm.sender_id = ?)
                      AND NOT EXISTS (
                          SELECT 1
                          FROM message_reads mr2
                          WHERE mr2.message_id = cm.message_id
                            AND mr2.reader_type = 'student'
                            AND mr2.reader_id = ?
                      )
                ) AS unread_count,
                (
                    SELECT cm2.body
                    FROM course_messages cm2
                    WHERE cm2.class_id = c.class_id
                      AND cm2.is_deleted = 0
                    ORDER BY cm2.created_at DESC
                    LIMIT 1
                ) AS last_message,
                MAX(m.created_at) AS last_message_time
            FROM class_enrollments ce
            JOIN classes c ON c.class_id = ce.class_id
            LEFT JOIN course_messages m ON m.class_id = c.class_id AND m.is_deleted = 0
            WHERE ce.student_id = ?
            GROUP BY c.class_id, c.class_name, c.course_code
            ORDER BY last_message_time DESC
        ");
        $stmt->execute([
            $claims['student_id'],
            $claims['student_id'],
            $claims['student_id'],
            $claims['student_id'],
        ]);
        $this->json(['channels' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /** GET /student/messages/dms — list direct message threads */
    public function getDirectMessages(): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();

        $stmt = $db->prepare("
            SELECT
                t.thread_id,
                t.lecturer_id,
                t.student_id,
                l.full_name AS lecturer_name,
                COALESCE(l.profile_photo, NULL) AS profile_photo,
                (SELECT body FROM direct_messages WHERE thread_id = t.thread_id AND is_deleted = 0 ORDER BY created_at DESC LIMIT 1) AS last_message,
                (SELECT created_at FROM direct_messages WHERE thread_id = t.thread_id AND is_deleted = 0 ORDER BY created_at DESC LIMIT 1) AS last_time,
                (
                    SELECT COUNT(*)
                    FROM direct_messages dm2
                    WHERE dm2.thread_id = t.thread_id
                      AND dm2.sender_type = 'lecturer'
                      AND dm2.is_deleted = 0
                      AND NOT EXISTS (
                          SELECT 1
                          FROM dm_reads dr
                          WHERE dr.dm_id = dm2.dm_id
                            AND dr.reader_type = 'student'
                            AND dr.reader_id = ?
                      )
                ) AS unread_count,
                (SELECT COUNT(*) FROM direct_messages WHERE thread_id = t.thread_id AND is_deleted = 0) AS message_count
            FROM direct_message_threads t
            JOIN lecturers l ON l.lecturer_id = t.lecturer_id
            WHERE t.student_id = ?
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$claims['student_id'], $claims['student_id']]);
        $this->json(['threads' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /** GET /student/messages/members — list all lecturers for starting new DMs */
    public function getMessageMembers(): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();

        // Return all lecturers the student is enrolled under
        $stmt = $db->prepare("
            SELECT DISTINCT
                l.lecturer_id AS id,
                'lecturer' AS type,
                l.full_name AS name,
                l.email,
                COALESCE(l.profile_photo, NULL) AS profile_photo
            FROM lecturers l
            JOIN classes c ON c.lecturer_id = l.lecturer_id
            JOIN class_enrollments ce ON ce.class_id = c.class_id
            WHERE ce.student_id = ? AND (l.is_active = 1 OR l.is_active IS NULL)
            ORDER BY l.full_name
        ");
        $stmt->execute([$claims['student_id']]);
        $this->json(['members' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /** POST /student/messages/dm/start — start a new DM thread */
    public function startDirectMessage(): void
    {
        $claims = AuthMiddleware::student();
        $b      = $this->jsonBody();
        $db     = $this->db();

        $lecturerId = (int)($b['participant_id'] ?? 0);
        if (!$lecturerId) $this->fail(400, 'participant_id is required.');

        // Check if thread already exists
        $existing = $db->prepare(
            "SELECT thread_id FROM direct_message_threads WHERE lecturer_id = ? AND student_id = ?"
        );
        $existing->execute([$lecturerId, $claims['student_id']]);
        $thread = $existing->fetch();

        if ($thread) {
            $this->json(['success' => true, 'thread_id' => $thread['thread_id'], 'new' => false]);
            return;
        }

        // Create new thread from a class where this student is enrolled under this lecturer
        $ins = $db->prepare("
            INSERT INTO direct_message_threads (lecturer_id, student_id, class_id, created_at)
            SELECT ?, ?, c.class_id, NOW()
            FROM classes c
            JOIN class_enrollments ce ON ce.class_id = c.class_id
            WHERE ce.student_id = ? AND c.lecturer_id = ?
            ORDER BY c.class_id ASC
            LIMIT 1
        ");
        $ins->execute([$lecturerId, $claims['student_id'], $claims['student_id'], $lecturerId]);
        if ($ins->rowCount() === 0) {
            $this->fail(403, 'You can only message lecturers for classes you are enrolled in.');
        }

        $threadId = (int)$db->lastInsertId();
        $this->json(['success' => true, 'thread_id' => $threadId, 'new' => true]);
    }

    /** GET /student/messages/dm/{thread_id} — get DM thread messages */
    public function getDirectMessageThread(int $threadId): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();

        // Verify access
        $verify = $db->prepare("
            SELECT thread_id FROM direct_message_threads WHERE thread_id = ? AND student_id = ?
        ");
        $verify->execute([$threadId, $claims['student_id']]);
        if (!$verify->fetch()) $this->fail(403, 'Access denied.');

        // Get messages
        $limit = min((int)($_GET['limit'] ?? 40), 100);

        $stmt = $db->prepare("
            SELECT
                dm.dm_id, dm.thread_id, dm.sender_type, dm.sender_id,
                dm.body, dm.parent_id, dm.is_deleted, dm.deleted_by,
                dm.created_at,
                CASE WHEN dm.sender_type = 'lecturer'
                     THEN (SELECT full_name FROM lecturers WHERE lecturer_id = dm.sender_id)
                     ELSE (SELECT student_name FROM students WHERE student_id = dm.sender_id)
                END AS sender_name,
                COALESCE(
                    CASE WHEN dm.sender_type = 'lecturer'
                         THEN (SELECT profile_photo FROM lecturers WHERE lecturer_id = dm.sender_id)
                         ELSE NULL
                    END,
                    NULL
                ) AS sender_photo
            FROM direct_messages dm
            WHERE dm.thread_id = ? AND dm.is_deleted = 0
            ORDER BY dm.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$threadId, $limit]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mark as read - get all unread messages from lecturer
        $unreadStmt = $db->prepare("
            SELECT dm.dm_id FROM direct_messages dm
            WHERE dm.thread_id = ? AND dm.sender_type = 'lecturer' AND dm.is_deleted = 0
            AND NOT EXISTS (
                SELECT 1 FROM dm_reads WHERE dm_reads.dm_id = dm.dm_id 
                AND reader_type = 'student' AND reader_id = ?
            )
        ");
        $unreadStmt->execute([$threadId, $claims['student_id']]);
        $unreadIds = $unreadStmt->fetchAll(PDO::FETCH_COLUMN);

        // Mark them as read
        if ($unreadIds) {
            foreach ($unreadIds as $dmId) {
                $readStmt = $db->prepare("
                    INSERT IGNORE INTO dm_reads (dm_id, reader_type, reader_id, read_at)
                    VALUES (?, 'student', ?, NOW())
                ");
                $readStmt->execute([$dmId, $claims['student_id']]);
            }
        }

        $this->json(['messages' => array_reverse($messages)]);
    }

    /** POST /student/messages/dm/{thread_id}/send — send a DM */
    public function sendDirectMessage(int $threadId): void
    {
        $claims = AuthMiddleware::student();
        $b      = $this->jsonBody();
        $db     = $this->db();

        // Verify access
        $verify = $db->prepare(
            "SELECT thread_id, lecturer_id FROM direct_message_threads WHERE thread_id = ? AND student_id = ?"
        );
        $verify->execute([$threadId, $claims['student_id']]);
        $thread = $verify->fetch();
        if (!$thread) $this->fail(403, 'Access denied.');

        $body = trim($b['body'] ?? '');
        if (!$body) $this->fail(400, 'Message body is required.');
        if (mb_strlen($body) > 2000) $this->fail(422, 'Message too long (max 2000 chars).');

        $body = strip_tags($body);

        // Insert message
        $db->prepare("
            INSERT INTO direct_messages (thread_id, sender_type, sender_id, body, created_at)
            VALUES (?, 'student', ?, ?, NOW())
        ")->execute([$threadId, $claims['student_id'], $body]);

        $messageId = (int)$db->lastInsertId();

        // Fetch the full message
        $msg = $db->prepare("
            SELECT dm_id, thread_id, sender_type, sender_id, body, parent_id, is_deleted, deleted_by, created_at
            FROM direct_messages WHERE dm_id = ?
        ");
        $msg->execute([$messageId]);
        $message = $msg->fetch(PDO::FETCH_ASSOC);

        $this->json([
            'success'    => true,
            'thread_id'  => $threadId,
            'message_id' => (int)($message['dm_id'] ?? $messageId),
            'created_at' => $message['created_at'] ?? null,
            'message'    => $message,
        ], 201);
    }

    /** GET /student/messages/{class_id} — get course channel messages */
    public function getMessages(int $classId): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();

        // Verify enrollment
        $check = $db->prepare("
            SELECT class_id FROM class_enrollments WHERE class_id = ? AND student_id = ?
        ");
        $check->execute([$classId, $claims['student_id']]);
        if (!$check->fetch()) $this->fail(403, 'Not enrolled in this class.');

        $limit = min((int)($_GET['limit'] ?? 40), 100);
        $since = (int)($_GET['since'] ?? 0);

        $stmt = $db->prepare("
            SELECT
                m.message_id, m.class_id, m.sender_type, m.sender_id,
                m.body, m.parent_id, m.is_deleted, m.created_at,
                CASE WHEN m.sender_type = 'lecturer'
                     THEN (SELECT full_name FROM lecturers WHERE lecturer_id = m.sender_id)
                     ELSE (SELECT student_name FROM students WHERE student_id = m.sender_id)
                END AS sender_name,
                CASE WHEN m.sender_type = 'lecturer'
                     THEN (SELECT profile_photo FROM lecturers WHERE lecturer_id = m.sender_id)
                     ELSE NULL
                END AS sender_photo
            FROM course_messages m
            WHERE m.class_id = ? AND m.is_deleted = 0
            ORDER BY m.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$classId, $limit]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mark as read
        $readStmt = $db->prepare("
            INSERT IGNORE INTO message_reads (message_id, reader_type, reader_id, read_at)
            SELECT message_id, 'student', ?, NOW()
            FROM course_messages
                        WHERE class_id = ?
                            AND is_deleted = 0
                            AND NOT (sender_type = 'student' AND sender_id = ?)
        ");
                $readStmt->execute([$claims['student_id'], $classId, $claims['student_id']]);

        $this->json(['messages' => array_reverse($messages)]);
    }

    /** POST /student/messages/{class_id} — send a course channel message */
    public function sendMessage(int $classId): void
    {
        $claims = AuthMiddleware::student();
        $b      = $this->jsonBody();
        $db     = $this->db();

        // Verify enrollment
        $check = $db->prepare("
            SELECT class_id FROM class_enrollments WHERE class_id = ? AND student_id = ?
        ");
        $check->execute([$classId, $claims['student_id']]);
        if (!$check->fetch()) $this->fail(403, 'Not enrolled in this class.');

        $body = trim($b['body'] ?? '');
        if (!$body) $this->fail(400, 'Message body is required.');
        if (mb_strlen($body) > 2000) $this->fail(422, 'Message too long (max 2000 chars).');

        $body = strip_tags($body);

        // Insert message
        $db->prepare("
            INSERT INTO course_messages (class_id, sender_type, sender_id, body, created_at)
            VALUES (?, 'student', ?, ?, NOW())
        ")->execute([$classId, $claims['student_id'], $body]);

        $messageId = (int)$db->lastInsertId();

        // Fetch the full message
        $msg = $db->prepare("
            SELECT message_id, class_id, sender_type, sender_id, body, parent_id, is_deleted, deleted_by, created_at
            FROM course_messages WHERE message_id = ?
        ");
        $msg->execute([$messageId]);
        $message = $msg->fetch(PDO::FETCH_ASSOC);

        $this->json([
            'success'    => true,
            'message_id' => (int)($message['message_id'] ?? $messageId),
            'created_at' => $message['created_at'] ?? null,
            'message'    => $message,
        ], 201);
    }

    /** POST /student/messages/delete — soft-delete own message (course or DM) */
    public function deleteMessage(): void
    {
        $claims = AuthMiddleware::student();
        $b      = $this->jsonBody();
        $db     = $this->db();

        $messageId = (int)($b['message_id'] ?? 0);
        if ($messageId <= 0) {
            $this->fail(400, 'message_id is required.');
        }

        $ctxType = (string)($b['context_type'] ?? ''); // optional: 'channel' | 'dm'
        $deleted = 0;

        $tryCourse = function() use ($db, $messageId, $claims): int {
            $q = $db->prepare("
                UPDATE course_messages
                SET is_deleted = 1, deleted_by = 'student'
                WHERE message_id = ?
                  AND sender_type = 'student'
                  AND sender_id = ?
                  AND is_deleted = 0
            ");
            $q->execute([$messageId, $claims['student_id']]);
            return $q->rowCount();
        };

        $tryDm = function() use ($db, $messageId, $claims): int {
            $q = $db->prepare("
                UPDATE direct_messages
                SET is_deleted = 1, deleted_by = 'student'
                WHERE dm_id = ?
                  AND sender_type = 'student'
                  AND sender_id = ?
                  AND is_deleted = 0
            ");
            $q->execute([$messageId, $claims['student_id']]);
            return $q->rowCount();
        };

        if ($ctxType === 'channel') {
            $deleted = $tryCourse();
            if ($deleted === 0) $deleted = $tryDm();
        } elseif ($ctxType === 'dm') {
            $deleted = $tryDm();
            if ($deleted === 0) $deleted = $tryCourse();
        } else {
            $deleted = $tryCourse();
            if ($deleted === 0) $deleted = $tryDm();
        }

        if ($deleted > 0) {
            $this->json(['success' => true, 'deleted' => true]);
            return;
        }

        // Idempotent delete behavior: return success even if already deleted.
        $existsOwn = $db->prepare("
            SELECT 1 FROM course_messages
            WHERE message_id = ? AND sender_type = 'student' AND sender_id = ?
            UNION ALL
            SELECT 1 FROM direct_messages
            WHERE dm_id = ? AND sender_type = 'student' AND sender_id = ?
            LIMIT 1
        ");
        $existsOwn->execute([
            $messageId,
            $claims['student_id'],
            $messageId,
            $claims['student_id'],
        ]);

        if ($existsOwn->fetch()) {
            $this->json(['success' => true, 'deleted' => false]);
            return;
        }

        // Do not leak ownership details for unknown ids.
        $this->json(['success' => true, 'deleted' => false]);
    }
}
