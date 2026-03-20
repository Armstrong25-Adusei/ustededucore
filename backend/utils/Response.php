<?php
// ============================================================
// EduCore/backend/utils/Response.php
// Hardened Standardised JSON response builder
// ============================================================

declare(strict_types=1);

class Response
{
    /**
     * Core sender method to ensure consistency and prevent silent failures.
     */
    private static function send(array $body, int $code): void
    {
        // 1. Set the correct Content-Type header
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($code);
        }

        // 2. Add a global 'timestamp' for easier debugging in logs/frontend
        $body['timestamp'] = time();

        try {
            // 3. Force an error if the data can't be encoded (e.g. recursion or malformed UTF-8)
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            echo $json;
        } catch (JsonException $e) {
            // Fallback for when the payload itself is broken
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Internal Server Error: Response encoding failed.',
                'debug'   => $e->getMessage()
            ]);
        }

        // 4. In a pure PHP environment, we exit. 
        // In a framework, we would return the string instead.
        exit;
    }

    /**
     * 200 OK with data payload
     */
    public static function json(mixed $data, int $code = 200, string $message = 'OK'): void
    {
        self::send([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    /**
     * 200 OK for paginated lists
     */
    public static function paginated(array $items, int $total, int $page, int $perPage): void
    {
        $perPage = max($perPage, 1);
        self::send([
            'success'    => true,
            'data'       => $items,
            'pagination' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ], 200);
    }

    /**
     * Error response (4xx and 5xx)
     */
    public static function error(string $message, int $code = 400, array $errors = []): void
    {
        $body = [
            'success' => false,
            'message' => $message
        ];

        if (!empty($errors)) {
            $body['errors'] = $errors;
        }

        self::send($body, $code);
    }

    // --- Specific Helpers ---

    public static function created(mixed $data, string $message = 'Created successfully.'): void
    {
        self::json($data, 201, $message);
    }

    public static function unauthorized(string $message = 'Authentication required.'): void
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Access denied.'): void
    {
        self::error($message, 403);
    }

    public static function notFound(string $message = 'Resource not found.'): void
    {
        self::error($message, 404);
    }

    public static function validation(array $errors): void
    {
        self::error('Validation failed.', 422, $errors);
    }

    public static function noContent(): void
    {
        if (!headers_sent()) {
            http_response_code(204);
        }
        exit;
    }
}