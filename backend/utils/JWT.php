<?php
// ============================================================
// EduCore/backend/utils/JWT.php
// Pure PHP JWT implementation — Hardened for Production
// ============================================================

declare(strict_types=1);

/**
 * Custom Exception for JWT specific errors
 */
class JWTException extends RuntimeException {}

class JWT
{
    private const CLOCK_LEEWAY = 60; // 60 seconds to account for server/phone clock drift

    /**
     * Encodes a payload into a signed JWT string.
     */
    public static function encode(array $payload, int $ttl = 3600): string
    {
        // Ensure the secret is actually set
        $secret = defined('Config::JWT_SECRET') ? Config::JWT_SECRET : '';
        if (empty($secret)) {
            throw new JWTException('JWT Secret is not configured.');
        }

        $header = self::base64url(json_encode([
            'alg' => 'HS256', // Hardcode to HS256 for this implementation
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));

        $now = time();
        $payload['iat'] = $now;
        $payload['nbf'] = $now - self::CLOCK_LEEWAY; // Allow immediate use even with slight drift
        
        if ($ttl > 0) {
            $payload['exp'] = $now + $ttl;
        }

        $body = self::base64url(json_encode($payload, JSON_THROW_ON_ERROR));
        $sig  = self::base64url(self::sign("{$header}.{$body}", $secret));

        return "{$header}.{$body}.{$sig}";
    }

    /**
     * Decodes and verifies a JWT string.
     */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new JWTException('Invalid token structure.', 401);
        }

        [$header64, $body64, $sig64] = $parts;

        // 1. Verify Header & Algorithm (Prevents "None" Algorithm attacks)
        $header = json_decode(self::base64urlDecode($header64), true);
        if (!isset($header['alg']) || $header['alg'] !== 'HS256') {
            throw new JWTException('Unsupported or missing algorithm.', 401);
        }

        // 2. Verify Signature
        $secret = Config::JWT_SECRET;
        $expected = self::base64url(self::sign("{$header64}.{$body64}", $secret));
        
        if (!hash_equals($expected, $sig64)) {
            throw new JWTException('Token signature invalid.', 401);
        }

        // 3. Decode Payload
        try {
            $payload = json_decode(self::base64urlDecode($body64), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JWTException('Token payload malformed.', 401);
        }

        $now = time();

        // 4. Check Expiry with Leeway
        if (isset($payload['exp']) && ($payload['exp'] + self::CLOCK_LEEWAY) < $now) {
            throw new JWTException('Token has expired.', 401);
        }

        // 5. Check Not-Before with Leeway
        if (isset($payload['nbf']) && $payload['nbf'] > ($now + self::CLOCK_LEEWAY)) {
            throw new JWTException('Token not yet valid.', 401);
        }

        return $payload;
    }

    /**
     * Extracts payload without throwing. Use for non-critical logging.
     */
    public static function tryDecode(string $token): ?array
    {
        try {
            return self::decode($token);
        } catch (Throwable) {
            return null;
        }
    }

    // ── Internal helpers ─────────────────────────────────────

    private static function sign(string $data, string $secret): string
    {
        return hash_hmac('sha256', $data, $secret, true);
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}