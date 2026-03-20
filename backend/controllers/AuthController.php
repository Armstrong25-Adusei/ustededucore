<?php
/**
 * EduCore — AuthController (Lecturer)  v5.5
 *
 * POST  /api/auth/register
 * POST  /api/auth/login
 * POST  /api/auth/logout
 * GET   /api/auth/me
 * GET   /api/auth/verify-email?token=…
 * POST  /api/auth/resend-verification
 * POST  /api/auth/forgot-password
 * POST  /api/auth/reset-password
 *
 * Payload shapes come from signup.html and login.html.
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';

class AuthController extends BaseController
{
    // ── POST /api/auth/register ───────────────────────────────────────
    /**
     * signup.html sends: { name, staff_id, email, password }
     */
    public function register(): void
    {
        $b = $this->jsonBody();

        $name     = trim($b['name'] ?? $b['full_name'] ?? '');
        $staffId  = trim($b['staff_id'] ?? '');
        $email    = strtolower(trim($b['email'] ?? ''));
        $password = $b['password'] ?? '';

        if (!$name)    $this->fail(400, 'Full name is required.');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))
            $this->fail(400, 'A valid email address is required.');
        if (strlen($password) < 8)
            $this->fail(400, 'Password must be at least 8 characters.');

        $db = $this->db();

        // Duplicate email check
        $dup = $db->prepare('SELECT lecturer_id FROM lecturers WHERE email = ?');
        $dup->execute([$email]);
        if ($dup->fetch()) $this->fail(409, 'An account with this email already exists.');

        // Default to institution_id = 1 if none provided (lecturer self-registers)
        $instId = (int)($b['institution_id'] ?? 1);

        $token = bin2hex(random_bytes(32));
        $hash  = password_hash($password, PASSWORD_BCRYPT, ['cost' => Config::BCRYPT_COST]);

        $stmt = $db->prepare("
            INSERT INTO lecturers
                (institution_id, full_name, email, password_hash, is_active,
                 email_verified, verification_token, verification_sent_at,
                 created_at, updated_at)
            VALUES (?, ?, ?, ?, 1, 0, ?, NOW(), NOW(), NOW())
        ");
        $stmt->execute([$instId, $name, $email, $hash, $token]);
        $id = (int)$db->lastInsertId();

        if ($staffId) $this->audit($id, 'lecturer', 'REGISTER', ['staff_id' => $staffId]);

        // Send verification email (non-fatal if mail server not configured yet)
        require_once __DIR__ . '/../utils/MailHelper.php';
        MailHelper::sendVerification($email, $name, $token, 'lecturer');

        $this->created(['lecturer_id' => $id], 'Account created. Check your email to verify.');
    }

    // ── POST /api/auth/login ──────────────────────────────────────────
    /**
     * login.html sends: { email, password }
     */
    public function login(): void
    {
        $b        = $this->jsonBody();
        $email    = strtolower(trim($b['email'] ?? ''));
        $password = $b['password'] ?? '';

        if (!$email || !$password)
            $this->fail(400, 'Email and password are required.');

        $db   = $this->db();
        $stmt = $db->prepare("
            SELECT l.*, i.institution_name, i.institution_type
            FROM   lecturers l
            LEFT JOIN institutions i ON i.institution_id = l.institution_id
            WHERE  l.email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $lecturer = $stmt->fetch();

        if (!$lecturer || !password_verify($password, $lecturer['password_hash']))
            $this->fail(401, 'Invalid email or password.');

        if (!$lecturer['email_verified'])
            $this->fail(403, 'Email not verified. Check your inbox or request a new link.');

        if (!$lecturer['is_active'])
            $this->fail(403, 'Account suspended. Contact your institution admin.');

        $db->prepare('UPDATE lecturers SET last_login = NOW() WHERE lecturer_id = ?')
           ->execute([$lecturer['lecturer_id']]);

        $token = issueToken([
            'lecturer_id'      => $lecturer['lecturer_id'],
            'institution_id'   => $lecturer['institution_id'],
            'institution_type' => $lecturer['institution_type'],
            'email'            => $lecturer['email'],
        ]);

        $this->json([
            'token'    => $token,
            'lecturer' => [
                'lecturer_id'      => $lecturer['lecturer_id'],
                'full_name'        => $lecturer['full_name'],
                'email'            => $lecturer['email'],
                'institution_name' => $lecturer['institution_name'],
                'institution_type' => $lecturer['institution_type'],
            ],
        ]);
    }

    // ── POST /api/auth/logout ─────────────────────────────────────────
    public function logout(): void
    {
        // JWT is stateless — client discards token
        $this->json(['success' => true, 'message' => 'Logged out.']);
    }

    // ── GET /api/auth/me ──────────────────────────────────────────────
    public function me(): void
    {
        $claims = AuthMiddleware::lecturer();
        $stmt   = $this->db()->prepare("
            SELECT l.lecturer_id, l.full_name, l.email, l.profile_photo, l.profile_photo_updated_at,
                   l.last_login, l.created_at,
                   i.institution_name, i.institution_type, d.department_name
            FROM   lecturers l
            LEFT JOIN institutions i ON i.institution_id = l.institution_id
            LEFT JOIN departments  d ON d.department_id  = l.department_id
            WHERE  l.lecturer_id = ?
        ");
        $stmt->execute([$claims['lecturer_id']]);
        $row = $stmt->fetch();
        if (!$row) $this->fail(404, 'Lecturer not found.');
        $this->json($row);
    }

    // ── GET /api/auth/verify-email?token=… ───────────────────────────
    public function verifyEmail(): void
    {
        $token = trim($_GET['token'] ?? '');
        if (!$token) { $this->redirectToLogin('?verified=0'); }

        $db   = $this->db();
        $stmt = $db->prepare(
            "SELECT lecturer_id FROM lecturers WHERE verification_token = ? AND email_verified = 0"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) { $this->redirectToLogin('?verified=0'); }

        $db->prepare("UPDATE lecturers SET email_verified = 1, verification_token = NULL WHERE lecturer_id = ?")
           ->execute([$row['lecturer_id']]);

        $this->redirectToLogin('?verified=1');
    }

    // ── POST /api/auth/resend-verification ───────────────────────────
    public function resendVerification(): void
    {
        $b     = $this->jsonBody();
        $email = strtolower(trim($b['email'] ?? ''));
        if (!$email) $this->fail(400, 'Email is required.');

        $db   = $this->db();
        $stmt = $db->prepare("SELECT lecturer_id, full_name, email_verified FROM lecturers WHERE email = ?");
        $stmt->execute([$email]);
        $row  = $stmt->fetch();

        // Always return success to prevent email enumeration
        if ($row && !$row['email_verified']) {
            $token = bin2hex(random_bytes(32));
            $db->prepare("UPDATE lecturers SET verification_token = ?, verification_sent_at = NOW() WHERE lecturer_id = ?")
               ->execute([$token, $row['lecturer_id']]);
            require_once __DIR__ . '/../utils/MailHelper.php';
            MailHelper::sendVerification($email, $row['full_name'], $token, 'lecturer');
        }

        $this->json(['success' => true, 'message' => 'If unverified, a new link has been sent.']);
    }

    // ── POST /api/auth/forgot-password ────────────────────────────────
    public function forgotPassword(): void
    {
        $b     = $this->jsonBody();
        $email = strtolower(trim($b['email'] ?? ''));
        if (!$email) $this->fail(400, 'Email is required.');

        $db   = $this->db();
        $stmt = $db->prepare("SELECT lecturer_id, full_name FROM lecturers WHERE email = ?");
        $stmt->execute([$email]);
        $row  = $stmt->fetch();

        if ($row) {
            $token = bin2hex(random_bytes(32));
            $exp   = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $db->prepare("UPDATE lecturers SET reset_token = ?, reset_token_expires = ? WHERE lecturer_id = ?")
               ->execute([$token, $exp, $row['lecturer_id']]);
            require_once __DIR__ . '/../utils/MailHelper.php';
            MailHelper::sendPasswordReset($email, $row['full_name'], $token, 'lecturer');
        }

        $this->json(['success' => true, 'message' => 'If this email is registered, a reset link has been sent.']);
    }

    // ── POST /api/auth/reset-password ────────────────────────────────
    public function resetPassword(): void
    {
        $b        = $this->jsonBody();
        $token    = trim($b['token'] ?? '');
        $password = $b['password'] ?? '';

        if (!$token)               $this->fail(400, 'Reset token is required.');
        if (strlen($password) < 8) $this->fail(400, 'Password must be at least 8 characters.');

        $db   = $this->db();
        $stmt = $db->prepare(
            "SELECT lecturer_id FROM lecturers WHERE reset_token = ? AND reset_token_expires > NOW()"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) $this->fail(400, 'Invalid or expired reset token.');

        $db->prepare("
            UPDATE lecturers
            SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL
            WHERE lecturer_id = ?
        ")->execute([
            password_hash($password, PASSWORD_BCRYPT, ['cost' => Config::BCRYPT_COST]),
            $row['lecturer_id'],
        ]);

        $this->json(['success' => true, 'message' => 'Password updated. You can now sign in.']);
    }

    // ── Redirect helper ───────────────────────────────────────────────
    private function redirectToLogin(string $qs = ''): never
    {
        header('Location: ' . Config::APP_URL . '/login.html' . $qs);
        exit;
    }
}
