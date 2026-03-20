<?php
// ============================================================
// EduCore/backend/models/ConfigModel.php
// configuration_engine table — per-teacher settings
// ============================================================
declare(strict_types=1);

class ConfigModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    public function allByTeacher(int $teacherId): array
    {
        $stmt = $this->db->prepare(
            "SELECT config_key, config_value, institution_type
             FROM configuration_engine WHERE teacher_id = ?"
        );
        $stmt->execute([$teacherId]);

        // Return as key → value map for easy JS consumption
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['config_key']] = $row['config_value'];
        }
        return $result;
    }

    public function get(int $teacherId, string $key, string $default = ''): string
    {
        $stmt = $this->db->prepare(
            "SELECT config_value FROM configuration_engine
             WHERE teacher_id = ? AND config_key = ? LIMIT 1"
        );
        $stmt->execute([$teacherId, $key]);
        return (string)($stmt->fetchColumn() ?: $default);
    }

    public function save(int $teacherId, array $settings): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO configuration_engine (teacher_id, config_key, config_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        );

        foreach ($settings as $key => $value) {
            $stmt->execute([$teacherId, $key, (string)$value]);
        }
    }
}
