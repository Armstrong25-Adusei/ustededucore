<?php
/**
 * EduCore — StudentPhotoController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Handles profile-photo upload and removal for students.
 *
 * ROUTES (registered in api.php):
 *   POST   student/photo   — multipart/form-data, field name: "photo"
 *   DELETE student/photo   — removes current photo from disk + DB
 *
 * SECURITY PIPELINE (upload):
 *   1. JWT auth guard (student token required)
 *   2. File present check
 *   3. Size limit  — max 5 MB
 *   4. MIME check  — finfo_file() reads magic bytes (not extension or browser header)
 *                    Allowed: image/jpeg, image/png, image/webp
 *   5. Image validity — getimagesize() confirms it is a real image
 *   6. Safe filename — stu_{id}_{unix_ts}.jpg  (original filename is NEVER used)
 *   7. Write to disk — uploads/students/ (relative to backend root)
 *   8. Delete old    — previous file removed from disk
 *   9. DB update     — profile_photo field in students table
 *  10. Respond       — return new photo URL
 *
 * RESPONSE (success):
 *   { "photo_url": "uploads/students/stu_3_1710213981.jpg" }
 * ─────────────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../config/Config.php';

class StudentPhotoController
{
    // ── Config ────────────────────────────────────────────────────────────────
    private const MAX_BYTES      = 5 * 1024 * 1024;          // 5 MB
    private const ALLOWED_MIMES  = ['image/jpeg', 'image/png', 'image/webp'];
    private const UPLOAD_DIR_REL = 'uploads/students/';      // relative to backend root
    private const UPLOAD_DIR     = __DIR__ . '/../' . self::UPLOAD_DIR_REL;

    private \PDO $db;
    private int  $studentId;

    public function __construct()
    {
        $this->db         = Database::connect();
        $claims           = AuthMiddleware::student();
        $this->studentId  = (int)$claims['student_id'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST student/photo
    // ─────────────────────────────────────────────────────────────────────────
    public function upload(): void
    {
        // ── 1. File present ──────────────────────────────────────────────────
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $errCode = $_FILES['photo']['error'] ?? -1;
            $errMsg  = $this->uploadErrMsg($errCode);
            $this->fail(422, $errMsg);
        }

        $tmp      = $_FILES['photo']['tmp_name'];
        $origName = $_FILES['photo']['name'] ?? 'unknown';
        $size     = (int)$_FILES['photo']['size'];

        // ── 2. Size limit ────────────────────────────────────────────────────
        if ($size > self::MAX_BYTES) {
            $this->fail(422, 'Image must be smaller than 5 MB.');
        }

        // ── 3. MIME via magic bytes (finfo) ──────────────────────────────────
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmp);
        if (!in_array($mimeType, self::ALLOWED_MIMES, true)) {
            $this->fail(422, 'Only JPEG, PNG, and WebP images are allowed.');
        }

        // ── 4. Image validity (getimagesize) ─────────────────────────────────
        $imgInfo = @getimagesize($tmp);
        if ($imgInfo === false) {
            $this->fail(422, 'Uploaded file is not a valid image.');
        }

        // ── 5. Ensure upload dir exists ──────────────────────────────────────
        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        // ── 6. Safe filename ─────────────────────────────────────────────────
        $ts  = time();
        $ext = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
        $filename = "stu_{$this->studentId}_{$ts}.{$ext}";
        $destPath = self::UPLOAD_DIR . $filename;
        $relPath  = self::UPLOAD_DIR_REL . $filename;

        // ── 7. Move uploaded file to destination ──────────────────────────────
        if (!move_uploaded_file($tmp, $destPath)) {
            $this->fail(500, 'Failed to save image. Please try again.');
        }

        // ── 8. Delete old photo from disk ─────────────────────────────────────
        $oldPath = $this->getOldPhotoPath();
        if ($oldPath && file_exists($oldPath)) {
            @unlink($oldPath);
        }

        // ── 9. Update DB ──────────────────────────────────────────────────────
        $stmt = $this->db->prepare(
            'UPDATE students SET profile_photo = ? WHERE student_id = ?'
        );
        $stmt->execute([$relPath, $this->studentId]);

        // ── 10. Respond ────────────────────────────────────────────────────────
        http_response_code(200);
        echo json_encode([
            'photo_url' => $relPath,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE student/photo
    // ─────────────────────────────────────────────────────────────────────────
    public function remove(): void
    {
        $oldPath = $this->getOldPhotoPath();
        if ($oldPath && file_exists($oldPath)) {
            @unlink($oldPath);
        }

        $stmt = $this->db->prepare(
            'UPDATE students SET profile_photo = NULL WHERE student_id = ?'
        );
        $stmt->execute([$this->studentId]);

        http_response_code(200);
        echo json_encode(['message' => 'Profile photo removed.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /** Fetch the currently stored relative path for this student. */
    private function getOldPhotoPath(): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT profile_photo FROM students WHERE student_id = ?'
        );
        $stmt->execute([$this->studentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || !$row['profile_photo']) return null;
        // Convert relative path to absolute
        return __DIR__ . '/../' . ltrim($row['profile_photo'], '/');
    }

    private function uploadErrMsg(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large.',
            UPLOAD_ERR_PARTIAL   => 'Upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Server storage error. Contact support.',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension.',
            default              => 'Upload failed (error ' . $code . ').',
        };
    }

    private function fail(int $status, string $message): never
    {
        http_response_code($status);
        echo json_encode(['error' => $message]);
        exit;
    }
}
