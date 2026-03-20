<?php
/**
 * EduCore — AuthMiddleware  v5.5
 * Call AuthMiddleware::lecturer() or ::student() at the top of any protected action.
 * Both return the decoded JWT payload or terminate with 401.
 */
declare(strict_types=1);

class AuthMiddleware
{
    /** Require a valid lecturer token. Returns payload. */
    public static function lecturer(): array
    {
        return self::require(Config::JWT_SECRET, 'lecturer_id');
    }

    /** Require a valid student token. Returns payload. */
    public static function student(): array
    {
        return self::require(Config::STUDENT_JWT_SECRET, 'student_id');
    }

    private static function require(string $secret, string $claimKey): array
    {
        // Apache may pass Authorization header as HTTP_AUTHORIZATION or via RewriteRule environment
        $header = $_SERVER['HTTP_AUTHORIZATION'] 
               ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] 
               ?? getenv('HTTP_AUTHORIZATION')
               ?? '';
        
        if (!str_starts_with($header, 'Bearer ')) self::abort('No Bearer token provided.');
        $token = trim(substr($header, 7));
        if (!$token) self::abort('Empty token.');
        try {
            $payload = JWT::decode($token, $secret);
        } catch (RuntimeException $e) {
            self::abort($e->getMessage());
        }
        if (empty($payload[$claimKey])) self::abort('Invalid token claims.');
        return $payload;
    }

    private static function abort(string $msg): never
    {
        http_response_code(401);
        echo json_encode(['error' => $msg]);
        exit;
    }
}
