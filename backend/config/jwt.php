<?php
/**
 * EduCore — JWT  v5.5
 * Pure-PHP HS256. No external library required.
 */
declare(strict_types=1);

class JWT
{
    public static function encode(array $payload, string $secret): string
    {
        $h   = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p   = self::b64(json_encode($payload));
        $sig = self::b64(hash_hmac('sha256', "{$h}.{$p}", $secret, true));
        return "{$h}.{$p}.{$sig}";
    }

    /** @throws RuntimeException */
    public static function decode(string $token, string $secret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) throw new RuntimeException('Malformed token.');
        [$h, $p, $sig] = $parts;
        if (!hash_equals(self::b64(hash_hmac('sha256', "{$h}.{$p}", $secret, true)), $sig)) {
            throw new RuntimeException('Invalid signature.');
        }
        $payload = json_decode(self::b64d($p), true);
        if (!is_array($payload)) throw new RuntimeException('Invalid payload.');
        if (isset($payload['exp']) && $payload['exp'] < time()) throw new RuntimeException('Token expired.');
        return $payload;
    }

    private static function b64(string $d): string
    {
        return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    }
    private static function b64d(string $d): string
    {
        return base64_decode(strtr($d, '-_', '+/') . str_repeat('=', (4 - strlen($d) % 4) % 4));
    }
}

// ── Convenience wrappers ──────────────────────────────────────────────────────

function issueToken(array $payload, int $ttl = 0): string
{
    $payload['iat'] = time();
    $payload['exp'] = time() + ($ttl ?: Config::JWT_TTL);
    return JWT::encode($payload, Config::JWT_SECRET);
}

function issueStudentToken(array $payload, int $ttl = 0): string
{
    $payload['iat'] = time();
    $payload['exp'] = time() + ($ttl ?: Config::STUDENT_JWT_TTL);
    return JWT::encode($payload, Config::STUDENT_JWT_SECRET);
}
