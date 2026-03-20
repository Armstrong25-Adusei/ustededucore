<?php
/**
 * EduCore — OverrideController  v1.0
 *
 * POST /api/override/request           — student submits (also handled by StudentDataController)
 * GET  /api/override/pending           — lecturer gets all pending (and history)
 * POST /api/override/{id}/approve      — lecturer approves
 * POST /api/override/{id}/reject       — lecturer rejects
 * GET  /api/override/session/{sessId}  — all requests for a session
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';

class OverrideController extends BaseController
{
    // ── GET /api/override/pending ─────────────────────────────────────────
    /**
     * Returns ALL override requests for this lecturer (all statuses).
     * The frontend filters by status client-side.
     */
    public function getPending(): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];

        $status = $_GET['status'] ?? null; // optional filter
        $limit  = min((int)($_GET['limit'] ?? 100), 500);

        $where  = ['o.lecturer_id = ?'];
        $params = [$lecId];

        if ($status && in_array($status, ['pending','approved','rejected'])) {
            $where[]  = 'o.status = ?';
            $params[] = $status;
        }

        $stmt = $this->db()->prepare("
            SELECT  o.override_id, o.session_id, o.class_id, o.student_id,
                    o.status, o.override_reason, o.biometric_attempts,
                    o.geofence_passed, o.student_lat, o.student_lng,
                    o.requested_at, o.decided_at,
                    s.student_name, s.index_number, s.program, s.profile_photo,
                    c.class_name, c.course_code
            FROM    override_requests o
            JOIN    students s ON s.student_id = o.student_id
            JOIN    classes  c ON c.class_id   = o.class_id
            WHERE   " . implode(' AND ', $where) . "
            ORDER   BY FIELD(o.status,'pending','approved','rejected'), o.requested_at DESC
            LIMIT   ?
        ");
        $params[] = $limit;
        $stmt->execute($params);
        $requests = $stmt->fetchAll();

        $this->json(['requests' => $requests, 'total' => count($requests)]);
    }

    // ── POST /api/override/{id}/approve ──────────────────────────────────
    public function approve(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $b      = $this->jsonBody();

        $db  = $this->db();
        $req = $this->_getOwnedRequest($db, $id, $lecId);

        if ($req['status'] !== 'pending') {
            $this->fail(409, 'Request has already been ' . $req['status'] . '.');
        }

        $db->beginTransaction();
        try {
            // Mark approved
            $db->prepare("
                UPDATE override_requests
                SET status = 'approved', decided_at = NOW()
                WHERE override_id = ?
            ")->execute([$id]);

            // Create / update attendance record as 'present' via manual_override
            $db->prepare("
                INSERT INTO attendance_records
                    (session_id, student_id, status, verification_method,
                     override_request_id, check_in_time)
                VALUES (?, ?, 'present', 'manual_override', ?, NOW())
                ON DUPLICATE KEY UPDATE
                    status = 'present',
                    verification_method = 'manual_override',
                    override_request_id = VALUES(override_request_id)
            ")->execute([$req['session_id'], $req['student_id'], $id]);

            // Reset student devices if this is a device-reset override
            if (!empty($b['reset_device'])) {
                $db->prepare(
                    "UPDATE student_devices SET status = 'revoked' WHERE student_id = ? AND status = 'active'"
                )->execute([$req['student_id']]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('[Override approve] ' . $e->getMessage());
            $this->fail(500, 'Failed to approve request.');
        }

        $this->audit($lecId, 'lecturer', 'OVERRIDE_APPROVED', [
            'override_id' => $id,
            'student_id'  => $req['student_id'],
        ]);

        $this->ok(null, 'Request approved. Attendance marked present.');
    }

    // ── POST /api/override/{id}/reject ────────────────────────────────────
    public function reject(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $b      = $this->jsonBody();

        $db  = $this->db();
        $req = $this->_getOwnedRequest($db, $id, $lecId);

        if ($req['status'] !== 'pending') {
            $this->fail(409, 'Request has already been ' . $req['status'] . '.');
        }

        $reason = trim($b['reason'] ?? $b['override_reason'] ?? '');

        $db->prepare("
            UPDATE override_requests
            SET status = 'rejected',
                decided_at = NOW(),
                override_reason = COALESCE(?, override_reason)
            WHERE override_id = ?
        ")->execute([$reason ?: null, $id]);

        $this->audit($lecId, 'lecturer', 'OVERRIDE_REJECTED', [
            'override_id' => $id,
            'reason'      => $reason,
        ]);

        $this->ok(null, 'Request rejected.');
    }

    // ── POST /api/override/request ────────────────────────────────────────
    /**
     * Submitted by the student-facing client. Duplicated here for the
     * lecturer-facing override panel ("force mark as present").
     */
    public function submitRequest(): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $b      = $this->jsonBody();

        $sessionId = (int)($b['session_id'] ?? 0);
        $studentId = (int)($b['student_id'] ?? 0);
        $classId   = (int)($b['class_id']   ?? 0);

        if (!$sessionId || !$studentId) $this->fail(400, 'session_id and student_id are required.');

        $db = $this->db();

        // Resolve class_id from session if not provided
        if (!$classId) {
            $r = $db->prepare("SELECT class_id FROM attendance_sessions WHERE session_id = ?");
            $r->execute([$sessionId]);
            $classId = (int)$r->fetchColumn();
        }

        // Check for duplicate
        $dup = $db->prepare(
            "SELECT override_id FROM override_requests WHERE student_id=? AND session_id=? LIMIT 1"
        );
        $dup->execute([$studentId, $sessionId]);
        if ($dup->fetch()) $this->fail(409, 'Override request already exists for this student and session.');

        $db->prepare("
            INSERT INTO override_requests
                (session_id, class_id, student_id, lecturer_id,
                 biometric_attempts, geofence_passed,
                 override_reason, status, requested_at)
            VALUES (?, ?, ?, ?, 0, 1, ?, 'pending', NOW())
        ")->execute([
            $sessionId, $classId, $studentId, $lecId,
            $b['reason'] ?? 'Lecturer-initiated override',
        ]);

        $this->created(['override_id' => (int)$db->lastInsertId()], 'Override request submitted.');
    }

    // ── GET /api/override/session/{sessId} ────────────────────────────────
    public function sessionHistory(int $sessId): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];

        $stmt = $this->db()->prepare("
            SELECT  o.*, s.student_name, s.index_number
            FROM    override_requests o
            JOIN    students s ON s.student_id = o.student_id
            WHERE   o.session_id = ? AND o.lecturer_id = ?
            ORDER   BY o.requested_at DESC
        ");
        $stmt->execute([$sessId, $lecId]);
        $this->json(['requests' => $stmt->fetchAll()]);
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────

    private function _getOwnedRequest(\PDO $db, int $id, int $lecId): array
    {
        $stmt = $db->prepare(
            "SELECT * FROM override_requests WHERE override_id = ? AND lecturer_id = ?"
        );
        $stmt->execute([$id, $lecId]);
        $req = $stmt->fetch();
        if (!$req) $this->fail(404, 'Override request not found.');
        return $req;
    }
}
