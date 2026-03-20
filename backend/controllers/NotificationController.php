<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';

class NotificationController extends BaseController
{
    public function index(): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];
        $limit  = min(max((int)($_GET['limit'] ?? 30), 1), 100);
        $db     = $this->db();

        $stmt = $db->prepare(
            "SELECT notification_id, lecturer_id, title, body, type, related_id,
                    is_read, read_at, created_at
             FROM notifications
             WHERE lecturer_id = ?
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $lecId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->json(['notifications' => $rows]);
    }

    public function unreadCount(): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];

        $stmt = $this->db()->prepare(
            "SELECT COUNT(*) AS count
             FROM notifications
             WHERE lecturer_id = ? AND is_read = 0"
        );
        $stmt->execute([$lecId]);
        $count = (int)($stmt->fetchColumn() ?: 0);

        $this->json(['count' => $count]);
    }

    public function markRead(int $id): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];

        $stmt = $this->db()->prepare(
            "UPDATE notifications
             SET is_read = 1, read_at = NOW()
             WHERE notification_id = ? AND lecturer_id = ?"
        );
        $stmt->execute([$id, $lecId]);

        $this->json(['success' => true]);
    }

    public function markAllRead(): void
    {
        $claims = AuthMiddleware::lecturer();
        $lecId  = (int)$claims['lecturer_id'];

        $stmt = $this->db()->prepare(
            "UPDATE notifications
             SET is_read = 1, read_at = NOW()
             WHERE lecturer_id = ? AND is_read = 0"
        );
        $stmt->execute([$lecId]);

        $this->json(['success' => true]);
    }
}
