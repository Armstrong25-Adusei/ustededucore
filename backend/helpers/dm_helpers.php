<?php
/**
 * EduCore — DM Helpers
 * Shared utilities for direct messaging endpoints.
 */

declare(strict_types=1);

/**
 * Authenticate and extract lecturer ID from JWT token.
 */
function dm_require_lecturer(PDO $pdo): int
{
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? '';
    
    if (!$auth || !preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        dm_err('Unauthorized: missing JWT token', 401);
    }
    
    // Verify JWT and extract lecturer_id
    // (assuming your auth middleware provides this)
    $token = $m[1];
    try {
        $decoded = json_decode(base64_decode(explode('.', $token)[1]), true);
        if (!$decoded || empty($decoded['lecturer_id'])) {
            dm_err('Unauthorized: invalid token', 401);
        }
        return (int)$decoded['lecturer_id'];
    } catch (Throwable) {
        dm_err('Unauthorized: token error', 401);
    }
}

/**
 * Authenticate and extract student ID from JWT token.
 */
function dm_require_student(PDO $pdo): int
{
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? '';
    
    if (!$auth || !preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        dm_err('Unauthorized: missing JWT token', 401);
    }
    
    $token = $m[1];
    try {
        $decoded = json_decode(base64_decode(explode('.', $token)[1]), true);
        if (!$decoded || empty($decoded['student_id'])) {
            dm_err('Unauthorized: invalid token', 401);
        }
        return (int)$decoded['student_id'];
    } catch (Throwable) {
        dm_err('Unauthorized: token error', 401);
    }
}

/**
 * Verify a lecturer owns a thread.
 */
function dm_lecturer_thread(PDO $pdo, int $thread_id, int $lec_id): void
{
    $stmt = $pdo->prepare("SELECT thread_id FROM direct_message_threads WHERE thread_id = ? AND lecturer_id = ?");
    $stmt->execute([$thread_id, $lec_id]);
    if (!$stmt->fetch()) {
        dm_err('Thread not found or access denied', 403);
    }
}

/**
 * Verify a student is in a thread.
 */
function dm_student_thread(PDO $pdo, int $thread_id, int $stu_id): void
{
    $stmt = $pdo->prepare("SELECT thread_id FROM direct_message_threads WHERE thread_id = ? AND student_id = ?");
    $stmt->execute([$thread_id, $stu_id]);
    if (!$stmt->fetch()) {
        dm_err('Thread not found or access denied', 403);
    }
}

/**
 * Get reactions for a set of message IDs.
 * Returns: [ message_id => [ emoji => [ [reactor_type => 'lecturer', reactor_id => 1], ... ], ... ], ... ]
 */
function dm_reactions_for(PDO $pdo, array $dm_ids, ?string $reactor_type = null, ?int $reactor_id = null): array
{
    if (empty($dm_ids)) return [];
    
    $ph = implode(',', array_fill(0, count($dm_ids), '?'));
    $sql = "SELECT dm_id, emoji, reactor_type, reactor_id FROM dm_reactions WHERE dm_id IN ($ph)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($dm_ids);
    
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(int)$row['dm_id']][$row['emoji']][] = [
            'reactor_type' => $row['reactor_type'],
            'reactor_id' => (int)$row['reactor_id'],
        ];
    }
    
    return $result;
}

/**
 * Format a message row for API response.
 */
function dm_format_message(
    array $row,
    array $reactions = [],
    ?array $parent_preview = null
): array {
    return [
        'message_id'   => (int)$row['dm_id'],
        'thread_id'    => (int)$row['thread_id'],
        'sender_type'  => $row['sender_type'],
        'sender_id'    => (int)$row['sender_id'],
        'sender_name'  => $row['sender_name'] ?? 'Unknown',
        'sender_photo' => $row['sender_photo'] ?? null,
        'body'         => $row['body'],
        'parent_id'    => $row['parent_id'] ? (int)$row['parent_id'] : null,
        'parent'       => $parent_preview,
        'is_deleted'   => (bool)$row['is_deleted'],
        'reactions'    => $reactions,
        'created_at'   => $row['created_at'],
    ];
}

/**
 * Send JSON response and exit.
 */
function dm_json($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send error and exit.
 */
function dm_err(string $message, int $status = 400): never
{
    dm_json(['error' => $message, 'status' => $status], $status);
}
