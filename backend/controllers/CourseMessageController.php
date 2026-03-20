<?php
/**
 * EduCore — CourseMessageController.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Course channel messaging — lecturer side.
 * All routes require a valid lecturer JWT.
 *
 * ROUTES (registered in api.php under 'messages'):
 *
 *   GET    messages/unread-counts
 *          → { channels: [{ class_id, class_name, course_code, unread }] }
 *          Counts unread messages per channel for the authenticated lecturer.
 *
 *   GET    messages/{class_id}?since={lastId}&limit={n}
 *          → { messages: [...], has_more: bool }
 *          Cursor-paginated history, newest-first when since=0, ascending when
 *          since > 0 (append-only polling). Each message includes sender info
 *          (name, photo), reactions aggregated, and reply preview if parent_id set.
 *
 *   POST   messages/{class_id}
 *          Body: { body: string, is_broadcast: 0|1, parent_id: int|null }
 *          Inserts into course_messages.  Returns the full new message object
 *          (same shape as index rows) so the frontend can append it immediately.
 *
 *   POST   messages/{class_id}/read
 *          Body: { up_to_message_id: int }
 *          Batch-inserts rows into message_reads for every unread message in
 *          the channel up to (and including) up_to_message_id.
 *          Uses INSERT IGNORE to leverage the unique key.
 *
 *   POST   messages/{msg_id}/react
 *          Body: { emoji: string }
 *          Toggles: deletes the row if it exists, inserts if absent.
 *          Returns { action: 'added'|'removed', emoji, message_id }.
 *
 *   DELETE messages/{msg_id}
 *          Lecturer-only soft-delete.
 *          Sets is_deleted = 1, deleted_by = 'lecturer'.
 *
 * SECURITY:
 *   • Every channel access is validated: the class must belong to this lecturer.
 *   • Body is stripped of HTML tags (strip_tags) and trimmed.
 *   • Max body length 2 000 chars; rejection returns 422.
 *   • Rate limit: max 20 sends per 30 s per lecturer (generous — no limit on reads).
 *   • Emoji in `react` validated as a single grapheme cluster ≤ 8 bytes.
 *
 * DB TABLES:
 *   course_messages   — message_id, class_id, sender_type, sender_id,
 *                       body (TEXT, utf8mb4), parent_id, is_deleted,
 *                       deleted_by, created_at
 *   message_reads     — read_id, message_id, reader_type, reader_id, read_at
 *                       UNIQUE KEY uq_read (message_id, reader_type, reader_id)
 *   message_reactions — reaction_id, message_id, reactor_type, reactor_id,
 *                       emoji (VARCHAR 8, utf8mb4), created_at
 *                       UNIQUE KEY uq_reaction (message_id, reactor_type, reactor_id, emoji)
 * ─────────────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

class CourseMessageController
{
    private const MAX_BODY_LEN  = 2000;   // chars
    private const DEFAULT_LIMIT = 40;
    private const MAX_LIMIT     = 100;
    private const RATE_WINDOW   = 30;     // seconds
    private const RATE_MAX      = 20;     // messages per window

    private \PDO $db;
    private int  $lecturerId;

    public function __construct()
    {
        $this->db         = Database::connect();
        $claims           = AuthMiddleware::lecturer();
        $this->lecturerId = (int)$claims['lecturer_id'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET messages/unread-counts
    // ─────────────────────────────────────────────────────────────────────────
    public function unreadCounts(): void
    {
        // For every class owned by this lecturer, count messages that have
        // no corresponding message_reads row for this lecturer.
        $stmt = $this->db->prepare(
            'SELECT
                c.class_id,
                c.class_name,
                c.course_code,
                COUNT(m.message_id) AS unread
             FROM classes c
             LEFT JOIN course_messages m
                ON  m.class_id    = c.class_id
                AND m.is_deleted  = 0
                AND NOT EXISTS (
                    SELECT 1 FROM message_reads mr
                    WHERE  mr.message_id   = m.message_id
                    AND    mr.reader_type  = \'lecturer\'
                    AND    mr.reader_id    = c.lecturer_id
                )
             WHERE c.lecturer_id  = ?
               AND c.is_archived  = 0
             GROUP BY c.class_id, c.class_name, c.course_code
             ORDER BY unread DESC, c.class_name ASC'
        );
        $stmt->execute([$this->lecturerId]);

        $channels = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $channels[] = [
                'class_id'    => (int)$row['class_id'],
                'class_name'  => $row['class_name'],
                'course_code' => $row['course_code'],
                'unread'      => (int)$row['unread'],
            ];
        }

        http_response_code(200);
        echo json_encode(['channels' => $channels]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET lecturer/messages/dms
    // ─────────────────────────────────────────────────────────────────────────
    public function getDirectMessages(): void
    {
        $stmt = $this->db->prepare(
            'SELECT
                t.thread_id                                                      AS dm_id,
                t.student_id,
                s.student_name,
                s.profile_photo                                                  AS student_photo,
                s.index_number,
                s.program,
                (SELECT d.body
                   FROM direct_messages d
                  WHERE d.thread_id = t.thread_id AND d.is_deleted = 0
                  ORDER BY d.dm_id DESC LIMIT 1)                                 AS last_message,
                (SELECT d.created_at
                   FROM direct_messages d
                  WHERE d.thread_id = t.thread_id
                  ORDER BY d.dm_id DESC LIMIT 1)                                 AS last_time,
                (SELECT COUNT(*)
                   FROM direct_messages d2
                   LEFT JOIN dm_reads r
                          ON r.dm_id       = d2.dm_id
                         AND r.reader_type = \'lecturer\'
                         AND r.reader_id   = t.lecturer_id
                  WHERE d2.thread_id   = t.thread_id
                    AND d2.sender_type = \'student\'
                    AND d2.is_deleted  = 0
                    AND r.read_id IS NULL)                                        AS unread_count
             FROM direct_message_threads t
             JOIN students s ON s.student_id = t.student_id
             WHERE t.lecturer_id = ?
             ORDER BY last_time DESC'
        );
        $stmt->execute([$this->lecturerId]);

        $rows = [];
        while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $rows[] = [
                'dm_id'         => (int)$r['dm_id'],
                'thread_id'     => (int)$r['dm_id'],
                'student_id'    => (int)$r['student_id'],
                'student_name'  => $r['student_name'] ?? 'Student',
                'student_photo' => $this->photoIfExists($r['student_photo'] ?? null),
                'index_number'  => $r['index_number'] ?? null,
                'program'       => $r['program'] ?? null,
                'last_message'  => $r['last_message'],
                'last_time'     => $r['last_time'],
                'unread_count'  => (int)$r['unread_count'],
            ];
        }

        http_response_code(200);
        echo json_encode($rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET lecturer/messages/students
    // ─────────────────────────────────────────────────────────────────────────
    public function getDmStudents(): void
    {
        // Join through class_enrollments so we correctly pick up every student
        // enrolled in any of this lecturer's active classes.
        $stmt = $this->db->prepare(
            'SELECT DISTINCT
                s.student_id,
                s.student_name,
                s.profile_photo AS student_photo,
                s.index_number,
                s.program,
                c.class_name,
                c.course_code,
                c.class_id
             FROM students s
             JOIN class_enrollments ce ON ce.student_id = s.student_id
             JOIN classes            c  ON c.class_id   = ce.class_id
             WHERE c.lecturer_id    = ?
               AND c.is_archived    = 0
               AND s.account_status = \'active\'
             ORDER BY s.student_name ASC'
        );
        $stmt->execute([$this->lecturerId]);

        $rows = [];
        while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $rows[] = [
                'student_id'    => (int)$r['student_id'],
                'student_name'  => $r['student_name'] ?? 'Student',
                'student_photo' => $this->photoIfExists($r['student_photo'] ?? null),
                'index_number'  => $r['index_number'] ?? null,
                'program'       => $r['program'] ?? null,
                'class_name'    => $r['class_name'] ?? null,
                'course_code'   => $r['course_code'] ?? null,
                'class_id'      => (int)$r['class_id'],
            ];
        }

        http_response_code(200);
        echo json_encode($rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST lecturer/messages/dm/start
    // ─────────────────────────────────────────────────────────────────────────
    public function startDm(): void
    {
        $data      = json_decode(file_get_contents('php://input'), true) ?? [];
        $studentId = (int)($data['student_id'] ?? 0);
        if ($studentId <= 0) {
            $this->fail(422, 'student_id is required.');
        }

        // Security: student must be enrolled in at least one of this lecturer's classes
        $chk = $this->db->prepare(
            'SELECT 1 FROM class_enrollments ce
             JOIN classes c ON c.class_id = ce.class_id
             WHERE ce.student_id = ? AND c.lecturer_id = ? AND c.is_archived = 0
             LIMIT 1'
        );
        $chk->execute([$studentId, $this->lecturerId]);
        if (!$chk->fetch()) {
            $this->fail(403, 'Student is not enrolled in any of your classes.');
        }

        // Fetch student info (verified they're accessible above)
        $stuStmt = $this->db->prepare(
            'SELECT student_id, student_name, profile_photo FROM students WHERE student_id = ? LIMIT 1'
        );
        $stuStmt->execute([$studentId]);
        $stu = $stuStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$stu) {
            $this->fail(404, 'Student not found.');
        }

        // INSERT IGNORE is idempotent: creates the thread only if it doesn't exist.
        // The UNIQUE KEY uq_lec_stu (lecturer_id, student_id) prevents duplicates.
        $this->db->prepare(
            'INSERT IGNORE INTO direct_message_threads (lecturer_id, student_id)
             VALUES (?, ?)'
        )->execute([$this->lecturerId, $studentId]);

        // Fetch the thread (whether just created or already existing)
        $find = $this->db->prepare(
            'SELECT thread_id FROM direct_message_threads
             WHERE lecturer_id = ? AND student_id = ?'
        );
        $find->execute([$this->lecturerId, $studentId]);
        $thread = $find->fetch(\PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            'success'          => true,
            'dm_id'            => (int)$thread['thread_id'],
            'thread_id'        => (int)$thread['thread_id'],
            'student_id'       => (int)$stu['student_id'],
            'student_name'     => $stu['student_name'] ?? 'Student',
            'student_photo'    => $this->photoIfExists($stu['profile_photo'] ?? null),
            'participant_type' => 'student',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET lecturer/messages/dm/{thread_id}?since={dm_id}
    // ─────────────────────────────────────────────────────────────────────────
    public function getDmThread(int $threadId): void
    {
        // Verify lecturer owns this thread
        $stmt = $this->db->prepare(
            'SELECT thread_id FROM direct_message_threads
             WHERE thread_id = ? AND lecturer_id = ?'
        );
        $stmt->execute([$threadId, $this->lecturerId]);
        if (!$stmt->fetch()) {
            $this->fail(403, 'Thread access denied.');
        }

        $since = max(0, (int)($_GET['since'] ?? 0));

        // Fetch messages with sender info and parent preview in one query
        $msgStmt = $this->db->prepare(
            'SELECT
                d.dm_id,
                d.thread_id,
                d.sender_type,
                d.sender_id,
                d.body,
                d.parent_id,
                d.is_deleted,
                d.created_at,
                CASE d.sender_type
                    WHEN \'lecturer\' THEN l.full_name
                    WHEN \'student\'  THEN s.student_name
                END AS sender_name,
                CASE d.sender_type
                    WHEN \'lecturer\' THEN l.profile_photo
                    WHEN \'student\'  THEN s.profile_photo
                END AS sender_photo,
                p.body AS parent_body,
                CASE p.sender_type
                    WHEN \'lecturer\' THEN pl.full_name
                    WHEN \'student\'  THEN ps.student_name
                END AS parent_sender
             FROM direct_messages d
             LEFT JOIN lecturers l  ON d.sender_type = \'lecturer\' AND d.sender_id = l.lecturer_id
             LEFT JOIN students  s  ON d.sender_type = \'student\'  AND d.sender_id = s.student_id
             LEFT JOIN direct_messages p  ON p.dm_id       = d.parent_id
             LEFT JOIN lecturers       pl ON p.sender_type  = \'lecturer\' AND p.sender_id = pl.lecturer_id
             LEFT JOIN students        ps ON p.sender_type  = \'student\'  AND p.sender_id = ps.student_id
             WHERE d.thread_id = ? AND d.dm_id > ?
             ORDER BY d.dm_id ASC
             LIMIT 200'
        );
        $msgStmt->execute([$threadId, $since]);
        $rows = $msgStmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            http_response_code(200);
            echo json_encode([]);
            return;
        }

        // Batch-fetch all reactions in ONE query (avoids N+1)
        $dmIds = array_column($rows, 'dm_id');
        $ph    = implode(',', array_fill(0, count($dmIds), '?'));
        $rxnStmt = $this->db->prepare(
            "SELECT dm_id, emoji, reactor_type, reactor_id
             FROM dm_reactions WHERE dm_id IN ($ph)"
        );
        $rxnStmt->execute($dmIds);

        // Group reactions: [dm_id][emoji] → {count, reacted_by_me}
        $rxnMap = [];
        while ($rxn = $rxnStmt->fetch(\PDO::FETCH_ASSOC)) {
            $id = $rxn['dm_id'];
            $em = $rxn['emoji'];
            if (!isset($rxnMap[$id][$em])) {
                $rxnMap[$id][$em] = ['emoji' => $em, 'count' => 0, 'reacted_by_me' => false];
            }
            $rxnMap[$id][$em]['count']++;
            if ($rxn['reactor_type'] === 'lecturer' && (int)$rxn['reactor_id'] === $this->lecturerId) {
                $rxnMap[$id][$em]['reacted_by_me'] = true;
            }
        }

        $messages = [];
        foreach ($rows as $row) {
            $id       = (int)$row['dm_id'];
            $reactions = isset($rxnMap[$id]) ? array_values($rxnMap[$id]) : [];

            $messages[] = [
                'message_id'    => $id,
                'thread_id'     => (int)$row['thread_id'],
                'sender_type'   => $row['sender_type'],
                'sender_id'     => (int)$row['sender_id'],
                'sender_name'   => $row['sender_name'] ?? 'Unknown',
                'sender_photo'  => $this->photoIfExists($row['sender_photo'] ?? null),
                'body'          => $row['is_deleted'] ? null : $row['body'],
                'is_deleted'    => (bool)$row['is_deleted'],
                'is_broadcast'  => false,   // DMs are never broadcasts
                'parent_id'     => $row['parent_id'] ? (int)$row['parent_id'] : null,
                'parent_sender' => $row['parent_sender'] ?? null,
                'parent_body'   => ($row['parent_body'] && !$row['is_deleted'])
                                     ? mb_substr($row['parent_body'], 0, 120) : null,
                'reactions'     => $reactions,
                'created_at'    => $row['created_at'],
            ];
        }

        // Batch mark all fetched messages as read by this lecturer
        $ph2  = implode(',', array_fill(0, count($dmIds), '(?,?,?)'));
        $vals = [];
        foreach ($dmIds as $mid) {
            $vals[] = $mid;
            $vals[] = 'lecturer';
            $vals[] = $this->lecturerId;
        }
        $this->db->prepare(
            "INSERT IGNORE INTO dm_reads (dm_id, reader_type, reader_id) VALUES $ph2"
        )->execute($vals);

        http_response_code(200);
        echo json_encode($messages);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST lecturer/messages/dm/{thread_id}/send
    // ─────────────────────────────────────────────────────────────────────────
    public function sendDm(int $threadId): void
    {
        // Verify lecturer owns this thread
        $stmt = $this->db->prepare(
            'SELECT thread_id FROM direct_message_threads
             WHERE thread_id = ? AND lecturer_id = ?'
        );
        $stmt->execute([$threadId, $this->lecturerId]);
        if (!$stmt->fetch()) {
            $this->fail(403, 'Thread access denied.');
        }

        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
        $body     = trim((string)($data['body'] ?? ''));
        $parentId = isset($data['parent_id']) && is_numeric($data['parent_id'])
                    ? (int)$data['parent_id'] : null;

        if ($body === '') {
            $this->fail(422, 'Message body cannot be empty.');
        }
        if (mb_strlen($body) > self::MAX_BODY_LEN) {
            $this->fail(422, 'Message body cannot exceed ' . self::MAX_BODY_LEN . ' characters.');
        }

        // Validate parent belongs to same thread
        if ($parentId) {
            $parentStmt = $this->db->prepare(
                'SELECT dm_id FROM direct_messages WHERE dm_id = ? AND thread_id = ?'
            );
            $parentStmt->execute([$parentId, $threadId]);
            if (!$parentStmt->fetch()) $parentId = null;
        }

        // Insert — let the DEFAULT current_timestamp() handle created_at
        $insertStmt = $this->db->prepare(
            'INSERT INTO direct_messages (thread_id, sender_type, sender_id, body, parent_id)
             VALUES (?, \'lecturer\', ?, ?, ?)'
        );
        $insertStmt->execute([$threadId, $this->lecturerId, $body, $parentId]);
        $dmId = (int)$this->db->lastInsertId();

        // Fetch inserted row with sender info and created_at from DB
        $fetchStmt = $this->db->prepare(
            'SELECT d.dm_id, d.thread_id, d.sender_type, d.sender_id,
                    d.body, d.parent_id, d.created_at,
                    l.full_name     AS sender_name,
                    l.profile_photo AS sender_photo
             FROM direct_messages d
             JOIN lecturers l ON l.lecturer_id = d.sender_id
             WHERE d.dm_id = ?'
        );
        $fetchStmt->execute([$dmId]);
        $row = $fetchStmt->fetch(\PDO::FETCH_ASSOC);

        http_response_code(201);
        echo json_encode([
            'success'      => true,
            'message_id'   => (int)$row['dm_id'],   // frontend uses message_id
            'thread_id'    => (int)$row['thread_id'],
            'sender_type'  => $row['sender_type'],
            'sender_id'    => (int)$row['sender_id'],
            'sender_name'  => $row['sender_name'] ?? 'Lecturer',
            'sender_photo' => $this->photoIfExists($row['sender_photo'] ?? null),
            'body'         => $row['body'],
            'parent_id'    => $row['parent_id'] ? (int)$row['parent_id'] : null,
            'is_deleted'   => false,
            'is_broadcast' => false,
            'reactions'    => [],
            'created_at'   => $row['created_at'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET messages/{class_id}?since={lastId}&limit={n}
    // ─────────────────────────────────────────────────────────────────────────
    public function index(int $classId): void
    {
        $this->authoriseChannel($classId);

        $since = max(0, (int)($_GET['since'] ?? 0));
        $limit = min(self::MAX_LIMIT, max(1, (int)($_GET['limit'] ?? self::DEFAULT_LIMIT)));

        // Fetch messages, join sender info from both lecturers and students tables.
        // Reactions will be fetched separately in PHP for MariaDB compatibility.
        $stmt = $this->db->prepare(
            'SELECT
                m.message_id,
                m.sender_type,
                m.sender_id,
                m.body,
                m.parent_id,
                m.is_broadcast,
                m.is_deleted,
                m.created_at,
                /* sender name + photo */
                CASE m.sender_type
                    WHEN \'lecturer\' THEN l.full_name
                    WHEN \'student\'  THEN s.student_name
                END AS sender_name,
                CASE m.sender_type
                    WHEN \'lecturer\' THEN l.profile_photo
                    WHEN \'student\'  THEN s.profile_photo
                END AS sender_photo,
                CASE m.sender_type
                    WHEN \'lecturer\' THEN l.title
                    ELSE NULL
                END AS sender_title,
                /* reply preview */
                pm.body          AS reply_body,
                CASE pm.sender_type
                    WHEN \'lecturer\' THEN pl.full_name
                    WHEN \'student\'  THEN ps.student_name
                END AS reply_sender
             FROM course_messages m
             /* sender joins */
             LEFT JOIN lecturers l
                ON m.sender_type = \'lecturer\' AND m.sender_id = l.lecturer_id
             LEFT JOIN students s
                ON m.sender_type = \'student\'  AND m.sender_id = s.student_id
             /* parent message for reply preview */
             LEFT JOIN course_messages pm ON pm.message_id = m.parent_id
             LEFT JOIN lecturers pl
                ON pm.sender_type = \'lecturer\' AND pm.sender_id = pl.lecturer_id
             LEFT JOIN students ps
                ON pm.sender_type = \'student\'  AND pm.sender_id = ps.student_id
             WHERE m.class_id = ?
               AND m.message_id > ?
             ORDER BY m.created_at ASC, m.message_id ASC
             LIMIT ?'
        );
        $stmt->execute([$classId, $since, $limit]);

        $messages = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Fetch reactions for this message separately
            $reactStmt = $this->db->prepare(
                'SELECT emoji, reactor_type, reactor_id FROM message_reactions WHERE message_id = ?'
            );
            $reactStmt->execute([$row['message_id']]);
            $reactions = [];
            while ($reaction = $reactStmt->fetch(\PDO::FETCH_ASSOC)) {
                $reactions[] = [
                    'emoji'        => $reaction['emoji'],
                    'reactor_type' => $reaction['reactor_type'],
                    'reactor_id'   => (int)$reaction['reactor_id'],
                ];
            }
            $messages[] = [
                'message_id'    => (int)$row['message_id'],
                'sender_type'   => $row['sender_type'],
                'sender_id'     => (int)$row['sender_id'],
                'sender_name'   => $row['sender_name'] ?? '—',
                'sender_photo'  => $this->photoIfExists($row['sender_photo'] ?? null),
                'sender_title'  => $row['sender_title'],
                'body'          => $row['is_deleted'] ? null : $row['body'],
                'is_broadcast'  => (bool)$row['is_broadcast'],
                'is_deleted'    => (bool)$row['is_deleted'],
                'parent_id'     => $row['parent_id'] ? (int)$row['parent_id'] : null,
                'reply_preview' => $row['reply_body']
                    ? mb_substr($row['reply_body'], 0, 80)
                    : null,
                'reply_sender'  => $row['reply_sender'],
                'reactions'     => $reactions,
                'created_at'    => $row['created_at'],
            ];
        }

        http_response_code(200);
        echo json_encode([
            'messages' => $messages,
            'has_more' => count($messages) === $limit,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST messages/{class_id}
    // ─────────────────────────────────────────────────────────────────────────
    public function send(int $classId): void
    {
        $this->authoriseChannel($classId);
        $this->enforceRateLimit();

        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
        $body     = trim(strip_tags((string)($data['body'] ?? '')));
        $isBcast  = !empty($data['is_broadcast']) ? 1 : 0;
        $parentId = isset($data['parent_id']) && is_numeric($data['parent_id'])
            ? (int)$data['parent_id'] : null;

        if ($body === '') {
            $this->fail(422, 'Message body cannot be empty.');
        }
        if (mb_strlen($body) > self::MAX_BODY_LEN) {
            $this->fail(422, 'Message is too long (max ' . self::MAX_BODY_LEN . ' characters).');
        }

        // Validate parent message belongs to same channel
        if ($parentId !== null) {
            $chk = $this->db->prepare(
                'SELECT 1 FROM course_messages
                 WHERE message_id = ? AND class_id = ? AND is_deleted = 0'
            );
            $chk->execute([$parentId, $classId]);
            if (!$chk->fetch()) $parentId = null; // silently drop invalid parent
        }

        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO course_messages
             (class_id, sender_type, sender_id, body, is_broadcast, parent_id, is_deleted, created_at)
             VALUES (?, \'lecturer\', ?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([$classId, $this->lecturerId, $body, $isBcast, $parentId, $now]);
        $msgId = (int)$this->db->lastInsertId();

        http_response_code(201);
        echo json_encode($this->fetchOne($msgId));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST messages/{class_id}/read
    // ─────────────────────────────────────────────────────────────────────────
    public function markRead(int $classId): void
    {
        $this->authoriseChannel($classId);

        $data    = json_decode(file_get_contents('php://input'), true) ?? [];
        $upTo    = isset($data['up_to_message_id']) ? (int)$data['up_to_message_id'] : 0;

        if ($upTo <= 0) {
            $this->fail(422, 'up_to_message_id must be a positive integer.');
        }

        // Batch-insert read records for all unread messages up to upTo.
        // INSERT IGNORE leverages the UNIQUE KEY uq_read to silently skip
        // duplicates — safe to call repeatedly without double-counting.
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO message_reads (message_id, reader_type, reader_id, read_at)
             SELECT m.message_id, \'lecturer\', ?, NOW()
             FROM   course_messages m
             WHERE  m.class_id   = ?
               AND  m.message_id <= ?
               AND  m.is_deleted = 0
               AND  NOT EXISTS (
                   SELECT 1 FROM message_reads mr
                   WHERE  mr.message_id  = m.message_id
                   AND    mr.reader_type = \'lecturer\'
                   AND    mr.reader_id   = ?
               )'
        );
        $stmt->execute([$this->lecturerId, $classId, $upTo, $this->lecturerId]);

        http_response_code(200);
        echo json_encode(['marked' => $stmt->rowCount()]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST messages/{msg_id}/react
    // ─────────────────────────────────────────────────────────────────────────
    public function react(int $msgId): void
    {
        // Verify message belongs to one of this lecturer's channels
        $this->authoriseMessage($msgId);

        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $emoji = (string)($data['emoji'] ?? '');

        // Basic emoji validation: non-empty, ≤ 8 bytes (covers all Unicode emoji)
        if ($emoji === '' || mb_strlen($emoji, '8bit') > 8) {
            $this->fail(422, 'Invalid emoji.');
        }

        // Toggle: check if reaction already exists
        $chk = $this->db->prepare(
            'SELECT reaction_id FROM message_reactions
             WHERE message_id = ? AND reactor_type = \'lecturer\' AND reactor_id = ? AND emoji = ?'
        );
        $chk->execute([$msgId, $this->lecturerId, $emoji]);
        $existing = $chk->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            // Remove reaction
            $del = $this->db->prepare(
                'DELETE FROM message_reactions WHERE reaction_id = ?'
            );
            $del->execute([(int)$existing['reaction_id']]);
            $action = 'removed';
        } else {
            // Add reaction — INSERT IGNORE handles unlikely race duplicates
            $ins = $this->db->prepare(
                'INSERT IGNORE INTO message_reactions
                 (message_id, reactor_type, reactor_id, emoji, created_at)
                 VALUES (?, \'lecturer\', ?, ?, NOW())'
            );
            $ins->execute([$msgId, $this->lecturerId, $emoji]);
            $action = 'added';
        }

        http_response_code(200);
        echo json_encode([
            'action'     => $action,
            'emoji'      => $emoji,
            'message_id' => $msgId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE messages/{msg_id}
    // ─────────────────────────────────────────────────────────────────────────
    public function delete(int $msgId): void
    {
        // Verify message belongs to one of this lecturer's channels
        $this->authoriseMessage($msgId);

        $stmt = $this->db->prepare(
            'UPDATE course_messages
             SET    is_deleted = 1, deleted_by = \'lecturer\'
             WHERE  message_id = ? AND is_deleted = 0'
        );
        $stmt->execute([$msgId]);

        if ($stmt->rowCount() === 0) {
            // Either already deleted or doesn't exist — idempotent OK
        }

        http_response_code(200);
        echo json_encode(['message' => 'Message removed.', 'message_id' => $msgId]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verify that class_id belongs to this lecturer.
     * 403 if not authorised, 404 if not found.
     */
    private function authoriseChannel(int $classId): void
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM classes
             WHERE class_id = ? AND lecturer_id = ? AND is_archived = 0'
        );
        $stmt->execute([$classId, $this->lecturerId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied or course not found.']);
            exit;
        }
    }

    /**
     * Verify that message_id belongs to a channel owned by this lecturer.
     */
    private function authoriseMessage(int $msgId): void
    {
        $stmt = $this->db->prepare(
            'SELECT 1
             FROM course_messages m
             JOIN classes c ON c.class_id = m.class_id
             WHERE m.message_id = ? AND c.lecturer_id = ?'
        );
        $stmt->execute([$msgId, $this->lecturerId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'Message not found or access denied.']);
            exit;
        }
    }

    /**
     * Simple rate-limit check: max RATE_MAX sends in RATE_WINDOW seconds.
     * Uses a lightweight DB count — appropriate for this load level.
     */
    private function enforceRateLimit(): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS cnt
             FROM course_messages
             WHERE sender_type = \'lecturer\'
               AND sender_id   = ?
               AND created_at  > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$this->lecturerId, self::RATE_WINDOW]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ((int)($row['cnt'] ?? 0) >= self::RATE_MAX) {
            http_response_code(429);
            echo json_encode([
                'error' => 'Too many messages. Please wait a moment before sending again.',
            ]);
            exit;
        }
    }

    /**
     * Fetch a single message row in the same shape as index() returns.
     * Used to return the newly-created message after send().
     */
    private function fetchOne(int $msgId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                m.message_id, m.sender_type, m.sender_id,
                m.body, m.is_broadcast, m.is_deleted,
                m.parent_id, m.created_at,
                l.full_name AS sender_name,
                l.profile_photo AS sender_photo,
                l.title AS sender_title,
                pm.body AS reply_body,
                CASE pm.sender_type
                    WHEN \'lecturer\' THEN pl.full_name
                    WHEN \'student\'  THEN ps.student_name
                END AS reply_sender
             FROM course_messages m
             LEFT JOIN lecturers l  ON m.sender_type = \'lecturer\' AND m.sender_id = l.lecturer_id
             LEFT JOIN course_messages pm ON pm.message_id = m.parent_id
             LEFT JOIN lecturers pl ON pm.sender_type = \'lecturer\' AND pm.sender_id = pl.lecturer_id
             LEFT JOIN students  ps ON pm.sender_type = \'student\'  AND pm.sender_id = ps.student_id
             WHERE m.message_id = ?'
        );
        $stmt->execute([$msgId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return [];

        return [
            'message_id'    => (int)$row['message_id'],
            'sender_type'   => $row['sender_type'],
            'sender_id'     => (int)$row['sender_id'],
            'sender_name'   => $row['sender_name'] ?? '—',
            'sender_photo'  => $this->photoIfExists($row['sender_photo'] ?? null),
            'sender_title'  => $row['sender_title'],
            'body'          => $row['body'],
            'is_broadcast'  => (bool)$row['is_broadcast'],
            'is_deleted'    => (bool)$row['is_deleted'],
            'parent_id'     => $row['parent_id'] ? (int)$row['parent_id'] : null,
            'reply_preview' => $row['reply_body']
                ? mb_substr($row['reply_body'], 0, 80)
                : null,
            'reply_sender'  => $row['reply_sender'],
            'reactions'     => [],
            'created_at'    => $row['created_at'],
        ];
    }

    /**
     * Only return a DB photo path if the corresponding local upload exists.
     * This avoids noisy frontend 404s when DB points to stale filenames.
     */
    private function photoIfExists(?string $path): ?string
    {
        if ($path === null) return null;
        $raw = trim($path);
        if ($raw === '') return null;

        if (preg_match('/^(https?:)?\/\//i', $raw) || str_starts_with($raw, 'data:')) {
            return $raw;
        }

        $normalized = str_replace('\\', '/', ltrim($raw, '/'));
        $pos = strpos($normalized, 'uploads/');
        if ($pos === false) {
            return $raw;
        }

        $relative = substr($normalized, $pos);
        $baseDir = __DIR__ . '/../';
        $absolute = $baseDir . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($absolute)) {
            return $relative;
        }

        // Recovery path: if DB folder is stale, try canonical upload folders by filename.
        $name = basename($relative);
        $candidates = [
            'uploads/students/' . $name,
            'uploads/lecturers/' . $name,
        ];
        foreach ($candidates as $candidate) {
            $candidateAbs = $baseDir . str_replace('/', DIRECTORY_SEPARATOR, $candidate);
            if (is_file($candidateAbs)) {
                return $candidate;
            }
        }

        return null;
    }

    private function fail(int $status, string $message): never
    {
        http_response_code($status);
        echo json_encode(['error' => $message]);
        exit;
    }
}