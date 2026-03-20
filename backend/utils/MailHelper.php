<?php
/**
 * EduCore — MailHelper  v5.5
 * Sends transactional email via PHPMailer (SMTP) or PHP mail() fallback.
 * In development mode it only logs — no accidental live sends.
 *
 * Required .env vars for SMTP:
 *   MAIL_HOST  MAIL_PORT  MAIL_USER  MAIL_PASS  MAIL_ENCRYPTION (tls|ssl)
 */
declare(strict_types=1);

class MailHelper
{
    // ── Verification email ────────────────────────────────────────────
    public static function sendVerification(
        string $toEmail, string $toName, string $token, string $role = 'lecturer'
    ): bool {
        $route = $role === 'student' ? 'student/auth/verify-email' : 'auth/verify-email';
        $link  = Config::APP_URL . '/backend/api/' . $route . '?token=' . urlencode($token);
        return self::send(
            $toEmail, $toName,
            'Verify your EduCore account',
            self::tpl('Verify Your Email', $toName, "
                <p>Thank you for registering with <strong>EduCore</strong>.</p>
                <p>Click the button below to activate your account. This link expires in <strong>24 hours</strong>.</p>
                <div style='text-align:center;margin:28px 0'>
                  <a href='{$link}' style='background:#06b6d4;color:#fff;padding:13px 32px;
                     border-radius:8px;text-decoration:none;font-weight:700;font-size:15px'>
                     Verify Email Address</a>
                </div>
                <p style='font-size:12px;color:#aaa;word-break:break-all'>Or copy: {$link}</p>
            ")
        );
    }

    // ── Password reset ────────────────────────────────────────────────
    public static function sendPasswordReset(
        string $toEmail, string $toName, string $token, string $role = 'lecturer'
    ): bool {
        $page = $role === 'student' ? 'student-reset-password.html' : 'reset-password.html';
        $link = Config::APP_URL . '/' . $page . '?token=' . urlencode($token);
        return self::send(
            $toEmail, $toName,
            'Reset your EduCore password',
            self::tpl('Reset Your Password', $toName, "
                <p>We received a password-reset request for your <strong>EduCore</strong> account.</p>
                <p>Click below — link expires in <strong>1 hour</strong>.</p>
                <div style='text-align:center;margin:28px 0'>
                  <a href='{$link}' style='background:#6366f1;color:#fff;padding:13px 32px;
                     border-radius:8px;text-decoration:none;font-weight:700;font-size:15px'>
                     Reset Password</a>
                </div>
                <p style='font-size:13px;color:#888'>If you didn't request this, you can safely ignore this email.</p>
            ")
        );
    }

    // ── Override notification (to lecturer) ───────────────────────────
    public static function sendOverrideNotification(
        string $toEmail, string $toName,
        string $studentName, string $className
    ): bool {
        return self::send(
            $toEmail, $toName,
            "Override Request — {$studentName}",
            self::tpl('Override Request', $toName, "
                <p><strong>{$studentName}</strong> has submitted a Manual Override Request
                   for <strong>{$className}</strong>.</p>
                <p>Log in to the EduCore portal to approve or reject.</p>
            ")
        );
    }

    // ── Core send ─────────────────────────────────────────────────────
    public static function send(
        string $toEmail, string $toName, string $subject, string $html
    ): bool {
        if (Config::APP_ENV !== 'production') {
            error_log("[EduCore Mail] To:{$toEmail} Subject:{$subject}");
            return true; // dev — just log, don't send
        }

        // PHPMailer via Composer
        $pm = __DIR__ . '/../../vendor/phpmailer/phpmailer/src/PHPMailer.php';
        if (file_exists($pm) && !empty($_ENV['MAIL_HOST'])) {
            return self::viaPHPMailer($pm, $toEmail, $toName, $subject, $html);
        }

        // PHP mail() fallback
        $headers  = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\n";
        $headers .= 'From: ' . Config::MAIL_FROM_NAME . ' <' . Config::MAIL_FROM . ">\r\n";
        return (bool) mail($toEmail, $subject, $html, $headers);
    }

    private static function viaPHPMailer(
        string $pmPath, string $toEmail, string $toName,
        string $subject, string $html
    ): bool {
        require_once $pmPath;
        require_once dirname($pmPath) . '/SMTP.php';
        require_once dirname($pmPath) . '/Exception.php';
        $m = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host       = $_ENV['MAIL_HOST'];
            $m->SMTPAuth   = true;
            $m->Username   = $_ENV['MAIL_USER'];
            $m->Password   = $_ENV['MAIL_PASS'];
            $enc           = strtolower($_ENV['MAIL_ENCRYPTION'] ?? 'tls');
            $m->SMTPSecure = $enc === 'ssl'
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $m->Port       = (int)($_ENV['MAIL_PORT'] ?? 587);
            $m->CharSet    = 'UTF-8';
            $m->setFrom(Config::MAIL_FROM, Config::MAIL_FROM_NAME);
            $m->addAddress($toEmail, $toName);
            $m->Subject    = $subject;
            $m->isHTML(true);
            $m->Body       = $html;
            $m->AltBody    = strip_tags(str_replace(['<br>', '<br/>'], "\n", $html));
            $m->send();
            return true;
        } catch (\Exception $e) {
            error_log('[EduCore Mail] PHPMailer: ' . $e->getMessage());
            return false;
        }
    }

    // ── HTML email template ───────────────────────────────────────────
    private static function tpl(string $heading, string $name, string $body): string
    {
        $year = date('Y');
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"/></head>
<body style="margin:0;padding:0;background:#f4f7fa;font-family:'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:40px 20px">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0"
  style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
  <tr><td style="background:#080c14;padding:24px 40px;text-align:center">
    <span style="font-size:22px;font-weight:800;color:#22d3ee">EduCore</span>
  </td></tr>
  <tr><td style="padding:36px 40px 28px;color:#1f2937;font-size:15px;line-height:1.7">
    <h2 style="margin:0 0 18px;font-size:20px;font-weight:700;color:#111">{$heading}</h2>
    <p style="margin:0 0 10px">Hi {$name},</p>
    {$body}
    <p style="margin:24px 0 0;font-size:14px;color:#6b7280">
      Regards,<br/><strong>The EduCore Team</strong></p>
  </td></tr>
  <tr><td style="background:#f9fafb;padding:18px 40px;text-align:center;
    font-size:12px;color:#9ca3af;border-top:1px solid #e5e7eb">
    &copy; {$year} EduCore. Smart Attendance for Ghanaian Institutions.
  </td></tr>
</table>
</td></tr></table>
</body></html>
HTML;
    }
}
