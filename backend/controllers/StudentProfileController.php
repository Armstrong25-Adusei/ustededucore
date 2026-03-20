<?php
/**
 * EduCore — StudentProfileController  v7.0
 *
 * All routes require a valid student JWT.
 *
 * GET   /api/student/me
 * GET   /api/student/profile
 * PATCH /api/student/profile
 * GET   /api/student/security           ← returns device list from student_devices
 * POST  /api/student/device/remove      ← student removes one of their own devices
 * POST  /api/student/device/unbind-request  ← asks lecturer to reset ALL devices
 * GET   /api/student/audit
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';

class StudentProfileController extends BaseController
{
    // ══════════════════════════════════════════════════════════════════
    // GET /api/student/me  (also /api/student/profile)
    // ══════════════════════════════════════════════════════════════════

    public function getMe(): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();

        $stmt = $db->prepare("
            SELECT  s.student_id, s.student_name, s.index_number,
                    s.email, s.phone, s.program,
                    s.enrollment_status, s.registered_by,
                    s.profile_photo, s.enrollment_date,
                    i.institution_name, i.institution_type,
                    l.full_name AS lecturer_name
            FROM    students s
            LEFT JOIN lecturers    l ON l.lecturer_id    = s.lecturer_id
            LEFT JOIN institutions i ON i.institution_id = l.institution_id
            WHERE   s.student_id = ?
        ");
        $stmt->execute([$claims['student_id']]);
        $row = $stmt->fetch();
        if (!$row) $this->fail(404, 'Student not found.');

        $row['profile_photo'] = $this->photoIfExists($row['profile_photo'] ?? null);

        // Append active device count
        $cntQ = $db->prepare(
            "SELECT COUNT(*) FROM student_devices WHERE student_id = ? AND status = 'active'"
        );
        $cntQ->execute([$claims['student_id']]);
        $row['active_device_count'] = (int)$cntQ->fetchColumn();
        $row['max_devices']         = 2;

        $this->json($row);
    }

    /** Returns photo path only if file exists; otherwise null. */
    private function photoIfExists(?string $path): ?string
    {
        if ($path === null) return null;
        $raw = trim($path);
        if ($raw === '') return null;

        if (preg_match('/^(https?:)?\/\//i', $raw) || str_starts_with($raw, 'data:')) {
            return $raw;
        }

        $normalized = str_replace('\\', '/', ltrim($raw, '/'));
        $pos = strpos($normalized, 'uploads/');
        if ($pos === false) {
            return $raw;
        }

        $relative = substr($normalized, $pos);
        $absolute = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        return is_file($absolute) ? $relative : null;
    }

    // ══════════════════════════════════════════════════════════════════
    // PATCH /api/student/profile
    // Updatable fields: phone, profile_photo
    // ══════════════════════════════════════════════════════════════════

    public function updateProfile(): void
    {
        $claims = AuthMiddleware::student();
        $b      = $this->jsonBody();

        $allowed = ['phone', 'profile_photo'];
        $sets    = [];
        $vals    = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $b)) {
                $sets[] = "{$field} = ?";
                $vals[] = trim((string)($b[$field] ?? '')) ?: null;
            }
        }

        if (empty($sets)) {
            $this->fail(400, 'Nothing to update. Allowed fields: phone, profile_photo.');
        }

        $vals[] = $claims['student_id'];
        $this->db()->prepare(
            "UPDATE students SET " . implode(', ', $sets) . " WHERE student_id = ?"
        )->execute($vals);

        $this->audit($claims['student_id'], 'student', 'PROFILE_UPDATED',
            ['fields' => array_keys($b)]);

        $this->ok(null, 'Profile updated successfully.');
    }

    // ══════════════════════════════════════════════════════════════════
    // GET /api/student/security
    // Returns all active devices plus remaining slot count
    // ══════════════════════════════════════════════════════════════════

    public function getSecurity(): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();

        $stmt = $db->prepare("
            SELECT  device_id, device_hash, device_name, device_type,
                    browser, first_login, last_login, status
            FROM    student_devices
            WHERE   student_id = ?
            ORDER   BY first_login ASC
        ");
        $stmt->execute([$claims['student_id']]);
        $devices = $stmt->fetchAll();

        $activeCount = count(array_filter($devices, fn($d) => $d['status'] === 'active'));

        $this->json([
            'devices'            => $devices,
            'active_count'       => $activeCount,
            'max_devices'        => 2,
            'slots_remaining'    => max(0, 2 - $activeCount),
            'can_add_device'     => $activeCount < 2,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // POST /api/student/device/remove
    // Student removes one of their own devices (frees a slot)
    // Body: { device_id }
    // ══════════════════════════════════════════════════════════════════

    public function removeDevice(): void
    {
        $claims   = AuthMiddleware::student();
        $b        = $this->jsonBody();
        $deviceId = (int)($b['device_id'] ?? 0);

        if (!$deviceId) $this->fail(400, 'device_id is required.');

        $db   = $this->db();

        // Verify this device belongs to this student
        $stmt = $db->prepare("
            SELECT device_id, device_hash, device_name
            FROM   student_devices
            WHERE  device_id = ? AND student_id = ?
        ");
        $stmt->execute([$deviceId, $claims['student_id']]);
        $dev = $stmt->fetch();

        if (!$dev) $this->fail(404, 'Device not found or does not belong to your account.');

        // Prevent removing the last active device (student would be locked out)
        $cntStmt = $db->prepare(
            "SELECT COUNT(*) FROM student_devices WHERE student_id = ? AND status = 'active'"
        );
        $cntStmt->execute([$claims['student_id']]);
        $activeCount = (int)$cntStmt->fetchColumn();

        if ($activeCount <= 1) {
            $this->fail(400, 'You cannot remove your only active device. You would be locked out. Contact your lecturer if you need a full device reset.');
        }

        // Soft-delete: status='revoked' keeps audit history
        $db->prepare("UPDATE student_devices SET status = 'revoked' WHERE device_id = ?")
           ->execute([$deviceId]);

        $this->audit($claims['student_id'], 'student', 'DEVICE_REMOVED_BY_STUDENT', [
            'device_id'   => $deviceId,
            'device_uuid' => $dev['device_hash'],
            'device_name' => $dev['device_name'],
        ]);

        $this->ok(null, "Device \"{$dev['device_name']}\" removed. You can now register a new device.");
    }

    // ══════════════════════════════════════════════════════════════════
    // POST /api/student/device/unbind-request
    // Asks lecturer to reset ALL devices (full reset)
    // ══════════════════════════════════════════════════════════════════

    public function requestUnbind(): void
    {
        $claims = AuthMiddleware::student();
        $db     = $this->db();

        $stuQ = $db->prepare("
            SELECT s.student_name, s.email, s.lecturer_id
            FROM   students s WHERE s.student_id = ?
        ");
        $stuQ->execute([$claims['student_id']]);
        $stu = $stuQ->fetch();

        if (!$stu) $this->fail(404, 'Student not found.');

        // Check active devices exist
        $cntQ = $db->prepare(
            "SELECT COUNT(*) FROM student_devices WHERE student_id = ? AND status = 'active'"
        );
        $cntQ->execute([$claims['student_id']]);
        $activeCount = (int)$cntQ->fetchColumn();

        if ($activeCount === 0) {
            $this->fail(409, 'No active devices are currently bound to your account.');
        }

        // Prevent duplicate requests within 24 hours
        $dupQ = $db->prepare("
            SELECT log_id FROM audit_logs
            WHERE  actor_id   = ?
              AND  actor_type = 'student'
              AND  action     = 'DEVICE_UNBIND_REQUEST'
              AND  logged_at  > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            LIMIT  1
        ");
        $dupQ->execute([$claims['student_id']]);
        if ($dupQ->fetch()) {
            $this->fail(429, 'A reset request was already submitted in the last 24 hours. Your lecturer will action it shortly.');
        }

        $this->audit($claims['student_id'], 'student', 'DEVICE_UNBIND_REQUEST', [
            'active_device_count' => $activeCount,
            'student_name'        => $stu['student_name'],
            'email'               => $stu['email'],
        ]);

        // Email lecturer
        if ($stu['lecturer_id']) {
            $lQ = $db->prepare("SELECT email, full_name FROM lecturers WHERE lecturer_id = ?");
            $lQ->execute([$stu['lecturer_id']]);
            $lect = $lQ->fetch();
            if ($lect) {
                require_once __DIR__ . '/../utils/MailHelper.php';
                MailHelper::sendDeviceUnbindRequest(
                    $lect['email'],
                    $lect['full_name'],
                    $stu['student_name'],
                    $stu['email']
                );
            }
        }

        $this->ok(null, 'Device reset request submitted. Your lecturer will clear your devices shortly so you can log in fresh.');
    }

    // ══════════════════════════════════════════════════════════════════
    // GET /api/student/audit
    // Student's own audit log
    // ══════════════════════════════════════════════════════════════════

    public function getAudit(): void
    {
        $claims = AuthMiddleware::student();
        $limit  = min((int)($_GET['limit'] ?? 30), 100);
        $page   = max((int)($_GET['page']  ?? 1),  1);
        $offset = ($page - 1) * $limit;

        $stmt = $this->db()->prepare("
            SELECT log_id, action, device_uuid, meta, logged_at
            FROM   audit_logs
            WHERE  actor_id   = ?
              AND  actor_type = 'student'
            ORDER  BY logged_at DESC
            LIMIT  ? OFFSET ?
        ");
        $stmt->bindValue(1, $claims['student_id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,                PDO::PARAM_INT);
        $stmt->bindValue(3, $offset,               PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['meta'] = $r['meta'] ? (json_decode($r['meta'], true) ?? []) : [];
        }
        unset($r);

        $this->json(['logs' => $rows, 'page' => $page, 'limit' => $limit]);
    }
}