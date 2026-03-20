<?php
/**
 * EduCore — StudentAuthController  v7.0
 *
 * Device architecture: student_devices table (max 2 active devices per student).
 * students.device_uuid has been REMOVED. All device checks hit student_devices.
 *
 * POST  /api/student/auth/register
 * POST  /api/student/auth/login
 * GET   /api/student/auth/verify-email?token=…
 * POST  /api/student/auth/resend-verification
 * POST  /api/student/auth/forgot-password
 * POST  /api/student/auth/reset-password
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../config/Config.php';
require_once __DIR__ . '/../utils/MailHelper.php';

class StudentAuthController extends BaseController
{
    /** Maximum active devices allowed per student */
    private const MAX_DEVICES = 2;

    // ══════════════════════════════════════════════════════════════════
    // REGISTER
    // POST /api/student/auth/register
    // Body: { student_name, index_number, email, password, device_uuid?,
    //         device_name?, device_type?, browser? }
    // ══════════════════════════════════════════════════════════════════

    public function register(): void
    {
        $b = $this->jsonBody();

        $name        = trim($b['student_name'] ?? '');
        $indexNumber = trim($b['index_number'] ?? '');
        $email       = strtolower(trim($b['email'] ?? ''));
        $password    = $b['password']    ?? '';
        $deviceUuid  = trim($b['device_uuid']  ?? '');
        $deviceName  = trim($b['device_name']  ?? '');
        $deviceType  = trim($b['device_type']  ?? '');
        $browser     = trim($b['browser']      ?? '');

        // Validation
        if (!$name)        $this->fail(400, 'Full name is required.');
        if (!$indexNumber) $this->fail(400, 'Index number is required.');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))
            $this->fail(400, 'A valid email address is required.');
        if (strlen($password) < 8)
            $this->fail(400, 'Password must be at least 8 characters.');

        $db = $this->db();

        // Duplicate checks
        $chk = $db->prepare("SELECT student_id FROM students WHERE index_number = ? LIMIT 1");
        $chk->execute([$indexNumber]);
        if ($chk->fetch()) $this->fail(409, 'An account with this index number already exists.');

        $chk2 = $db->prepare("SELECT student_id FROM students WHERE email = ? LIMIT 1");
        $chk2->execute([$email]);
        if ($chk2->fetch()) $this->fail(409, 'An account with this email already exists.');

        // Insert student (generated_id holds the email verification token)
        $verifyToken = bin2hex(random_bytes(25)); // 50 chars
        $hash        = password_hash($password, PASSWORD_BCRYPT, ['cost' => Config::BCRYPT_COST]);

        $db->beginTransaction();
        try {
            $db->prepare("
                INSERT INTO students
                    (master_list_id, lecturer_id,
                     index_number, student_name, email, password_hash,
                     enrollment_status, registered_by, generated_id, enrollment_date)
                VALUES
                    (NULL, NULL, ?, ?, ?, ?, 'pending', 'self', ?, NOW())
            ")->execute([$indexNumber, $name, $email, $hash, $verifyToken]);

            $studentId = (int)$db->lastInsertId();

            // Bind first device if provided
            if ($deviceUuid) {
                $db->prepare("
                    INSERT IGNORE INTO student_devices
                        (student_id, device_hash, device_name, device_type, browser,
                         first_login, last_login, status)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 'active')
                ")->execute([
                    $studentId,
                    $deviceUuid,
                    $deviceName  ?: $this->guessDeviceName($browser, $deviceType),
                    $deviceType  ?: null,
                    $browser     ?: null,
                ]);

                $this->audit($studentId, 'student', 'DEVICE_BOUND', [
                    'device_uuid' => $deviceUuid,
                    'trigger'     => 'register',
                ]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('[EduCore Register] ' . $e->getMessage());
            $this->fail(500, 'Registration failed. Please try again.');
        }

        // Send verification email — outside DB transaction but errors are non-fatal
        try {
            MailHelper::sendVerification($email, $name, $verifyToken, 'student');
        } catch (\Throwable $e) {
            error_log('[EduCore Mail] Verification send failed: ' . $e->getMessage());
            // Do not 500 — student was created successfully; they can resend
        }

        $this->json([
            'success'    => true,
            'message'    => 'Account created. Please check your email to verify your account.',
            'student_id' => $studentId,
        ], 201);
    }

    // ══════════════════════════════════════════════════════════════════
    // LOGIN
    // POST /api/student/auth/login
    // Body: { index_number, password, device_uuid,
    //         device_name?, device_type?, browser? }
    //
    // Device flow:
    //   1. Verify credentials
    //   2. If device_uuid already bound to this student → allow
    //   3. If device_uuid NOT found AND active_device_count < MAX → bind + allow
    //   4. If device_uuid NOT found AND active_device_count >= MAX → block
    // ══════════════════════════════════════════════════════════════════

    public function login(): void
    {
        $b = $this->jsonBody();

        $indexOrEmail = trim($b['index_number'] ?? $b['email'] ?? '');
        $password     = $b['password']     ?? '';
        $deviceUuid   = trim($b['device_uuid']  ?? '');
        $deviceName   = trim($b['device_name']  ?? '');
        $deviceType   = trim($b['device_type']  ?? '');
        $browser      = trim($b['browser']      ?? '');

        if (!$indexOrEmail) $this->fail(400, 'Index number or email is required.');
        if (!$password)     $this->fail(400, 'Password is required.');
        if (!$deviceUuid)   $this->fail(400, 'Device UUID is required.');

        $db = $this->db();

        // ── Find student ──────────────────────────────────────────────
        $stmt = $db->prepare("
            SELECT student_id, student_name, email, password_hash,
                   enrollment_status, account_status, index_number,
                   program, phone, profile_photo, lecturer_id
            FROM   students
            WHERE  index_number = ? OR email = ?
            LIMIT  1
        ");
        $stmt->execute([$indexOrEmail, $indexOrEmail]);
        $stu = $stmt->fetch();

        if (!$stu || !password_verify($password, $stu['password_hash'])) {
            $this->audit(
                $stu['student_id'] ?? 0, 'student', 'LOGIN_FAILED_CREDENTIALS',
                ['index_or_email' => $indexOrEmail]
            );
            $this->fail(401, 'Incorrect index number or password.');
        }

        // ── Account checks ────────────────────────────────────────────
        if ($stu['account_status'] === 'suspended') {
            $this->fail(403, 'Your account has been suspended. Please contact your lecturer.');
        }

        if ($stu['enrollment_status'] === 'pending') {
            // generated_id still holds verify token → not yet verified
            $this->fail(403, 'Please verify your email address before logging in. Check your inbox.');
        }

        // ── Device check ──────────────────────────────────────────────
        $studentId = (int)$stu['student_id'];

        // Step 1: Does this exact device already exist?
        $devStmt = $db->prepare("
            SELECT device_id, status
            FROM   student_devices
            WHERE  student_id  = ? AND device_hash = ?
            LIMIT  1
        ");
        $devStmt->execute([$studentId, $deviceUuid]);
        $existingDevice = $devStmt->fetch();

        if ($existingDevice) {
            // Device exists — check if it was revoked
            if ($existingDevice['status'] !== 'active') {
                $this->audit($studentId, 'student', 'LOGIN_FAILED_DEVICE_REVOKED',
                    ['device_uuid' => $deviceUuid]);
                $this->fail(403, 'This device has been removed from your account. Please contact your lecturer to re-add it.');
            }

            // Update last_login timestamp
            $db->prepare("
                UPDATE student_devices
                SET    last_login = NOW()
                WHERE  device_id = ?
            ")->execute([$existingDevice['device_id']]);

        } else {
            // Step 2: New device — check how many active devices this student has
            $countStmt = $db->prepare("
                SELECT COUNT(*) AS cnt
                FROM   student_devices
                WHERE  student_id = ? AND status = 'active'
            ");
            $countStmt->execute([$studentId]);
            $activeCount = (int)$countStmt->fetchColumn();

            if ($activeCount >= self::MAX_DEVICES) {
                $this->audit($studentId, 'student', 'LOGIN_FAILED_MAX_DEVICES',
                    ['device_uuid' => $deviceUuid, 'active_count' => $activeCount]);
                $this->fail(403, sprintf(
                    'Maximum device limit reached (%d devices). ' .
                    'Remove an existing device in Settings or ask your lecturer to reset your devices.',
                    self::MAX_DEVICES
                ));
            }

            // Bind new device
            $db->prepare("
                INSERT INTO student_devices
                    (student_id, device_hash, device_name, device_type, browser,
                     first_login, last_login, status)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 'active')
            ")->execute([
                $studentId,
                $deviceUuid,
                $deviceName ?: $this->guessDeviceName($browser, $deviceType),
                $deviceType ?: null,
                $browser    ?: null,
            ]);

            $this->audit($studentId, 'student', 'DEVICE_BOUND', [
                'device_uuid' => $deviceUuid,
                'trigger'     => 'login',
                'slot'        => $activeCount + 1,
            ]);
        }

        // ── Audit login success ───────────────────────────────────────
        $db->prepare("
            INSERT INTO audit_logs (actor_id, actor_type, action, device_uuid, meta, logged_at)
            VALUES (?, 'student', 'LOGIN_SUCCESS', ?, ?, NOW())
        ")->execute([
            $studentId,
            $deviceUuid,
            json_encode(['index_number' => $stu['index_number']]),
        ]);

        // ── Issue JWT ─────────────────────────────────────────────────
        $token = JWT::encode([
            'student_id'  => $studentId,
            'index_number'=> $stu['index_number'],
            'iat'         => time(),
            'exp'         => time() + Config::STUDENT_JWT_TTL,
        ], Config::STUDENT_JWT_SECRET);

        // Build device list for response
        $devices = $this->getDeviceList($db, $studentId);

        $this->json([
            'token'   => $token,
            'student' => [
                'student_id'    => $studentId,
                'student_name'  => $stu['student_name'],
                'index_number'  => $stu['index_number'],
                'email'         => $stu['email'],
                'phone'         => $stu['phone'],
                'program'       => $stu['program'],
                'profile_photo' => $stu['profile_photo'],
                'lecturer_id'   => $stu['lecturer_id'],
            ],
            'devices' => $devices,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // VERIFY EMAIL
    // GET /api/student/auth/verify-email?token=…
    // ══════════════════════════════════════════════════════════════════

    public function verifyEmail(): void
    {
        $token = trim($_GET['token'] ?? '');
        if (!$token) $this->fail(400, 'Verification token is required.');

        $db   = $this->db();
        $stmt = $db->prepare("
            SELECT student_id, student_name, email
            FROM   students
            WHERE  generated_id = ? AND enrollment_status = 'pending'
            LIMIT  1
        ");
        $stmt->execute([$token]);
        $stu = $stmt->fetch();

        if (!$stu) {
            $this->fail(400, 'Invalid or expired verification link. Please request a new one.');
        }

        // Mark verified: clear token, set enrolled
        $db->prepare("
            UPDATE students
            SET    enrollment_status = 'enrolled',
                   generated_id      = NULL
            WHERE  student_id = ?
        ")->execute([$stu['student_id']]);

        $this->audit($stu['student_id'], 'student', 'EMAIL_VERIFIED', [
            'email' => $stu['email'],
        ]);

        $this->ok(null, 'Email verified successfully. You can now log in.');
    }

    // ══════════════════════════════════════════════════════════════════
    // RESEND VERIFICATION
    // POST /api/student/auth/resend-verification
    // Body: { email }
    // ══════════════════════════════════════════════════════════════════

    public function resendVerification(): void
    {
        $b     = $this->jsonBody();
        $email = strtolower(trim($b['email'] ?? ''));
        if (!$email) $this->fail(400, 'Email is required.');

        $db   = $this->db();
        $stmt = $db->prepare("
            SELECT student_id, student_name, enrollment_status
            FROM   students WHERE email = ? LIMIT 1
        ");
        $stmt->execute([$email]);
        $stu = $stmt->fetch();

        // Always respond OK to avoid email enumeration
        if (!$stu || $stu['enrollment_status'] !== 'pending') {
            $this->ok(null, 'If an unverified account exists, a new link has been sent.');
            return;
        }

        $newToken = bin2hex(random_bytes(25));
        $db->prepare("UPDATE students SET generated_id = ? WHERE student_id = ?")
           ->execute([$newToken, $stu['student_id']]);

        MailHelper::sendVerification($email, $stu['student_name'], $newToken, 'student');

        $this->ok(null, 'Verification email resent. Please check your inbox.');
    }

    // ══════════════════════════════════════════════════════════════════
    // FORGOT PASSWORD
    // POST /api/student/auth/forgot-password
    // Body: { email }
    // ══════════════════════════════════════════════════════════════════

    public function forgotPassword(): void
    {
        $b     = $this->jsonBody();
        $email = strtolower(trim($b['email'] ?? ''));
        if (!$email) $this->fail(400, 'Email is required.');

        $db   = $this->db();
        $stmt = $db->prepare("SELECT student_id, student_name FROM students WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $stu = $stmt->fetch();

        // Always respond OK
        if (!$stu) {
            $this->ok(null, 'If that email is registered, a reset link has been sent.');
            return;
        }

        // Store reset token as "RESET_{token}" in generated_id
        $resetToken = bin2hex(random_bytes(22));
        $db->prepare("UPDATE students SET generated_id = ? WHERE student_id = ?")
           ->execute(['RESET_' . $resetToken, $stu['student_id']]);

        MailHelper::sendPasswordReset($email, $stu['student_name'], $resetToken, 'student');

        $this->ok(null, 'Password reset link sent. Please check your email.');
    }

    // ══════════════════════════════════════════════════════════════════
    // RESET PASSWORD
    // POST /api/student/auth/reset-password
    // Body: { token, password }
    // ══════════════════════════════════════════════════════════════════

    public function resetPassword(): void
    {
        $b        = $this->jsonBody();
        $token    = trim($b['token']    ?? '');
        $password = $b['password'] ?? '';

        if (!$token)              $this->fail(400, 'Reset token is required.');
        if (strlen($password) < 8) $this->fail(400, 'Password must be at least 8 characters.');

        $db   = $this->db();
        $stmt = $db->prepare("
            SELECT student_id FROM students
            WHERE  generated_id = ? LIMIT 1
        ");
        $stmt->execute(['RESET_' . $token]);
        $stu = $stmt->fetch();

        if (!$stu) $this->fail(400, 'Invalid or expired reset link. Please request a new one.');

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => Config::BCRYPT_COST]);
        $db->prepare("
            UPDATE students SET password_hash = ?, generated_id = NULL WHERE student_id = ?
        ")->execute([$hash, $stu['student_id']]);

        $this->audit($stu['student_id'], 'student', 'PASSWORD_RESET', []);

        $this->ok(null, 'Password reset successfully. You can now log in.');
    }

    // ══════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════

    /**
     * Returns list of active devices for the student.
     * Used in login response so the frontend Settings page can show them immediately.
     */
    private function getDeviceList(PDO $db, int $studentId): array
    {
        $stmt = $db->prepare("
            SELECT device_id, device_hash, device_name, device_type,
                   browser, first_login, last_login
            FROM   student_devices
            WHERE  student_id = ? AND status = 'active'
            ORDER  BY first_login ASC
        ");
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    }

    /**
     * Produces a friendly device name from browser/OS strings when the
     * client does not send an explicit device_name.
     *
     * Examples:
     *   "Mozilla/5.0 … Android … Chrome" → "Android / Chrome"
     *   "Mozilla/5.0 … Windows … Edge"   → "Windows / Edge"
     *   "Mozilla/5.0 … iPhone … Safari"  → "iPhone / Safari"
     */
    private function guessDeviceName(string $userAgent, string $deviceType): string
    {
        if (!$userAgent) {
            return $deviceType ? ucfirst($deviceType) . ' Device' : 'Unknown Device';
        }

        $os = 'Unknown';
        if (stripos($userAgent, 'Android') !== false)       $os = 'Android';
        elseif (stripos($userAgent, 'iPhone') !== false)    $os = 'iPhone';
        elseif (stripos($userAgent, 'iPad') !== false)      $os = 'iPad';
        elseif (stripos($userAgent, 'Windows') !== false)   $os = 'Windows';
        elseif (stripos($userAgent, 'Macintosh') !== false) $os = 'Mac';
        elseif (stripos($userAgent, 'Linux') !== false)     $os = 'Linux';

        $browser = 'Browser';
        if (stripos($userAgent, 'Chrome') !== false && stripos($userAgent, 'Chromium') === false)
            $browser = 'Chrome';
        elseif (stripos($userAgent, 'Firefox') !== false)  $browser = 'Firefox';
        elseif (stripos($userAgent, 'Safari') !== false)   $browser = 'Safari';
        elseif (stripos($userAgent, 'Edge') !== false)     $browser = 'Edge';
        elseif (stripos($userAgent, 'Opera') !== false)    $browser = 'Opera';

        return "{$os} / {$browser}";
    }
}