<?php

namespace App\Plan\Share;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Рядки центру сповіщень (таблиця notification з S0) для подій S5, у яких
 * адресати відомі точно: «поділились файлом» → вибрані люди; «Диск відкликав
 * доступ» → суперадміни. ON CONFLICT — якщо NotificationFanout (S1) теж
 * адресує цю подію тим самим людям, дубля не буде (uniq user_id+event_id).
 * Доставку в Telegram / Web Push робить S1 за цими ж рядками.
 */
final readonly class Inbox
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @param list<int> $userIds
     *
     * @return int скільки людей отримали рядок
     */
    public function deliver(int $eventId, array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return (int) $this->db->executeStatement(
            "INSERT INTO notification (id, user_id, event_id, created_at)
             SELECT nextval('notification_id_seq'), u.id, :event, :now
             FROM telegram_user u
             WHERE u.id IN (:users) AND u.archived_at IS NULL
             ON CONFLICT (user_id, event_id) DO NOTHING",
            ['event' => $eventId, 'now' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv')))->format('Y-m-d H:i:s'), 'users' => array_values(array_unique($userIds))],
            ['users' => ArrayParameterType::INTEGER],
        );
    }

    /** @return list<int> */
    public function superadmins(): array
    {
        return array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM telegram_user WHERE is_superadmin AND archived_at IS NULL'));
    }
}
