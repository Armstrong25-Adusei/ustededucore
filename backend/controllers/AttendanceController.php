<?php
/**
 * EduCore — AttendanceController  v1.0
 *
 * POST  /api/attendance/session/open           — open a new attendance session
 * POST  /api/attendance/session/close/{id}     — close session (alias)
 * GET   /api/attendance/session/open           — get open sessions for this lecturer
 * GET   /api/attendance/session/{id}           — session detail with records
 * POST  /api/attendance/record                 — manual record insert
 * PATCH /api/attendance/record/{id}            — edit status of a record
 * GET   /api/attendance/summary                — summary stats + records list
 * GET   /api/attendance/heatmap                — per-date heatmap data
 * GET   /api/attendance/sync/pending           — pending offline sync records
 * POST  /api/attendance/sync/resolve           — resolve sync conflicts
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';

class AttendanceController extends BaseController
{
    // ── POST /api/attendance/session/open ────────────────────────────────
    public function openSession(): void
    {
        $claims  = AuthMiddleware::lecturer();
        $lecId   = (int)$claims['lecturer_id'];
        $b       = $this->jsonBody();

        $classId = (int)($b['class_id'] ?? 0);
        if (!$classId) $this->fail(400, 'class_id is required.');

        $db = $this->db();

        // Verify the class belongs to this lecturer
        $cStmt = $db->prepare("SELECT * FROM classes WHERE class_id = ? AND lecturer_id = ?");
        $cStmt->execute([$classId, $lecId]);
        $class = $cStmt->fetch();
        if (!$class) $this->fail(404, 'Course not found.');

        // Prevent duplicate open sessions for same class today
        $dupStmt = $db->prepare("
            SELECT session_id FROM attendance_sessions
            WHERE class_id = ? AND session_date = CURDATE()
              AND session_status IN ('open','in_progress')
            LIMIT 1
        ");
        $dupStmt->execute([$classId]);
        if ($dupStmt->fetch()) {
            $this->fail(409, 'An active session already exists for this course today.');
        }

        // GPS / geofence override from request body
        $lat    = isset($b['latitude'])              ? (float)$b['latitude']              : null;
        $lng    = isset($b['longitude'])             ? (float)$b['longitude']             : null;
        $radius = isset($b['geofence_radius_meters']) ? (int)$b['geofence_radius_meters'] : null;

        if ($lat !== null && $lng !== null) {
            $db->prepare("UPDATE classes SET gps_latitude=?, gps_longitude=?" .
                ($radius ? ", geofence_radius_meters=?" : "") .
                " WHERE class_id=?"
            )->execute($radius ? [$lat, $lng, $radius, $classId] : [$lat, $lng, $classId]);
        }

        // Generate initial QR and manual code
        $qrToken    = bin2hex(random_bytes(32));
        $manualCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $ttl        = $this->_getQRTTL($lecId, $db);
        $expiry     = date('Y-m-d H:i:s', time() + $ttl);
        $now        = date('H:i:s');
        $type       = $b['session_type'] ?? 'class';

        $db->prepare("
            INSERT INTO attendance_sessions
                (class_id, session_date, session_time, session_type,
                 session_status, current_qr_code, qr_code_expires_at,
                 manual_code, manual_code_expires_at,
                 total_students_present, total_students_absent)
            VALUES (?, CURDATE(), ?, ?, 'open', ?, ?, ?, ?, 0, 0)
        ")->execute([$classId, $now, $type, $qrToken, $expiry, $manualCode, $expiry]);

        $sessionId = (int)$db->lastInsertId();

        // Log first QR to dynamic_qr_codes
        $db->prepare("
            INSERT INTO dynamic_qr_codes
                (session_id, qr_code_value, manual_code_value, expires_at, is_expired)
            VALUES (?, ?, ?, ?, 0)
        ")->execute([$sessionId, $qrToken, $manualCode, $expiry]);

        $this->audit($lecId, 'lecturer', 'SESSION_OPENED', [
            'session_id' => $sessionId,
            'class_id'   => $classId,
        ]);

        $this->json([
            'session' => [
                'session_id'          => $sessionId,
                'class_id'            => $classId,
                'class_name'          => $class['class_name'],
                'course_code'         => $class['course_code'],
                'session_date'        => date('Y-m-d'),
                'session_time'        => $now,
                'session_type'        => $type,
                'session_status'      => 'open',
                'current_qr_code'     => $qrToken,
                'qr_code_expires_at'  => $expiry,
                'manual_code'         => $manualCode,
                'ttl_seconds'         => $ttl,
                'gps_latitude'        => $class['gps_latitude'],
                'gps_longitude'       => $class['gps_longitude'],
                'geofence_radius_meters' => $radius ?? $class['geofence_radius_meters'],
                'total_students_present' => 0,
                'total_students_absent'  => 0,
            ],
        ], 201);
    }

    // ── POST /api/attendance/session/close/{id} ───────────────────────────
    public function closeSession(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $db     = $this->db();

        $this->_assertOwnsSession($db, $id, $lecId);

        $db->prepare("
            UPDATE attendance_sessions
            SET session_status = 'closed',
                current_qr_code = NULL,
                qr_code_expires_at = NULL
            WHERE session_id = ?
        ")->execute([$id]);

        $this->audit($lecId, 'lecturer', 'SESSION_CLOSED', ['session_id' => $id]);
        $this->ok(null, 'Session closed.');
    }

    // ── GET /api/attendance/session/open ─────────────────────────────────
    public function getOpenSessions(): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];

        $stmt = $this->db()->prepare("
            SELECT  ass.*, c.class_name, c.course_code,
                    c.gps_latitude, c.gps_longitude, c.geofence_radius_meters
            FROM    attendance_sessions ass
            JOIN    classes c ON c.class_id = ass.class_id
            WHERE   c.lecturer_id = ?
              AND   ass.session_status IN ('open','in_progress')
            ORDER   BY ass.session_date DESC, ass.session_time DESC
            LIMIT   1
        ");
        $stmt->execute([$lecId]);
        $sess = $stmt->fetch();

        // Return empty session if none exists (don't throw 404)
        $this->json(['session' => $sess ?: null]);
    }

    // ── GET /api/attendance/session/{id} ─────────────────────────────────
    public function sessionDetail(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $db     = $this->db();

        $this->_assertOwnsSession($db, $id, $lecId);

        $stmt = $db->prepare("
            SELECT  ar.attendance_id, ar.status, ar.verification_method,
                    ar.check_in_time, ar.latitude, ar.longitude,
                    ar.remarks, ar.sate_token,
                    s.student_name, s.index_number, s.email, s.profile_photo,
                    ass.session_date, ass.session_time, ass.session_type,
                    c.class_name, c.course_code
            FROM    attendance_records ar
            JOIN    students s ON s.student_id = ar.student_id
            JOIN    attendance_sessions ass ON ass.session_id = ar.session_id
            JOIN    classes c ON c.class_id = ass.class_id
            WHERE   ar.session_id = ?
            ORDER   BY ar.check_in_time ASC
        ");
        $stmt->execute([$id]);
        $records = $stmt->fetchAll();

        $this->json(['session_id' => $id, 'records' => $records]);
    }

    // ── POST /api/attendance/record ───────────────────────────────────────
    public function recordAttendance(): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $b      = $this->jsonBody();

        $sessionId = (int)($b['session_id'] ?? 0);
        $studentId = (int)($b['student_id'] ?? 0);
        $status    = $b['status'] ?? 'present';

        if (!$sessionId || !$studentId) $this->fail(400, 'session_id and student_id are required.');
        if (!in_array($status, ['present','absent','late','excused'])) $this->fail(400, 'Invalid status.');

        $db = $this->db();
        $this->_assertOwnsSession($db, $sessionId, $lecId);

        $db->prepare("
            INSERT INTO attendance_records
                (session_id, student_id, status, verification_method, check_in_time)
            VALUES (?, ?, ?, 'manual_override', NOW())
            ON DUPLICATE KEY UPDATE status = VALUES(status), verification_method = 'manual_override'
        ")->execute([$sessionId, $studentId, $status]);

        $this->audit($lecId, 'lecturer', 'MANUAL_RECORD', [
            'session_id' => $sessionId,
            'student_id' => $studentId,
            'status'     => $status,
        ]);

        $this->ok(null, 'Record saved.');
    }

    // ── PATCH /api/attendance/record/{id} ────────────────────────────────
    public function editRecord(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $b      = $this->jsonBody();

        $status  = $b['status']  ?? null;
        $remarks = $b['remarks'] ?? null;

        if (!$status && $remarks === null) $this->fail(400, 'Nothing to update.');
        if ($status && !in_array($status, ['present','absent','late','excused'])) {
            $this->fail(400, 'Invalid status.');
        }

        $db = $this->db();

        // Verify the record belongs to a session owned by this lecturer
        $check = $db->prepare("
            SELECT ar.attendance_id
            FROM   attendance_records ar
            JOIN   attendance_sessions ass ON ass.session_id = ar.session_id
            JOIN   classes c ON c.class_id = ass.class_id
            WHERE  ar.attendance_id = ? AND c.lecturer_id = ?
        ");
        $check->execute([$id, $lecId]);
        if (!$check->fetch()) $this->fail(404, 'Record not found.');

        $sets = []; $vals = [];
        if ($status)  { $sets[] = 'status = ?';  $vals[] = $status; }
        if ($remarks !== null) { $sets[] = 'remarks = ?'; $vals[] = $remarks; }
        $vals[] = $id;

        $db->prepare("UPDATE attendance_records SET " . implode(', ', $sets) . " WHERE attendance_id = ?")
           ->execute($vals);

        $this->audit($lecId, 'lecturer', 'RECORD_EDITED', [
            'attendance_id' => $id,
            'status'        => $status,
        ]);

        $this->ok(null, 'Record updated.');
    }

    // ── GET /api/attendance/summary ───────────────────────────────────────
    public function summary(): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $db     = $this->db();

        $classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : null;
        $status  = $_GET['status']  ?? null;
        $date    = $_GET['date']    ?? null;
        $method  = $_GET['method']  ?? null;
        $limit   = min((int)($_GET['limit'] ?? 500), 2000);

        // Build parameterised WHERE clauses
        $where  = ["c.lecturer_id = ?"];
        $params = [$lecId];

        if ($classId) { $where[] = "ass.class_id = ?";              $params[] = $classId; }
        if ($status)  { $where[] = "ar.status = ?";                 $params[] = $status; }
        if ($date)    { $where[] = "ass.session_date = ?";          $params[] = $date; }
        if ($method)  { $where[] = "ar.verification_method = ?";    $params[] = $method; }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        // Aggregate summary
        $aggStmt = $db->prepare("
            SELECT
                SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END)  AS total_present,
                SUM(CASE WHEN ar.status = 'absent'  THEN 1 ELSE 0 END)  AS total_absent,
                SUM(CASE WHEN ar.status = 'late'    THEN 1 ELSE 0 END)  AS total_late,
                SUM(CASE WHEN ar.status = 'excused' THEN 1 ELSE 0 END)  AS total_excused,
                COUNT(ar.attendance_id)                                   AS total_records,
                ROUND(
                    SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) * 100.0
                    / NULLIF(COUNT(ar.attendance_id), 0)
                , 1) AS attendance_rate
            FROM   attendance_records ar
            JOIN   attendance_sessions ass ON ass.session_id = ar.session_id
            JOIN   classes c ON c.class_id = ass.class_id
            $whereSQL
        ");
        $aggStmt->execute($params);
        $summary = $aggStmt->fetch();

        // Detailed records
        $recStmt = $db->prepare("
            SELECT  ar.attendance_id, ar.status, ar.verification_method,
                    ar.check_in_time, ar.remarks,
                    s.student_name, s.index_number, s.profile_photo,
                    c.class_name, c.course_code, c.class_id,
                    ass.session_date, ass.session_id
            FROM    attendance_records ar
            JOIN    students s   ON s.student_id   = ar.student_id
            JOIN    attendance_sessions ass ON ass.session_id = ar.session_id
            JOIN    classes c    ON c.class_id     = ass.class_id
            $whereSQL
            ORDER   BY ass.session_date DESC, ar.check_in_time DESC
            LIMIT   ?
        ");
        $recStmt->execute([...$params, $limit]);
        $records = $recStmt->fetchAll();

        $this->json([
            'summary' => $summary,
            'records' => $records,
        ]);
    }

    // ── GET /api/attendance/heatmap ───────────────────────────────────────
    public function heatmap(): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $db     = $this->db();

        $classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : null;
        $from    = $_GET['from'] ?? date('Y-m-d', strtotime('-90 days'));
        $to      = $_GET['to']   ?? date('Y-m-d');

        $where  = ['c.lecturer_id = ?', 'ass.session_date BETWEEN ? AND ?'];
        $params = [$lecId, $from, $to];
        if ($classId) { $where[] = 'c.class_id = ?'; $params[] = $classId; }

        $stmt = $db->prepare("
            SELECT  ass.session_date,
                    SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) AS present_count,
                    COUNT(ar.attendance_id) AS total_count,
                    ROUND(
                        SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) * 100.0
                        / NULLIF(COUNT(ar.attendance_id), 0)
                    , 1) AS rate
            FROM    attendance_records ar
            JOIN    attendance_sessions ass ON ass.session_id = ar.session_id
            JOIN    classes c ON c.class_id = ass.class_id
            WHERE   " . implode(' AND ', $where) . "
            GROUP   BY ass.session_date
            ORDER   BY ass.session_date ASC
        ");
        $stmt->execute($params);
        $this->json(['heatmap' => $stmt->fetchAll()]);
    }

    // ── GET /api/attendance/sync/pending ─────────────────────────────────
    public function pendingSync(): void
    {
        $claims = AuthMiddleware::lecturer();
        $stmt   = $this->db()->prepare("
            SELECT * FROM sync_queue
            WHERE lecturer_id = ? AND status = 'pending'
            ORDER BY created_at ASC
            LIMIT 100
        ");
        $stmt->execute([$claims['lecturer_id']]);
        $this->json(['items' => $stmt->fetchAll()]);
    }

    // ── POST /api/attendance/sync/resolve ────────────────────────────────
    public function resolveSync(): void
    {
        $claims = AuthMiddleware::lecturer();
        $b      = $this->jsonBody();
        $ids    = array_map('intval', (array)($b['ids'] ?? []));
        if (empty($ids)) $this->fail(400, 'ids is required.');

        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->db()->prepare(
            "UPDATE sync_queue SET status='synced' WHERE queue_id IN ($in) AND lecturer_id=?"
        )->execute([...$ids, (int)$claims['lecturer_id']]);

        $this->ok(null, 'Synced.');
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────

    private function _assertOwnsSession(\PDO $db, int $sessionId, int $lecId): void
    {
        $stmt = $db->prepare("
            SELECT ass.session_id FROM attendance_sessions ass
            JOIN classes c ON c.class_id = ass.class_id
            WHERE ass.session_id = ? AND c.lecturer_id = ?
        ");
        $stmt->execute([$sessionId, $lecId]);
        if (!$stmt->fetch()) $this->fail(404, 'Session not found.');
    }

    private function _getQRTTL(int $lecId, \PDO $db): int
    {
        $stmt = $db->prepare(
            "SELECT config_value FROM configuration_engine WHERE lecturer_id = ? AND config_key = 'qr_expiry_seconds' LIMIT 1"
        );
        $stmt->execute([$lecId]);
        $row = $stmt->fetch();
        return $row ? max(10, (int)$row['config_value']) : 15;
    }
}