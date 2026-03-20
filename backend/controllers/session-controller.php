<?php
/**
 * EduCore — SessionController  v1.0
 *
 * GET    /api/sessions/{id}                  — session detail
 * POST   /api/sessions/{id}/close            — close a session
 * POST   /api/sessions/{id}/extend           — extend QR expiry
 * POST   /api/sessions/{id}/refresh-geofence — update GPS pin
 * PATCH  /api/sessions/{id}                  — rotate QR / update fields
 * GET    /api/sessions/{id}/current-code     — current QR + manual code
 * GET    /api/sessions/{id}/recent-checkins  — last 30 check-ins
 * GET    /api/sessions/{id}/live-stats       — present/absent counts
 *
 * Also handles:
 * POST   /api/attendance/session/open        — open a new session (via AttendanceController delegate)
 * GET    /api/attendance/session/open        — get open session
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';

class SessionController extends BaseController
{
    private const QR_TTL_DEFAULT  = 15; // seconds
    private const QR_CODE_LENGTH  = 32; // bytes → 64 hex chars

    // ── GET /api/sessions/{id} ────────────────────────────────────────────
    public function show(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $sess   = $this->_getOwnedSession($id, (int)$claims['lecturer_id']);

        $db     = $this->db();

        // Enrolled count
        $enrolled = (int)$db->prepare(
            "SELECT COUNT(*) FROM students WHERE lecturer_id = ? AND enrollment_status='enrolled'"
        )->execute([$claims['lecturer_id']]) ? $db->query(
            "SELECT COUNT(*) FROM students WHERE lecturer_id = {$claims['lecturer_id']} AND enrollment_status='enrolled'"
        )->fetchColumn() : 0;

        $sess['total_students'] = $enrolled;
        $this->json($sess);
    }

    // ── POST /api/sessions/{id}/close ─────────────────────────────────────
    public function close(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $sess   = $this->_getOwnedSession($id, (int)$claims['lecturer_id']);

        $db = $this->db();
        $db->prepare("
            UPDATE attendance_sessions
            SET session_status = 'closed',
                current_qr_code = NULL,
                qr_code_expires_at = NULL,
                manual_code = NULL,
                manual_code_expires_at = NULL
            WHERE session_id = ?
        ")->execute([$id]);

        $this->audit((int)$claims['lecturer_id'], 'lecturer', 'SESSION_CLOSED', [
            'session_id' => $id,
            'class_id'   => $sess['class_id'],
        ]);

        $this->ok(null, 'Session closed.');
    }

    // ── POST /api/sessions/{id}/extend ────────────────────────────────────
    public function extend(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $this->_getOwnedSession($id, (int)$claims['lecturer_id']);

        $db  = $this->db();
        $ttl = $this->_getQRTTL((int)$claims['lecturer_id'], $db);

        $this->_rotateQR($db, $id, $ttl);
        $this->ok(null, 'QR code refreshed.');
    }

    // ── POST /api/sessions/{id}/refresh-geofence ──────────────────────────
    public function refreshGeofence(int $id, array $body): void
    {
        $claims = AuthMiddleware::lecturer();
        $this->_getOwnedSession($id, (int)$claims['lecturer_id']);

        $lat    = isset($body['latitude'])  ? (float)$body['latitude']  : null;
        $lng    = isset($body['longitude']) ? (float)$body['longitude'] : null;
        $radius = isset($body['radius'])    ? (int)$body['radius']      : null;

        if ($lat === null || $lng === null) {
            $this->fail(400, 'latitude and longitude are required.');
        }

        $db = $this->db();

        // Update the class geofence (used for future sessions too)
        $stmt = $db->prepare("
            UPDATE classes SET gps_latitude = ?, gps_longitude = ?
            " . ($radius ? ", geofence_radius_meters = ?" : "") . "
            WHERE class_id = (SELECT class_id FROM attendance_sessions WHERE session_id = ?)
        ");
        $params = $radius ? [$lat, $lng, $radius, $id] : [$lat, $lng, $id];
        $stmt->execute($params);

        $this->ok(['latitude' => $lat, 'longitude' => $lng], 'Geofence updated.');
    }

    // ── PATCH /api/sessions/{id} ──────────────────────────────────────────
    public function update(int $id, array $body): void
    {
        $claims = AuthMiddleware::lecturer();
        $this->_getOwnedSession($id, (int)$claims['lecturer_id']);

        $db = $this->db();

        if (!empty($body['rotate_qr'])) {
            $ttl = $this->_getQRTTL((int)$claims['lecturer_id'], $db);
            $this->_rotateQR($db, $id, $ttl);
            $this->ok(null, 'QR rotated.');
            return;
        }

        // Generic field update (e.g. session_type, status)
        $allowed = ['session_type', 'session_status'];
        $sets = []; $vals = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "{$f} = ?";
                $vals[] = $body[$f];
            }
        }
        if (empty($sets)) $this->fail(400, 'Nothing to update.');
        $vals[] = $id;
        $db->prepare("UPDATE attendance_sessions SET " . implode(', ', $sets) . " WHERE session_id = ?")
           ->execute($vals);

        $this->ok(null, 'Session updated.');
    }

    // ── GET /api/sessions/{id}/current-code ───────────────────────────────
    public function currentCode(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $sess   = $this->_getOwnedSession($id, (int)$claims['lecturer_id']);

        $db  = $this->db();
        $ttl = $this->_getQRTTL((int)$claims['lecturer_id'], $db);

        // Rotate if expired
        if (empty($sess['current_qr_code']) || (
            $sess['qr_code_expires_at'] &&
            new \DateTime($sess['qr_code_expires_at']) < new \DateTime()
        )) {
            $this->_rotateQR($db, $id, $ttl);
            $sess = $this->_fetchSession($db, $id);
        }

        $this->json([
            'qr_code_value'    => $sess['current_qr_code'],
            'manual_code_value'=> $sess['manual_code'],
            'expires_at'       => $sess['qr_code_expires_at'],
            'ttl_seconds'      => $ttl,
        ]);
    }

    // ── GET /api/sessions/{id}/recent-checkins ────────────────────────────
    public function recentCheckins(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $this->_getOwnedSession($id, (int)$claims['lecturer_id']);

        $stmt = $this->db()->prepare("
            SELECT  ar.attendance_id, ar.status, ar.verification_method,
                    ar.check_in_time, ar.latitude, ar.longitude,
                    s.student_name, s.index_number, s.profile_photo
            FROM    attendance_records ar
            JOIN    students s ON s.student_id = ar.student_id
            WHERE   ar.session_id = ?
              AND   ar.status IN ('present','late')
            ORDER   BY ar.check_in_time DESC
            LIMIT   60
        ");
        $stmt->execute([$id]);
        $this->json(['checkins' => $stmt->fetchAll()]);
    }

    // ── GET /api/sessions/{id}/live-stats ─────────────────────────────────
    public function liveStats(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $sess   = $this->_getOwnedSession($id, (int)$claims['lecturer_id']);

        $db = $this->db();

        // Enrolled count for this lecturer
        $enrolledStmt = $db->prepare(
            "SELECT COUNT(*) FROM students WHERE lecturer_id = ? AND enrollment_status = 'enrolled'"
        );
        $enrolledStmt->execute([$claims['lecturer_id']]);
        $enrolled = (int)$enrolledStmt->fetchColumn();

        $this->json([
            'session_id'              => $id,
            'total_students_present'  => (int)$sess['total_students_present'],
            'total_students_absent'   => (int)$sess['total_students_absent'],
            'total_students'          => $enrolled,
            'session_status'          => $sess['session_status'],
        ]);
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────

    private function _getOwnedSession(int $id, int $lecId): array
    {
        $sess = $this->_fetchSession($this->db(), $id);
        if (!$sess) $this->fail(404, 'Session not found.');
        if ((int)$sess['lecturer_id'] !== $lecId) $this->fail(403, 'Access denied.');
        return $sess;
    }

    private function _fetchSession(\PDO $db, int $id): ?array
    {
        $stmt = $db->prepare("
            SELECT  ass.*, c.class_name, c.course_code, c.lecturer_id,
                    c.gps_latitude, c.gps_longitude, c.geofence_radius_meters
            FROM    attendance_sessions ass
            JOIN    classes c ON c.class_id = ass.class_id
            WHERE   ass.session_id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function _rotateQR(\PDO $db, int $sessionId, int $ttl): void
    {
        $qrToken    = bin2hex(random_bytes(self::QR_CODE_LENGTH));
        $manualCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $expiry     = date('Y-m-d H:i:s', time() + $ttl);

        $db->prepare("
            UPDATE attendance_sessions
            SET    current_qr_code        = ?,
                   qr_code_expires_at     = ?,
                   manual_code            = ?,
                   manual_code_expires_at = ?
            WHERE  session_id = ?
        ")->execute([$qrToken, $expiry, $manualCode, $expiry, $sessionId]);

        // Log to dynamic_qr_codes for audit trail
        $db->prepare("
            INSERT INTO dynamic_qr_codes
                (session_id, qr_code_value, manual_code_value, expires_at, is_expired)
            VALUES (?, ?, ?, ?, 0)
        ")->execute([$sessionId, $qrToken, $manualCode, $expiry]);
    }

    private function _getQRTTL(int $lecId, \PDO $db): int
    {
        $stmt = $db->prepare("
            SELECT config_value FROM configuration_engine
            WHERE lecturer_id = ? AND config_key = 'qr_expiry_seconds'
            LIMIT 1
        ");
        $stmt->execute([$lecId]);
        $row = $stmt->fetch();
        return $row ? max(10, (int)$row['config_value']) : self::QR_TTL_DEFAULT;
    }
}