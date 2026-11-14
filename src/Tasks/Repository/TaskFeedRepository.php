<?php

namespace App\Tasks\Repository;

use App\Entity\TelegramUser;
use App\Tasks\Entity\Task;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * «Хроніка» — стрічка подій як вхідні в ПланФіксі: одна строка на задачу
 * з останньою подією, «+N» нових подій від інших людей, ★ і «Непрочитані».
 *
 * Подія = рядок task_message (коментар, файл, системна подія), прочитане —
 * task_read.last_message_id. Сторінки — за курсором (last_activity_at, id),
 * тож прокрутка не зсувається, коли зверху зʼявляються нові події.
 */
class TaskFeedRepository
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @return list<array<string, mixed>> рядки задач з останньою подією (ліміт + 1, щоб знати, чи є далі)
     */
    public function chronicle(TelegramUser $viewer, bool $unreadOnly, bool $favoritesOnly, ?string $cursor, int $limit): array
    {
        [$visible, $params] = TaskVisibility::sql($viewer);
        $params['me'] = (int) $viewer->getId();
        $where = [$visible];

        if ($cursor !== null && preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\|(\d+)$/', $cursor, $m) === 1) {
            $where[] = '(t.last_activity_at, t.id) < (CAST(:cursorAt AS timestamp), :cursorId)';
            $params['cursorAt'] = $m[1];
            $params['cursorId'] = (int) $m[2];
        }

        if ($unreadOnly) {
            $where[] = 'EXISTS (SELECT 1 FROM task_message u WHERE u.task_id = t.id AND u.id > COALESCE(r.last_message_id, 0)
                AND u.deleted_at IS NULL AND u.author_id IS DISTINCT FROM :me)';
        }

        if ($favoritesOnly) {
            $where[] = 'f.id IS NOT NULL';
        }

        return $this->db->fetchAllAssociative(
            'SELECT t.id, t.title, t.status, t.client, t.creator_id, t.last_activity_at,
                    f.id IS NOT NULL AS favorite,
                    COALESCE(r.last_message_id, 0) AS read_upto,
                    lm.id AS msg_id, lm.kind AS msg_kind, lm.event_type AS msg_event, lm.text AS msg_text,
                    lm.author_id AS msg_author, lm.created_at AS msg_at,
                    (SELECT COUNT(*) FROM task_message u WHERE u.task_id = t.id AND u.id > COALESCE(r.last_message_id, 0)
                        AND u.deleted_at IS NULL AND u.author_id IS DISTINCT FROM :me) AS unread
               FROM task t
               LEFT JOIN task_read r ON r.task_id = t.id AND r.user_id = :me
               LEFT JOIN task_favorite f ON f.task_id = t.id AND f.user_id = :me
               LEFT JOIN LATERAL (
                    SELECT m.id, m.kind, m.event_type, m.text, m.author_id, m.created_at FROM task_message m
                     WHERE m.task_id = t.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1
               ) lm ON TRUE
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY t.last_activity_at DESC, t.id DESC
              LIMIT ' . ($limit + 1),
            $params,
        );
    }

    /** @return array{tasks: int, events: int} скільки задач мають нове і скільки там подій */
    public function unreadCounts(TelegramUser $viewer): array
    {
        [$visible, $params] = TaskVisibility::sql($viewer);
        $params['me'] = (int) $viewer->getId();

        $row = $this->db->fetchAssociative(
            'SELECT COUNT(*) AS tasks, COALESCE(SUM(n), 0) AS events FROM (
                SELECT (SELECT COUNT(*) FROM task_message u WHERE u.task_id = t.id AND u.id > COALESCE(r.last_message_id, 0)
                           AND u.deleted_at IS NULL AND u.author_id IS DISTINCT FROM :me) AS n
                  FROM task t
                  LEFT JOIN task_read r ON r.task_id = t.id AND r.user_id = :me
                 WHERE ' . $visible . '
             ) x WHERE n > 0',
            $params,
        ) ?: [];

        return ['tasks' => (int) ($row['tasks'] ?? 0), 'events' => (int) ($row['events'] ?? 0)];
    }

    /** «Прочитати все»: кожна видима задача — прочитана до останнього повідомлення. */
    public function readAll(TelegramUser $viewer): int
    {
        [$visible, $params] = TaskVisibility::sql($viewer);
        $params['me'] = (int) $viewer->getId();
        $params['now'] = Task::now()->format('Y-m-d H:i:s');

        return (int) $this->db->executeStatement(
            "INSERT INTO task_read (id, task_id, user_id, last_message_id, read_at)
             SELECT nextval('task_read_id_seq'), t.id, :me, x.last_id, CAST(:now AS timestamp)
               FROM task t
               JOIN LATERAL (SELECT MAX(m.id) AS last_id FROM task_message m WHERE m.task_id = t.id) x ON x.last_id IS NOT NULL
              WHERE {$visible}
             ON CONFLICT (task_id, user_id) DO UPDATE
                SET last_message_id = GREATEST(task_read.last_message_id, EXCLUDED.last_message_id),
                    read_at = EXCLUDED.read_at
              WHERE task_read.last_message_id < EXCLUDED.last_message_id",
            $params,
        );
    }

    /**
     * Хто до якого повідомлення дочитав задачу — з цього фронт малює «хто прочитав»
     * під кожним повідомленням (окремий рядок на повідомлення не потрібен).
     *
     * @return list<array{userId: int, lastMessageId: int}>
     */
    public function reads(Task $task): array
    {
        return array_map(
            static fn (array $r) => ['userId' => (int) $r['user_id'], 'lastMessageId' => (int) $r['last_message_id']],
            $this->db->fetchAllAssociative(
                'SELECT user_id, last_message_id FROM task_read WHERE task_id = ? AND last_message_id > 0 ORDER BY read_at',
                [$task->getId()],
            ),
        );
    }

    /**
     * Файли останніх подій Хроніки — назва й тип, без шляхів.
     *
     * @param int[] $messageIds
     *
     * @return array<int, list<array{name: string, kind: string}>>
     */
    public function filesOf(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $out = [];

        foreach ($this->db->fetchAllAssociative(
            'SELECT message_id, original_name, kind FROM task_attachment WHERE message_id IN (?) ORDER BY id',
            [$messageIds],
            [ArrayParameterType::INTEGER],
        ) as $row) {
            $out[(int) $row['message_id']][] = ['name' => (string) $row['original_name'], 'kind' => (string) $row['kind']];
        }

        return $out;
    }

    /** @param int[] $messageIds @return int[] які з них людина позначила ★ */
    public function starredOf(TelegramUser $viewer, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        return array_map('intval', $this->db->fetchFirstColumn(
            'SELECT message_id FROM task_message_star WHERE user_id = ? AND message_id IN (?)',
            [$viewer->getId(), $messageIds],
            [\Doctrine\DBAL\ParameterType::INTEGER, ArrayParameterType::INTEGER],
        ));
    }

    public function isFavorite(TelegramUser $viewer, Task $task): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1 FROM task_favorite WHERE user_id = ? AND task_id = ?',
            [$viewer->getId(), $task->getId()],
        );
    }

    public static function cursor(string $lastActivityAt, int $id): string
    {
        return (new DateTime($lastActivityAt, new DateTimeZone('Europe/Kyiv')))->format('Y-m-d H:i:s') . '|' . $id;
    }
}
