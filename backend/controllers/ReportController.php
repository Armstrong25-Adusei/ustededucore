<?php
/**
 * EduCore — ReportController  v1.0
 *
 * GET /api/reports/summary   — overall attendance summary across all courses
 * GET /api/reports/heatmap   — daily attendance rates for charting
 * GET /api/reports/risk      — students at risk (<75% attendance)
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';

class ReportController extends BaseController
{
    // ── GET /api/reports/summary ──────────────────────────────────────────
    public function summary(): void
    {
        $claims  = AuthMiddleware::lecturer();
        $lecId   = (int)$claims['lecturer_id'];
        $db      = $this->db();

        $classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : null;
        $from    = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $to      = $_GET['to']   ?? date('Y-m-d');

        $where  = ['c.lecturer_id = ?', 'ass.session_date BETWEEN ? AND ?'];
        $params = [$lecId, $from, $to];
        if ($classId) { $where[] = 'c.class_id = ?'; $params[] = $classId; }
        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        // Overall aggregate
        $aggStmt = $db->prepare("
            SELECT
                COUNT(DISTINCT ass.session_id)                                             AS total_sessions,
                SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END)                   AS total_present,
                SUM(CASE WHEN ar.status = 'absent'  THEN 1 ELSE 0 END)                   AS total_absent,
                SUM(CASE WHEN ar.status = 'late'    THEN 1 ELSE 0 END)                   AS total_late,
                SUM(CASE WHEN ar.status = 'excused' THEN 1 ELSE 0 END)                   AS total_excused,
                COUNT(ar.attendance_id)                                                    AS total_records,
                ROUND(
                    SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END)*100.0
                    / NULLIF(COUNT(ar.attendance_id),0), 1
                ) AS attendance_rate
            FROM   attendance_records ar
            JOIN   attendance_sessions ass ON ass.session_id = ar.session_id
            JOIN   classes c ON c.class_id = ass.class_id
            $whereSQL
        ");
        $aggStmt->execute($params);
        $summary = $aggStmt->fetch();

        // Per-course breakdown
        $courseStmt = $db->prepare("
            SELECT  c.class_id, c.class_name, c.course_code,
                    COUNT(DISTINCT ass.session_id)  AS sessions,
                    SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END) AS present,
                    COUNT(ar.attendance_id) AS total,
                    ROUND(
                        SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END)*100.0
                        / NULLIF(COUNT(ar.attendance_id),0), 1
                    ) AS rate
            FROM    attendance_records ar
            JOIN    attendance_sessions ass ON ass.session_id = ar.session_id
            JOIN    classes c ON c.class_id = ass.class_id
            $whereSQL
            GROUP   BY c.class_id
            ORDER   BY rate ASC
        ");
        $courseStmt->execute($params);
        $byCourse = $courseStmt->fetchAll();

        $this->json([
            'summary'   => $summary,
            'by_course' => $byCourse,
            'from'      => $from,
            'to'        => $to,
        ]);
    }

    // ── GET /api/reports/heatmap ──────────────────────────────────────────
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
                    COUNT(DISTINCT ass.session_id) AS sessions,
                    SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END) AS present_count,
                    COUNT(ar.attendance_id) AS total_count,
                    ROUND(
                        SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END)*100.0
                        / NULLIF(COUNT(ar.attendance_id),0), 1
                    ) AS rate
            FROM    attendance_records ar
            JOIN    attendance_sessions ass ON ass.session_id = ar.session_id
            JOIN    classes c ON c.class_id = ass.class_id
            WHERE   " . implode(' AND ', $where) . "
            GROUP   BY ass.session_date
            ORDER   BY ass.session_date ASC
        ");
        $stmt->execute($params);
        $this->json(['heatmap' => $stmt->fetchAll(), 'from' => $from, 'to' => $to]);
    }

    // ── GET /api/reports/risk ─────────────────────────────────────────────
    public function riskStudents(): void
    {
        $claims    = AuthMiddleware::lecturer();
        $lecId     = (int)$claims['lecturer_id'];
        $threshold = (float)($_GET['threshold'] ?? 75.0);
        $db        = $this->db();

        $stmt = $db->prepare("
            SELECT  s.student_id, s.student_name, s.index_number, s.program,
                    c.class_id, c.class_name, c.course_code,
                    COUNT(DISTINCT ass.session_id) AS total_sessions,
                    SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END) AS present_count,
                    ROUND(
                        SUM(CASE WHEN ar.status IN('present','late') THEN 1 ELSE 0 END)*100.0
                        / NULLIF(COUNT(ar.attendance_id),0), 1
                    ) AS attendance_rate
            FROM    attendance_records ar
            JOIN    students s   ON s.student_id   = ar.student_id
            JOIN    attendance_sessions ass ON ass.session_id = ar.session_id
            JOIN    classes c    ON c.class_id     = ass.class_id
            WHERE   c.lecturer_id = ?
            GROUP   BY s.student_id, c.class_id
            HAVING  attendance_rate IS NOT NULL AND attendance_rate < ?
            ORDER   BY attendance_rate ASC
            LIMIT   200
        ");
        $stmt->execute([$lecId, $threshold]);
        $this->json(['risk_students' => $stmt->fetchAll(), 'threshold' => $threshold]);
    }
}
