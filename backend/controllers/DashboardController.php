<?php
/**
 * EduCore — DashboardController  v1.0
 *
 * GET /api/dashboard
 * Returns a single JSON payload with all data the lec-dashboard.html
 * needs to render without issuing multiple parallel requests.
 *
 * Response shape:
 * {
 *   courses        : Course[]       — all courses for this lecturer
 *   total_students : int            — sum of enrolled students across all courses
 *   pending_reqs   : int            — pending override requests count
 *   attendance_rate: float|null     — overall weighted attendance rate (%)
 *   open_session   : Session|null   — currently open session (if any)
 *   recent_requests: OverrideReq[]  — first 4 pending override requests
 * }
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';

class DashboardController extends BaseController
{
    public function index(): void
    {
        $claims    = AuthMiddleware::lecturer();
        $lecId     = (int)$claims['lecturer_id'];
        $db        = $this->db();

        // ── Courses ───────────────────────────────────────────────────────────
        $cStmt = $db->prepare("
            SELECT  c.class_id, c.class_name, c.course_code, c.join_code,
                    c.level, c.program, c.semester, c.academic_year,
                    COUNT(DISTINCT s.student_id) AS student_count,
                    COALESCE(
                        ROUND(
                            SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) * 100.0
                            / NULLIF(COUNT(ar.attendance_id), 0)
                        , 1), 0
                    ) AS attendance_rate
            FROM    classes c
            LEFT JOIN students s
                   ON  s.lecturer_id = c.lecturer_id
            LEFT JOIN attendance_sessions ass
                   ON  ass.class_id = c.class_id
            LEFT JOIN attendance_records ar
                   ON  ar.session_id = ass.session_id
            WHERE   c.lecturer_id = ? AND c.is_archived = 0
            GROUP   BY c.class_id
            ORDER   BY c.created_at DESC
        ");
        $cStmt->execute([$lecId]);
        $courses = $cStmt->fetchAll();

        // ── Total students ────────────────────────────────────────────────────
        $totalStudents = 0;
        foreach ($courses as $c) {
            $totalStudents += (int)$c['student_count'];
        }

        // ── Pending override requests ─────────────────────────────────────────
        $rStmt = $db->prepare("
            SELECT  o.override_id, o.session_id, o.student_id, o.status,
                    o.override_reason, o.geofence_passed, o.biometric_attempts,
                    o.requested_at, o.decided_at,
                    s.student_name, s.index_number, s.program
            FROM    override_requests o
            JOIN    students s ON s.student_id = o.student_id
            WHERE   o.lecturer_id = ? AND o.status = 'pending'
            ORDER   BY o.requested_at DESC
            LIMIT   50
        ");
        $rStmt->execute([$lecId]);
        $allRequests = $rStmt->fetchAll();

        // ── Overall attendance rate ───────────────────────────────────────────
        $attStmt = $db->prepare("
            SELECT
                SUM(CASE WHEN ar.status IN ('present','late') THEN 1 ELSE 0 END) AS present_count,
                COUNT(ar.attendance_id) AS total_count
            FROM   attendance_records ar
            JOIN   attendance_sessions ass ON ass.session_id = ar.session_id
            JOIN   classes c ON c.class_id = ass.class_id
            WHERE  c.lecturer_id = ?
        ");
        $attStmt->execute([$lecId]);
        $attRow = $attStmt->fetch();
        $attRate = null;
        if ($attRow && (int)$attRow['total_count'] > 0) {
            $attRate = round((int)$attRow['present_count'] * 100 / (int)$attRow['total_count'], 1);
        }

        // ── Open session ──────────────────────────────────────────────────────
        $sessStmt = $db->prepare("
            SELECT  ass.session_id, ass.session_date, ass.session_time,
                    ass.session_type, ass.session_status,
                    ass.total_students_present, ass.total_students_absent,
                    ass.current_qr_code, ass.qr_code_expires_at,
                    ass.manual_code, ass.manual_code_expires_at,
                    c.class_name, c.course_code, c.class_id,
                    c.gps_latitude, c.gps_longitude, c.geofence_radius_meters
            FROM    attendance_sessions ass
            JOIN    classes c ON c.class_id = ass.class_id
            WHERE   c.lecturer_id = ?
              AND   ass.session_status IN ('open','in_progress')
            ORDER   BY ass.session_date DESC, ass.session_time DESC
            LIMIT   1
        ");
        $sessStmt->execute([$lecId]);
        $openSession = $sessStmt->fetch() ?: null;

        if ($openSession) {
            // Attach total enrolled students for progress bar calculation
            $enrolledStmt = $db->prepare(
                "SELECT COUNT(*) FROM students WHERE lecturer_id = ? AND enrollment_status = 'enrolled'"
            );
            $enrolledStmt->execute([$lecId]);
            $openSession['total_students'] = (int)$enrolledStmt->fetchColumn();
        }

        $this->json([
            'courses'         => $courses,
            'total_students'  => $totalStudents,
            'pending_reqs'    => count($allRequests),
            'attendance_rate' => $attRate,
            'open_session'    => $openSession,
            'recent_requests' => array_slice($allRequests, 0, 4),
            'summary' => [
                'attendance_rate'       => $attRate,
                'total_present'         => (int)($attRow['present_count'] ?? 0),
                'total_count'           => (int)($attRow['total_count'] ?? 0),
            ],
        ]);
    }
}
