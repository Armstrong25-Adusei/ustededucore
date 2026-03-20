<?php
/**
 * EduCore — SettingsController  v1.0
 *
 * GET  /api/settings  — fetch all settings for the authenticated lecturer
 * POST /api/settings  — save / upsert settings
 *
 * Settings are stored in configuration_engine as key-value pairs.
 * Supported keys (all per-lecturer):
 *   qr_expiry_seconds          int     QR code rotation period
 *   late_threshold_minutes     int     minutes after session start → late
 *   absent_threshold_minutes   int     minutes after session start → absent
 *   geofence_radius_meters     int     default geofence radius
 *   require_password           boolean SATE Layer 6
 *   gps_strict                 boolean reject out-of-geofence check-ins
 *   email_notifications        boolean override / anomaly email alerts
 *   full_name                  string  profile update (syncs to lecturers table)
 *   email                      string  profile update (syncs to lecturers table)
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';

class SettingsController extends BaseController
{
    private const ALLOWED_KEYS = [
        'qr_expiry_seconds'        => 'integer',
        'late_threshold_minutes'   => 'integer',
        'absent_threshold_minutes' => 'integer',
        'geofence_radius_meters'   => 'integer',
        'require_password'         => 'boolean',
        'gps_strict'               => 'boolean',
        'email_notifications'      => 'boolean',
    ];

    // ── GET /api/settings ─────────────────────────────────────────────────
    public function index(): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $db     = $this->db();

        $stmt = $db->prepare(
            "SELECT config_key, config_value, data_type FROM configuration_engine WHERE lecturer_id = ?"
        );
        $stmt->execute([$lecId]);
        $rows = $stmt->fetchAll();

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['config_key']] = $this->_cast($row['config_value'], $row['data_type']);
        }

        // Also return lecturer profile fields
        $pStmt = $db->prepare(
            "SELECT full_name, email, last_login FROM lecturers WHERE lecturer_id = ?"
        );
        $pStmt->execute([$lecId]);
        $profile = $pStmt->fetch();

        $this->json(['settings' => $settings, 'profile' => $profile ?: []]);
    }

    // ── POST /api/settings ────────────────────────────────────────────────
    public function save(): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $b      = $this->jsonBody();
        $db     = $this->db();

        // Profile fields — sync directly to lecturers table
        $profileSets = [];
        $profileVals = [];

        if (isset($b['full_name']) && trim($b['full_name'])) {
            $profileSets[] = 'full_name = ?';
            $profileVals[] = trim($b['full_name']);
        }
        if (isset($b['email']) && filter_var(trim($b['email']), FILTER_VALIDATE_EMAIL)) {
            // Check for duplicate email
            $dupStmt = $db->prepare(
                "SELECT lecturer_id FROM lecturers WHERE email = ? AND lecturer_id != ?"
            );
            $dupStmt->execute([trim($b['email']), $lecId]);
            if ($dupStmt->fetch()) $this->fail(409, 'Email already in use by another account.');
            $profileSets[] = 'email = ?';
            $profileVals[] = strtolower(trim($b['email']));
        }
        if (!empty($profileSets)) {
            $profileVals[] = $lecId;
            $db->prepare(
                "UPDATE lecturers SET " . implode(', ', $profileSets) . " WHERE lecturer_id = ?"
            )->execute($profileVals);

            // Update cached ec_lecturer info
            unset($b['full_name'], $b['email']);
        }

        // Password change
        if (!empty($b['new_password'])) {
            if (strlen($b['new_password']) < 8) $this->fail(400, 'Password must be at least 8 characters.');
            if ($b['new_password'] !== ($b['confirm_password'] ?? '')) $this->fail(400, 'Passwords do not match.');
            $hash = password_hash($b['new_password'], PASSWORD_BCRYPT, ['cost' => Config::BCRYPT_COST]);
            $db->prepare("UPDATE lecturers SET password_hash = ? WHERE lecturer_id = ?")
               ->execute([$hash, $lecId]);
        }

        // Config key-value pairs
        $upsert = $db->prepare("
            INSERT INTO configuration_engine (lecturer_id, config_key, config_value, data_type)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), data_type = VALUES(data_type)
        ");

        foreach (self::ALLOWED_KEYS as $key => $type) {
            if (!array_key_exists($key, $b)) continue;
            $val = $b[$key];
            if ($type === 'boolean') $val = $val ? '1' : '0';
            elseif ($type === 'integer') $val = (string)(int)$val;
            else $val = (string)$val;
            $upsert->execute([$lecId, $key, $val, $type]);
        }

        $this->ok(null, 'Settings saved.');
    }

    private function _cast(string $val, string $type): mixed
    {
        return match ($type) {
            'integer' => (int)$val,
            'boolean' => (bool)(int)$val,
            default   => $val,
        };
    }
}
