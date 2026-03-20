<?php
/**
 * EduCore — StudentController  v1.0  (Lecturer-facing)
 *
 * GET    /api/students                          — list all students for lecturer
 * GET    /api/students/lookup?generated_id=…    — find student by generated_id
 * GET    /api/students/{id}                     — student detail
 * PATCH  /api/students/{id}                     — update student status / reset device
 * GET    /api/students/{id}/biometrics          — biometric enrolment status
 * POST   /api/students/{id}/biometrics/fingerprint — enrol fingerprint
 * POST   /api/students/{id}/biometrics/face      — enrol face
 * DELETE /api/students/{id}/biometrics           — delete biometrics
 * POST   /api/students/{id}/devices/reset        — reset all active devices
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';

class StudentController extends BaseController
{
    // ── GET /api/students ─────────────────────────────────────────────────
    public function index(): void
    {
        $claims  = AuthMiddleware::lecturer();
        $lecId   = (int)$claims['lecturer_id'];
        $db      = $this->db();

        $classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : null;
        $status  = $_GET['enrollment_status'] ?? null;
        $search  = trim($_GET['q'] ?? '');
        $limit   = min((int)($_GET['limit'] ?? 500), 2000);

        // Schema note: no class_students pivot exists.
        // Students belong to a lecturer via students.lecturer_id.
        // When class_id is supplied, filter to students who have attendance records
        // in sessions for that class (inline subquery; class_id is interpolated as
        // a validated integer so no injection risk).

        $where  = ['s.lecturer_id = ?'];
        $params = [$lecId];

        if ($status) { $where[] = 's.enrollment_status = ?'; $params[] = $status; }
        if ($search) {
            $where[]  = '(s.student_name LIKE ? OR s.index_number LIKE ? OR s.email LIKE ?)';
            $like     = '%' . $search . '%';
            $params   = array_merge($params, [$like, $like, $like]);
        }

        // Class filter: inline subquery avoids parameter-order confusion entirely.
        $classJoin = '';
        if ($classId) {
            $safeId    = intval($classId);
            $classJoin = "JOIN (
                            SELECT DISTINCT ar.student_id
                            FROM   attendance_records ar
                            JOIN   attendance_sessions asess ON asess.session_id = ar.session_id
                            WHERE  asess.class_id = {$safeId}
                          ) cf ON cf.student_id = s.student_id";
        }

        $stmt = $db->prepare("
            SELECT  s.student_id, s.student_name, s.index_number, s.email,
                    s.phone, s.program, s.profile_photo,
                    s.enrollment_status, s.account_status,
                    s.enrollment_date, s.last_attendance,
                    s.generated_id,
                    (SELECT COUNT(*) FROM student_devices sd
                     WHERE sd.student_id = s.student_id AND sd.status = 'active') AS device_count,
                    (SELECT COUNT(*) FROM student_devices sd
                     WHERE sd.student_id = s.student_id AND sd.status = 'active') > 0 AS has_device
            FROM    students s
            {$classJoin}
            WHERE   " . implode(' AND ', $where) . "
            ORDER   BY s.student_name ASC
            LIMIT   ?
        ");
        $params[] = $limit;
        $stmt->execute($params);
        $students = $stmt->fetchAll();

        $this->json(['students' => $students, 'total' => count($students)]);
    }

    // ── GET /api/students/lookup ──────────────────────────────────────────
    public function lookup(): void
    {
        $claims    = AuthMiddleware::lecturer();
        $lecId     = (int)$claims['lecturer_id'];
        $generated = trim($_GET['generated_id'] ?? '');
        if (!$generated) $this->fail(400, 'generated_id is required.');

        $stmt = $this->db()->prepare("
            SELECT student_id, student_name, index_number, email, enrollment_status
            FROM   students
            WHERE  generated_id = ? AND lecturer_id = ?
            LIMIT  1
        ");
        $stmt->execute([$generated, $lecId]);
        $row = $stmt->fetch();
        if (!$row) $this->fail(404, 'Student not found.');
        $this->json($row);
    }

    // ── GET /api/students/{id} ────────────────────────────────────────────
    public function show(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $db     = $this->db();

        $stu = $this->_getOwnedStudent($db, $id, $lecId);

        // Attendance summary for this student
        $attStmt = $db->prepare("
            SELECT
                COUNT(ar.attendance_id) AS total,
                SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END) AS present,
                ROUND(SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END)*100.0
                    / NULLIF(COUNT(ar.attendance_id),0), 1) AS rate
            FROM  attendance_records ar
            JOIN  attendance_sessions ass ON ass.session_id = ar.session_id
            JOIN  classes c ON c.class_id = ass.class_id
            WHERE ar.student_id = ? AND c.lecturer_id = ?
        ");
        $attStmt->execute([$id, $lecId]);
        $stu['attendance'] = $attStmt->fetch();

        // Devices
        $devStmt = $db->prepare(
            "SELECT device_id, device_hash, device_name, device_type, status, first_login, last_login
             FROM student_devices WHERE student_id = ? ORDER BY first_login ASC"
        );
        $devStmt->execute([$id]);
        $stu['devices'] = $devStmt->fetchAll();

        $this->json($stu);
    }

    // ── PATCH /api/students/{id} ──────────────────────────────────────────
    public function updateStatus(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $b      = $this->jsonBody();
        $db     = $this->db();

        $this->_getOwnedStudent($db, $id, $lecId);

        $allowed = ['account_status', 'enrollment_status', 'program', 'phone'];
        $sets = []; $vals = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $b)) {
                $sets[] = "{$f} = ?";
                $vals[] = $b[$f];
            }
        }

        // Device reset flag
        if (!empty($b['reset_device'])) {
            $db->prepare(
                "UPDATE student_devices SET status = 'revoked' WHERE student_id = ? AND status = 'active'"
            )->execute([$id]);

            $this->audit($lecId, 'lecturer', 'DEVICE_RESET_BY_LECTURER', [
                'student_id' => $id,
            ]);
        }

        if (!empty($sets)) {
            $vals[] = $id;
            $db->prepare(
                "UPDATE students SET " . implode(', ', $sets) . " WHERE student_id = ?"
            )->execute($vals);
        }

        $this->ok(null, 'Student updated.');
    }

    // ── GET /api/students/{id}/biometrics ─────────────────────────────────
    public function biometricStatus(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $db     = $this->db();

        $this->_getOwnedStudent($db, $id, $lecId);

        $stmt = $db->prepare(
            "SELECT biometric_id, primary_finger, fingerprint_quality,
                    face_quality, enrollment_complete, capture_method,
                    is_verified, created_at, updated_at
             FROM student_biometrics WHERE student_id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $bio = $stmt->fetch();

        $this->json([
            'student_id'         => $id,
            'has_fingerprint'    => !empty($bio['fingerprint_template'] ?? null) || ($bio && $bio['fingerprint_quality'] > 0),
            'has_face'           => !empty($bio['face_embedding'] ?? null) || ($bio && $bio['face_quality'] > 0),
            'enrollment_complete'=> $bio ? (bool)$bio['enrollment_complete'] : false,
            'biometrics'         => $bio ?: null,
        ]);
    }

    // ── POST /api/students/{id}/biometrics/fingerprint ────────────────────
    public function enrollFingerprint(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $b      = $this->jsonBody();
        $db     = $this->db();

        $this->_getOwnedStudent($db, $id, $lecId);

        $template = $b['fingerprint_template'] ?? null;
        $finger   = $b['primary_finger']       ?? 'RIGHT_INDEX';
        $quality  = (float)($b['quality']       ?? 0.0);

        if (!$template) $this->fail(400, 'fingerprint_template is required.');

        $db->prepare("
            INSERT INTO student_biometrics (student_id, fingerprint_template, primary_finger, fingerprint_quality, capture_method)
            VALUES (?, ?, ?, ?, 'biostation_usb')
            ON DUPLICATE KEY UPDATE
                fingerprint_template = VALUES(fingerprint_template),
                primary_finger       = VALUES(primary_finger),
                fingerprint_quality  = VALUES(fingerprint_quality),
                updated_at           = NOW()
        ")->execute([$id, $template, $finger, $quality]);

        $this->audit($lecId, 'lecturer', 'FINGERPRINT_ENROLLED', ['student_id' => $id]);
        $this->ok(null, 'Fingerprint enrolled.');
    }

    // ── POST /api/students/{id}/biometrics/face ───────────────────────────
    public function enrollFace(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $b      = $this->jsonBody();
        $db     = $this->db();

        $this->_getOwnedStudent($db, $id, $lecId);

        $embedding = $b['face_embedding']   ?? null;
        $landmarks = $b['facial_landmarks'] ?? null;
        $quality   = (float)($b['quality']  ?? 0.0);

        if (!$embedding) $this->fail(400, 'face_embedding is required.');

        $db->prepare("
            INSERT INTO student_biometrics (student_id, face_embedding, facial_landmarks, face_quality, capture_method)
            VALUES (?, ?, ?, ?, 'edulink_selfie')
            ON DUPLICATE KEY UPDATE
                face_embedding   = VALUES(face_embedding),
                facial_landmarks = VALUES(facial_landmarks),
                face_quality     = VALUES(face_quality),
                updated_at       = NOW()
        ")->execute([$id, $embedding, $landmarks, $quality]);

        $this->audit($lecId, 'lecturer', 'FACE_ENROLLED', ['student_id' => $id]);
        $this->ok(null, 'Face enrolled.');
    }

    // ── DELETE /api/students/{id}/biometrics ──────────────────────────────
    public function deleteBiometrics(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $db     = $this->db();

        $this->_getOwnedStudent($db, $id, $lecId);

        $db->prepare("DELETE FROM student_biometrics WHERE student_id = ?")
           ->execute([$id]);

        $this->audit($lecId, 'lecturer', 'BIOMETRICS_DELETED', ['student_id' => $id]);
        $this->ok(null, 'Biometrics deleted.');
    }

    // ── POST /api/students/{id}/devices/reset ─────────────────────────────
    public function resetStudentDevices(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $db     = $this->db();

        $this->_getOwnedStudent($db, $id, $lecId);

        $db->prepare(
            "UPDATE student_devices SET status = 'revoked' WHERE student_id = ? AND status = 'active'"
        )->execute([$id]);

        $this->audit($lecId, 'lecturer', 'DEVICE_RESET_BY_LECTURER', [
            'student_id' => $id,
        ]);

        $this->ok(null, 'Devices reset. Student can now register a new device.');
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────

    private function _getOwnedStudent(\PDO $db, int $id, int $lecId): array
    {
        $stmt = $db->prepare(
            "SELECT * FROM students WHERE student_id = ? AND lecturer_id = ? LIMIT 1"
        );
        $stmt->execute([$id, $lecId]);
        $stu = $stmt->fetch();
        if (!$stu) $this->fail(404, 'Student not found.');
        return $stu;
    }
}