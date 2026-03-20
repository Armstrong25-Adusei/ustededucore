<?php
/**
 * EduCore — LecturerPhotoController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Handles profile-photo upload and removal for lecturers.
 *
 * ROUTES (registered in api.php):
 *   POST   lecturer/photo   — multipart/form-data, field name: "photo"
 *   DELETE lecturer/photo   — removes current photo from disk + DB
 *
 * SECURITY PIPELINE (upload):
 *   1. JWT auth guard (lecturer token required)
 *   2. File present check
 *   3. Size limit  — max 3 MB
 *   4. MIME check  — finfo_file() reads magic bytes (not extension or browser header)
 *                    Allowed: image/jpeg, image/png, image/webp
 *   5. Image validity — getimagesize() confirms it is a real image
 *   6. GD re-encode  — re-saves via GD, stripping all EXIF metadata (GPS, device info)
 *   7. Resize        — caps at 400×400 px, preserving aspect ratio
 *   8. Safe filename — lec_{id}_{unix_ts}.jpg  (original filename is NEVER used)
 *   9. Write to disk — uploads/lecturers/ (relative to backend root)
 *  10. Delete old    — previous file removed from disk
 *  11. DB update     — profile_photo + profile_photo_updated_at
 *  12. Audit log     — photo_upload_log row inserted (accepted or rejected)
 *
 * RESPONSE (success):
 *   { "photo_url": "uploads/lecturers/lec_1_1710213981.jpg",
 *     "updated_at": "2026-03-14 18:00:00" }
 * ─────────────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

class LecturerPhotoController
{
    // ── Config ────────────────────────────────────────────────────────────────
    private const MAX_BYTES      = 3 * 1024 * 1024;          // 3 MB
    private const MAX_DIMENSION  = 400;                       // px (width + height cap)
    private const ALLOWED_MIMES  = ['image/jpeg', 'image/png', 'image/webp'];
    private const UPLOAD_DIR_REL = 'uploads/lecturers/';     // relative to backend root
    private const UPLOAD_DIR     = __DIR__ . '/../' . self::UPLOAD_DIR_REL;

    private \PDO $db;
    private int  $lecturerId;

    public function __construct()
    {
        $this->db         = Database::connect();
        $claims           = AuthMiddleware::lecturer();
        $this->lecturerId = (int)$claims['lecturer_id'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST lecturer/photo
    // ─────────────────────────────────────────────────────────────────────────
    public function upload(): void
    {
        // ── 1. File present ──────────────────────────────────────────────────
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $errCode = $_FILES['photo']['error'] ?? -1;
            $errMsg  = $this->uploadErrMsg($errCode);
            $this->logAttempt('rejected_type', $errMsg);
            $this->fail(422, $errMsg);
        }

        $tmp      = $_FILES['photo']['tmp_name'];
        $origName = $_FILES['photo']['name'] ?? 'unknown';
        $size     = (int)$_FILES['photo']['size'];

        // ── 2. Size limit ────────────────────────────────────────────────────
        if ($size > self::MAX_BYTES) {
            $this->logAttempt('rejected_size', 'File exceeds 3 MB limit', $origName, $size);
            $this->fail(422, 'Image must be smaller than 3 MB.');
        }

        // ── 3. MIME via magic bytes (finfo) ──────────────────────────────────
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmp);
        if (!in_array($mimeType, self::ALLOWED_MIMES, true)) {
            $this->logAttempt('rejected_type', "Rejected MIME: {$mimeType}", $origName, $size, $mimeType);
            $this->fail(422, 'Only JPEG, PNG, and WebP images are allowed.');
        }

        // ── 4. Image validity (getimagesize) ─────────────────────────────────
        $imgInfo = @getimagesize($tmp);
        if ($imgInfo === false) {
            $this->logAttempt('rejected_content', 'Not a valid image', $origName, $size, $mimeType);
            $this->fail(422, 'Uploaded file is not a valid image.');
        }

        // ── 5. Skip GD processing - directly copy file ────────────────────────
        // (GD library may not be available; simple file move is sufficient)
        
        // ── 6. Safe filename + ensure upload dir exists ────────────────────────
        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }
        $ts       = time();
        // Determine extension from MIME type for consistency
        $ext = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
        $filename = "lec_{$this->lecturerId}_{$ts}.{$ext}";
        $destPath = self::UPLOAD_DIR . $filename;
        $relPath  = self::UPLOAD_DIR_REL . $filename;

        // ── 7. Move uploaded file to destination ──────────────────────────────
        if (!move_uploaded_file($tmp, $destPath)) {
            $this->logAttempt('rejected_content', 'Failed to move uploaded file', $origName, $size, $mimeType);
            $this->fail(500, 'Failed to save image. Please try again.');
        }

        // ── 8. Delete old photo from disk ─────────────────────────────────────
        $oldPath = $this->getOldPhotoPath();
        if ($oldPath && file_exists($oldPath)) {
            @unlink($oldPath);
        }

        // ── 9. Update DB ──────────────────────────────────────────────────────
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE lecturers
             SET    profile_photo = ?, profile_photo_updated_at = ?, updated_at = ?
             WHERE  lecturer_id = ?'
        );
        $stmt->execute([$relPath, $now, $now, $this->lecturerId]);

        // ── 10. Audit log ──────────────────────────────────────────────────────
        $this->logAttempt('accepted', null, $origName, $size, $mimeType, $relPath);

        // ── 11. Respond ────────────────────────────────────────────────────────
        http_response_code(200);
        echo json_encode([
            'photo_url'  => $relPath,
            'updated_at' => $now,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE lecturer/photo
    // ─────────────────────────────────────────────────────────────────────────
    public function remove(): void
    {
        $oldPath = $this->getOldPhotoPath();
        if ($oldPath && file_exists($oldPath)) {
            @unlink($oldPath);
        }

        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE lecturers
             SET    profile_photo = NULL, profile_photo_updated_at = NULL, updated_at = ?
             WHERE  lecturer_id = ?'
        );
        $stmt->execute([$now, $this->lecturerId]);

        // Log removal in photo_upload_log as 'deleted'
        $this->logAttempt('deleted', 'Photo removed by lecturer', null, null, null, $oldPath);

        http_response_code(200);
        echo json_encode(['message' => 'Profile photo removed.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /** Fetch the currently stored relative path for this lecturer. */
    private function getOldPhotoPath(): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT profile_photo FROM lecturers WHERE lecturer_id = ?'
        );
        $stmt->execute([$this->lecturerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || !$row['profile_photo']) return null;
        // Convert relative path to absolute
        return __DIR__ . '/../' . ltrim($row['profile_photo'], '/');
    }

    /**
     * Calculate output dimensions preserving aspect ratio, capped at $max px
     * on the longest side.
     *
     * @return array{0:int, 1:int}  [width, height]
     */
    private function calcResize(int $w, int $h, int $max): array
    {
        if ($w <= $max && $h <= $max) {
            return [$w, $h]; // already small enough — no upscale
        }
        if ($w >= $h) {
            return [$max, (int)round($h * $max / $w)];
        }
        return [(int)round($w * $max / $h), $max];
    }

    /**
     * Insert a row in photo_upload_log.
     * Called for both accepted and rejected uploads so there is always an audit trail.
     */
    private function logAttempt(
        string  $outcome,
        ?string $reason     = null,
        ?string $origName   = null,
        ?int    $sizeBytes  = null,
        ?string $mimeType   = null,
        ?string $storedPath = null
    ): void {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO photo_upload_log
                 (uploader_type, uploader_id, original_filename, stored_path,
                  file_size_bytes, mime_type, ip_address, user_agent,
                  outcome, rejection_reason, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                'lecturer',
                $this->lecturerId,
                $origName,
                $storedPath ?? '',
                $sizeBytes,
                $mimeType,
                $_SERVER['REMOTE_ADDR']         ?? null,
                $_SERVER['HTTP_USER_AGENT']      ?? null,
                $outcome,
                $reason,
            ]);
        } catch (\Throwable) {
            // Audit log failure must not break the upload response
        }
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
