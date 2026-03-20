<?php
/**
 * EduCore — MasterListController  v1.0
 *
 * GET    /api/master-list           — list all master_list entries for this lecturer
 * POST   /api/master-list/upload    — upload and parse CSV
 * DELETE /api/master-list/{id}      — delete a single entry
 *
 * CSV format (flexible column detection):
 *   Required : index_number (or "Index No", "Index Number", "ID")
 *   Required : student_name (or "Name", "Full Name", "Student Name")
 *   Optional : email, phone, program (or "Programme"), level
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';

class MasterListController extends BaseController
{
    // ── GET /api/master-list ──────────────────────────────────────────────
    public function index(): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $limit  = min((int)($_GET['limit'] ?? 1000), 5000);

        $stmt = $this->db()->prepare("
            SELECT id, index_number, institutional_id, student_name,
                   email, phone, program, level, is_claimed, uploaded_at
            FROM   master_list
            WHERE  lecturer_id = ?
            ORDER  BY uploaded_at DESC
            LIMIT  ?
        ");
        $stmt->execute([$lecId, $limit]);
        $this->json(['list' => $stmt->fetchAll(), 'total' => $stmt->rowCount()]);
    }

    // ── POST /api/master-list/upload ──────────────────────────────────────
    public function upload(): void
    {
        try {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];

        if (empty($_FILES['file'])) $this->fail(400, 'No file uploaded.');
        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) $this->fail(400, 'Upload error code: ' . $file['error']);
        if ($file['size'] > 5 * 1024 * 1024)  $this->fail(413, 'File too large. Maximum 5 MB.');

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            $this->fail(415, 'Only CSV files are accepted. Please export your Excel file as CSV and try again.');
        }

        // Read CSV file
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) $this->fail(500, 'Failed to open file.');
        
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        if (empty($rows)) $this->fail(400, 'Empty or invalid file.');

        // Read header row and detect column indexes
        $header = array_map(fn($h) => strtolower(trim((string)$h)), $rows[0]);

        $colMap = [
            'index_number' => $this->_findCol($header, ['index_number','index no','index no.','index','id number','student id','matric']),
            'student_name' => $this->_findCol($header, ['student_name','name','full name','student name','fullname']),
            'email'        => $this->_findCol($header, ['email','e-mail','email address']),
            'phone'        => $this->_findCol($header, ['phone','mobile','telephone','contact']),
            'program'      => $this->_findCol($header, ['program','programme','course','program of study']),
            'level'        => $this->_findCol($header, ['level','year','class year']),
        ];

        if ($colMap['index_number'] === null || $colMap['student_name'] === null) {
            $this->fail(422, 'File must have columns for Index Number and Student Name. Detected headers: ' . implode(', ', $header));
        }

        $db = $this->db();
        $inserted = 0;
        $skipped  = 0;
        $errors   = [];

        $insertStmt = $db->prepare("
            INSERT IGNORE INTO master_list
                (lecturer_id, index_number, student_name, email, phone, program, level, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $rowNum = 1;
        foreach ($rows as $i => $row) {
            $rowNum = $i + 1;
            if ($i === 0) continue; // skip header row
            if (empty(array_filter($row))) continue; // skip blank rows

            $indexNum = trim((string)($row[$colMap['index_number']] ?? ''));
            $name     = trim((string)($row[$colMap['student_name']] ?? ''));

            if (!$indexNum || !$name) {
                $skipped++;
                $errors[] = "Row {$rowNum}: missing index number or name.";
                continue;
            }

            $email   = $colMap['email']   !== null ? trim((string)($row[$colMap['email']]   ?? '')) : null;
            $phone   = $colMap['phone']   !== null ? trim((string)($row[$colMap['phone']]   ?? '')) : null;
            $program = $colMap['program'] !== null ? trim((string)($row[$colMap['program']] ?? '')) : null;
            $level   = $colMap['level']   !== null ? trim((string)($row[$colMap['level']]   ?? '')) : null;

            try {
                $insertStmt->execute([
                    $lecId,
                    $indexNum,
                    $name,
                    $email   ?: null,
                    $phone   ?: null,
                    $program ?: null,
                    $level   ?: null,
                ]);
                if ($insertStmt->rowCount() > 0) $inserted++;
                else $skipped++;
            } catch (\PDOException $e) {
                $skipped++;
                $errors[] = "Row {$rowNum}: " . $e->getMessage();
            }
        }

        $this->audit($lecId, 'lecturer', 'MASTER_LIST_UPLOAD', [
            'imported' => $inserted,
            'skipped'  => $skipped,
            'filename' => $file['name'],
        ]);

        $this->json([
            'success'  => true,
            'imported' => $inserted,
            'skipped'  => $skipped,
            'errors'   => array_slice($errors, 0, 20),
            'message'  => "Imported {$inserted} student(s). {$skipped} skipped (already exist or invalid).",
        ], 201);
        } catch (Exception $e) {
            error_log("[MasterList Upload] " . $e->getMessage() . " | " . $e->getTraceAsString());
            $this->fail(500, 'Upload error: ' . $e->getMessage());
        }
    }

    // ── DELETE /api/master-list/{id} ──────────────────────────────────────
    public function delete(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $db     = $this->db();

        $stmt = $db->prepare(
            "SELECT id FROM master_list WHERE id = ? AND lecturer_id = ?"
        );
        $stmt->execute([$id, $lecId]);
        if (!$stmt->fetch()) $this->fail(404, 'Entry not found.');

        $db->prepare("DELETE FROM master_list WHERE id = ? AND lecturer_id = ?")
           ->execute([$id, $lecId]);

        $this->ok(null, 'Entry removed from master list.');
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────

    /**
     * Find the first matching column index (case-insensitive).
     * Returns null if none found.
     */
    private function _findCol(array $header, array $candidates): ?int
    {
        foreach ($candidates as $c) {
            $idx = array_search(strtolower($c), $header, true);
            if ($idx !== false) return (int)$idx;
        }
        return null;
    }
}
