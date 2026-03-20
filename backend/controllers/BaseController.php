<?php
/**
 * EduCore — BaseController  v5.5
 * Shared helpers inherited by every controller.
 */
declare(strict_types=1);

abstract class BaseController
{
    // ── Responses ─────────────────────────────────────────────────────
    protected function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    protected function ok(mixed $data = null, string $message = 'OK'): void
    {
        $this->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    protected function created(mixed $data = null, string $message = 'Created'): void
    {
        $this->json(['success' => true, 'message' => $message, 'data' => $data], 201);
    }

    protected function fail(int $status, string $message, array $errors = []): never
    {
        $body = ['error' => $message];
        if ($errors) $body['errors'] = $errors;
        http_response_code($status);
        echo json_encode($body);
        exit;
    }

    // ── Input ─────────────────────────────────────────────────────────
    protected function jsonBody(): array
    {
        $raw  = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    protected function requireFields(array $body, array $fields): void
    {
        foreach ($fields as $f) {
            if (!isset($body[$f]) || (is_string($body[$f]) && trim($body[$f]) === '')) {
                $this->fail(400, "Field '{$f}' is required.");
            }
        }
    }

    // ── Database ──────────────────────────────────────────────────────
    protected function db(): PDO { return Database::connect(); }

    // ── Audit logging ─────────────────────────────────────────────────
    protected function audit(int $actorId, string $actorType, string $action, array $meta = []): void
    {
        try {
            $this->db()->prepare("
                INSERT INTO audit_logs (actor_id, actor_type, action, device_uuid, meta, logged_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ")->execute([
                $actorId, $actorType, $action,
                $meta['device_uuid'] ?? null,
                json_encode($meta),
            ]);
        } catch (\Throwable) { /* Non-fatal — never crash main request */ }
    }

    // ── Haversine distance (metres) ───────────────────────────────────
    protected function haversineM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
