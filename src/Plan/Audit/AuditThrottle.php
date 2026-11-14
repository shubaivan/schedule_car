<?php

namespace App\Plan\Audit;

use App\Plan\Subject\SubjectRef;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * «Цю саму дію ця людина вже робила щойно» — щоб журнал аудиту не тонув
 * у повторах: кожне відкриття картки складу чи PDF у переглядачі (який ще й
 * докачує файл шматками) інакше дає рядок.
 *
 * Правило: одна подія на людину × суб'єкт × тип (× уточнення з payload,
 * напр. той самий файл) за WINDOW_MINUTES. Аноніми (actor = null) не
 * зводяться — там нема кого зводити.
 *
 * Журнал складу (wh_activity, Twig) пишеться як і раніше — кожен перегляд;
 * зводиться лише plan_event.
 */
final class AuditThrottle
{
    public const int WINDOW_MINUTES = 10;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string, scalar> $match ключі payload, що мають збігтися (напр. ['fileId' => 12])
     */
    public function seenRecently(string $type, ?int $actorId, SubjectRef $subject, array $match = [], ?DateTimeImmutable $now = null): bool
    {
        if ($actorId === null) {
            return false;
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
        $since = $now->sub(new DateInterval('PT' . self::WINDOW_MINUTES . 'M'));

        $sql = 'SELECT 1 FROM plan_event WHERE actor_id = :actor AND type = :type AND subject_type = :st AND subject_id = :sid AND occurred_at >= :since';
        $params = ['actor' => $actorId, 'type' => $type, 'st' => $subject->type->value, 'sid' => $subject->id, 'since' => $since->format('Y-m-d H:i:s')];
        $i = 0;

        foreach ($match as $key => $value) {
            $sql .= sprintf(' AND payload->>:k%1$d = :v%1$d', $i);
            $params['k' . $i] = $key;
            $params['v' . $i] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            ++$i;
        }

        return $this->db->fetchOne($sql . ' LIMIT 1', $params) !== false;
    }
}
