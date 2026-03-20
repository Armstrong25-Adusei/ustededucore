<?php
/**
 * EduCore — QR Code Generator Helper
 *
 * Generates a signed, time-expiring QR payload for the Tertiary attendance path.
 */

declare(strict_types=1);

class QRGenerator {

    private const CLOCK_GRACE_PERIOD = 10; // 10 seconds leeway for network latency

    /**
     * Generate a rotating QR token.
     *
     * @param int    $sessionId        Active session PK
     * @param int    $classId          Class PK
     * @param float  $anchorLat        Classroom GPS latitude
     * @param float  $anchorLng        Classroom GPS longitude
     * @param int    $rotationSeconds  How long this token is valid (default 30 s)
     * @return array{token: string, expires_at: int, qr_string: string}
     */
    public static function generate(
        int   $sessionId,
        int   $classId,
        float $anchorLat,
        float $anchorLng,
        int   $rotationSeconds = 30
    ): array {
        $now       = time();
        $expiresAt = $now + $rotationSeconds;

        $payload = [
            'session_id' => $sessionId,
            'class_id'   => $classId,
            'anchor_lat' => $anchorLat,
            'anchor_lng' => $anchorLng,
            'iat'        => $now,
            'exp'        => $expiresAt,
            'nonce'      => bin2hex(random_bytes(8)), // Increased entropy
        ];

        try {
            $encoded = self::base64url(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to encode QR payload: ' . $e->getMessage());
        }

        $signature = self::sign($encoded);
        $qrString  = "{$encoded}.{$signature}";

        return [
            'token'      => $qrString,
            'expires_at' => $expiresAt,
            'qr_string'  => $qrString,
        ];
    }

    /**
     * Verify and decode a QR token received from a student's device.
     */
    public static function verify(string $qrString): array {
        $parts = explode('.', $qrString);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Malformed QR token structure.');
        }

        [$encoded, $sig] = $parts;

        // 1. Validate Signature FIRST
        if (!hash_equals(self::sign($encoded), $sig)) {
            throw new RuntimeException('QR token signature invalid.');
        }

        // 2. Decode JSON with error handling
        try {
            $payload = json_decode(self::base64urlDecode($encoded), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('QR token payload corrupted or invalid JSON.');
        }

        // 3. Check Expiration with Grace Period
        // This prevents errors if the student scans at the very last second.
        if (time() > ($payload['exp'] + self::CLOCK_GRACE_PERIOD)) {
            throw new RuntimeException('QR token expired. Please scan the latest code appearing on the screen.');
        }

        return $payload;
    }

    // ── Internal Helpers ──────────────────────────────────────────────────────

    private static function sign(string $data): string {
        // Priority: Config Class -> Env -> Exception
        $secret = defined('Config::QR_SECRET') ? Config::QR_SECRET : ($_ENV['QR_SECRET'] ?? '');
        
        if (empty($secret) || $secret === 'fallback-change-me') {
            throw new RuntimeException('QR Security Secret is not properly configured.');
        }

        return self::base64url(hash_hmac('sha256', $data, $secret, true));
    }

    private static function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return (string)base64_decode(strtr($data, '-_', '+/'));
    }
}