<?php
/**
 * EduCore — ClassController  v1.0
 *
 * GET    /api/classes                  — list all courses for this lecturer
 * POST   /api/classes                  — create a new course
 * GET    /api/classes/{id}             — course detail with stats
 * PUT    /api/classes/{id}             — update course fields
 * DELETE /api/classes/{id}             — soft-archive a course
 * POST   /api/classes/{id}/regen       — regenerate join code
 * GET    /api/classes/{id}/students    — students enrolled via this class's join_code
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';

class ClassController extends BaseController
{
    // ── GET /api/classes ──────────────────────────────────────────────────
    public function index(): void
    {
        $claims  = AuthMiddleware::lecturer();
        $lecId   = (int)$claims['lecturer_id'];
        $db      = $this->db();

        $stmt = $db->prepare("
            SELECT  c.class_id, c.class_name, c.course_code, c.join_code,
                    c.level, c.program, c.semester, c.academic_year,
                    c.gps_latitude, c.gps_longitude, c.geofence_radius_meters,
                    c.is_archived, c.created_at,
                    COUNT(DISTINCT s.student_id)    AS student_count,
                    COUNT(DISTINCT ass.session_id)  AS session_count,
                    ROUND(
                        SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END)*100.0
                        / NULLIF(COUNT(ar.attendance_id), 0)
                    , 1) AS attendance_rate
            FROM    classes c
            LEFT JOIN students s   ON s.lecturer_id = c.lecturer_id AND s.enrollment_status = 'enrolled'
            LEFT JOIN attendance_sessions ass ON ass.class_id = c.class_id
            LEFT JOIN attendance_records ar   ON ar.session_id = ass.session_id
            WHERE   c.lecturer_id = ? AND c.is_archived = 0
            GROUP   BY c.class_id
            ORDER   BY c.created_at DESC
        ");
        $stmt->execute([$lecId]);
        $classes = $stmt->fetchAll();

        $this->json($classes);
    }

    // ── POST /api/classes ─────────────────────────────────────────────────
    public function create(): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $b      = $this->jsonBody();

        $name = trim($b['class_name'] ?? '');
        if (!$name) $this->fail(400, 'class_name is required.');

        $joinCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        $db = $this->db();
        $db->prepare("
            INSERT INTO classes
                (lecturer_id, class_name, course_code, join_code, level,
                 program, semester, academic_year, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $lecId,
            $name,
            trim($b['course_code']   ?? '') ?: null,
            $joinCode,
            trim($b['level']         ?? '') ?: null,
            trim($b['program']       ?? '') ?: null,
            trim($b['semester']      ?? '') ?: null,
            trim($b['academic_year'] ?? '') ?: null,
        ]);

        $classId = (int)$db->lastInsertId();

        $this->audit($lecId, 'lecturer', 'CLASS_CREATED', [
            'class_id'   => $classId,
            'class_name' => $name,
        ]);

        $this->json([
            'class_id'  => $classId,
            'join_code' => $joinCode,
            'message'   => 'Course created.',
        ], 201);
    }

    // ── GET /api/classes/{id} ─────────────────────────────────────────────
    public function show(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $class  = $this->_getOwnedClass($id, $lecId);

        $db     = $this->db();

        // Recent sessions
        $sessStmt = $db->prepare("
            SELECT session_id, session_date, session_time, session_type,
                   session_status, total_students_present, total_students_absent
            FROM   attendance_sessions
            WHERE  class_id = ?
            ORDER  BY session_date DESC, session_time DESC
            LIMIT  10
        ");
        $sessStmt->execute([$id]);
        $class['recent_sessions'] = $sessStmt->fetchAll();

        // Student count
        $cntStmt = $db->prepare(
            "SELECT COUNT(*) FROM students WHERE lecturer_id = ? AND enrollment_status = 'enrolled'"
        );
        $cntStmt->execute([$lecId]);
        $class['student_count'] = (int)$cntStmt->fetchColumn();

        $this->json($class);
    }

    // ── PUT /api/classes/{id} ─────────────────────────────────────────────
    public function update(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $this->_getOwnedClass($id, $lecId);
        $b = $this->jsonBody();

        $allowed = ['class_name','course_code','level','program',
                    'semester','academic_year','gps_latitude','gps_longitude',
                    'geofence_radius_meters'];
        $sets = []; $vals = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $b)) {
                $sets[] = "{$f} = ?";
                $vals[] = $b[$f];
            }
        }
        if (empty($sets)) $this->fail(400, 'Nothing to update.');
        $vals[] = $id;
        $this->db()->prepare(
            "UPDATE classes SET " . implode(', ', $sets) . " WHERE class_id = ?"
        )->execute($vals);

        $this->ok(null, 'Course updated.');
    }

    // ── DELETE /api/classes/{id} ──────────────────────────────────────────
    public function delete(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $this->_getOwnedClass($id, $lecId);

        $this->db()->prepare("UPDATE classes SET is_archived = 1 WHERE class_id = ?")
             ->execute([$id]);

        $this->audit($lecId, 'lecturer', 'CLASS_ARCHIVED', ['class_id' => $id]);
        $this->ok(null, 'Course archived.');
    }

    // ── POST /api/classes/{id}/regen ──────────────────────────────────────
    public function regenJoinCode(int $id): void
    {
        $claims   = AuthMiddleware::lecturer();
        $lecId    = (int)$claims['lecturer_id'];
        $this->_getOwnedClass($id, $lecId);

        $newCode  = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $this->db()->prepare("UPDATE classes SET join_code = ? WHERE class_id = ?")
             ->execute([$newCode, $id]);

        $this->audit($lecId, 'lecturer', 'JOIN_CODE_REGEN', [
            'class_id' => $id, 'new_code' => $newCode,
        ]);

        $this->json(['join_code' => $newCode, 'message' => 'Join code regenerated.']);
    }

    // ── GET /api/classes/{id}/students ────────────────────────────────────
    public function students(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $this->_getOwnedClass($id, $lecId);

        $stmt = $this->db()->prepare("
            SELECT  s.student_id, s.student_name, s.index_number, s.email,
                    s.program, s.enrollment_status, s.last_attendance,
                    (SELECT COUNT(*) FROM student_devices sd
                     WHERE sd.student_id = s.student_id AND sd.status = 'active') AS device_count
            FROM    students s
            WHERE   s.lecturer_id = ? AND s.enrollment_status = 'enrolled'
            ORDER   BY s.student_name ASC
        ");
        $stmt->execute([$lecId]);
        $this->json(['students' => $stmt->fetchAll()]);
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────

    private function _getOwnedClass(int $id, int $lecId): array
    {
        $stmt = $this->db()->prepare(
            "SELECT * FROM classes WHERE class_id = ? AND lecturer_id = ? LIMIT 1"
        );
        $stmt->execute([$id, $lecId]);
        $cls = $stmt->fetch();
        if (!$cls) $this->fail(404, 'Course not found.');
        return $cls;
    }
}
