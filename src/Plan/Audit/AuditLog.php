<?php

namespace App\Plan\Audit;

use App\Plan\Event\ApprovalRequested;
use App\Plan\Event\StatusChanged;
use App\Plan\Event\SubjectClosed;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Generator;

/**
 * Читання журналу plan_event для сторінки аудиту: фільтри, сторінки за курсором
 * (id події), одна подія і її сусіди, вивантаження для CSV.
 *
 * Лише SELECT — журнал незмінний; видаляє рядки тільки plan:audit:prune.
 * Суперадмін бачить УСЕ: тут немає фільтра видимості суб'єктів.
 *
 * @phpstan-type AuditRow array{id: int, type: string, subject_type: string, subject_id: int, actor_id: ?int, occurred_at: string, payload: string, ip: ?string, channel: string}
 */
final class AuditLog
{
    private const string COLUMNS = 'e.id, e.type, e.subject_type, e.subject_id, e.actor_id, e.occurred_at, e.payload, e.ip, e.channel';
    private const int COUNT_CAP = 10000;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Сторінка журналу: найновіші зверху; before — старіші за подію, after — новіші.
     *
     * @return array{items: list<AuditRow>, older: ?int, newer: ?int}
     */
    public function page(AuditFilter $filter): array
    {
        [$where, $params, $types] = $this->where($filter);
        $limit = $filter->limit;

        if ($filter->after !== null) {
            $where[] = 'e.id > :after';
            $params['after'] = $filter->after;
            $rows = $this->rows($where, $params, $types, 'ASC', $limit + 1);
            $more = count($rows) > $limit;
            $rows = array_reverse(array_slice($rows, 0, $limit));

            return [
                'items' => $rows,
                'older' => $rows !== [] ? $rows[count($rows) - 1]['id'] : null,
                'newer' => $more && $rows !== [] ? $rows[0]['id'] : null,
            ];
        }

        if ($filter->before !== null) {
            $where[] = 'e.id < :before';
            $params['before'] = $filter->before;
        }

        $rows = $this->rows($where, $params, $types, 'DESC', $limit + 1);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return [
            'items' => $rows,
            'older' => $more && $rows !== [] ? $rows[count($rows) - 1]['id'] : null,
            'newer' => $filter->before !== null && $rows !== [] ? $rows[0]['id'] : null,
        ];
    }

    /** Скільки подій під фільтром — до COUNT_CAP (далі «10 000+»): лічильник не має гальмувати сторінку. */
    public function count(AuditFilter $filter): int
    {
        [$where, $params, $types] = $this->where($filter->withoutCursor());

        return (int) $this->db->fetchOne(
            sprintf('SELECT COUNT(*) FROM (SELECT 1 FROM plan_event e LEFT JOIN telegram_user u ON u.id = e.actor_id WHERE %s LIMIT %d) c', implode(' AND ', $where), self::COUNT_CAP + 1),
            $params,
            $types,
        );
    }

    public static function countCap(): int
    {
        return self::COUNT_CAP;
    }

    /** @return AuditRow|null */
    public function find(int $id): ?array
    {
        $rows = $this->rows(['e.id = :id'], ['id' => $id], [], 'DESC', 1);

        return $rows[0] ?? null;
    }

    /**
     * Події того самого суб'єкта довкола цієї: що було перед і після.
     *
     * @param AuditRow $row
     *
     * @return list<AuditRow>
     */
    public function around(array $row, int $each = 5): array
    {
        $base = ['e.subject_type = :st', 'e.subject_id = :sid'];
        $params = ['st' => $row['subject_type'], 'sid' => $row['subject_id'], 'id' => $row['id']];

        $before = $this->rows([...$base, 'e.id < :id'], $params, [], 'DESC', $each);
        $after = $this->rows([...$base, 'e.id > :id'], $params, [], 'ASC', $each);

        return [...array_reverse($after), $row, ...$before];
    }

    /**
     * Усі рядки під фільтром для CSV — порціями, щоб не тримати в пам'яті 50k.
     *
     * @return Generator<int, AuditRow>
     */
    public function export(AuditFilter $filter, int $max): Generator
    {
        $filter = $filter->withoutCursor();
        $cursor = null;
        $left = $max;

        while ($left > 0) {
            [$where, $params, $types] = $this->where($filter);

            if ($cursor !== null) {
                $where[] = 'e.id < :before';
                $params['before'] = $cursor;
            }

            $rows = $this->rows($where, $params, $types, 'DESC', min(1000, $left));

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                yield $row;
            }

            $left -= count($rows);
            $cursor = $rows[count($rows) - 1]['id'];
        }
    }

    /**
     * Хто взагалі є в журналі — для фільтра «Хто».
     *
     * @return list<array{id: int, name: string}>
     */
    public function actors(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.id, u.first_name, u.last_name, u.username, u.phone_number FROM telegram_user u
              WHERE EXISTS (SELECT 1 FROM plan_event e WHERE e.actor_id = u.id)
              ORDER BY u.first_name, u.last_name, u.id',
        );

        return array_map(static fn (array $r) => ['id' => (int) $r['id'], 'name' => self::name($r)], $rows);
    }

    /**
     * Імена людей одним запитом.
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id) => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach ($this->db->fetchAllAssociative(
            'SELECT id, first_name, last_name, username, phone_number FROM telegram_user WHERE id IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER],
        ) as $r) {
            $out[(int) $r['id']] = self::name($r);
        }

        return $out;
    }

    /** @param array<string, mixed> $r */
    private static function name(array $r): string
    {
        $name = trim(sprintf('%s %s', $r['first_name'] ?? '', $r['last_name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        return $r['username'] ? '@' . $r['username'] : (string) ($r['phone_number'] ?? ('#' . $r['id']));
    }

    /**
     * @param list<string> $where
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|int|string> $types
     *
     * @return list<AuditRow>
     */
    private function rows(array $where, array $params, array $types, string $order, int $limit): array
    {
        $rows = $this->db->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM plan_event e LEFT JOIN telegram_user u ON u.id = e.actor_id WHERE %s ORDER BY e.id %s LIMIT %d',
                self::COLUMNS,
                implode(' AND ', $where),
                $order === 'ASC' ? 'ASC' : 'DESC',
                $limit,
            ),
            $params,
            $types,
        );

        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'type' => (string) $r['type'],
            'subject_type' => (string) $r['subject_type'],
            'subject_id' => (int) $r['subject_id'],
            'actor_id' => $r['actor_id'] !== null ? (int) $r['actor_id'] : null,
            'occurred_at' => (string) $r['occurred_at'],
            'payload' => (string) $r['payload'],
            'ip' => $r['ip'] !== null ? (string) $r['ip'] : null,
            'channel' => (string) $r['channel'],
        ], $rows);
    }

    /**
     * @return array{0: list<string>, 1: array<string, mixed>, 2: array<string, ArrayParameterType|int|string>}
     */
    private function where(AuditFilter $filter): array
    {
        $where = ['TRUE'];
        $params = [];
        $types = [];

        if ($filter->actor !== null) {
            $where[] = 'e.actor_id = :actor';
            $params['actor'] = $filter->actor;
        }

        if ($filter->types !== []) {
            $typeClause = 'e.type IN (:types)';

            if ($filter->withStatusComments) {
                // Статус із коментарем — це теж коментар (див. AuditFilter::fromQuery).
                $typeClause = "($typeClause OR (e.type IN (:statusTypes) AND e.payload->>'comment' IS NOT NULL))";
                $params['statusTypes'] = [StatusChanged::TYPE, ApprovalRequested::TYPE, SubjectClosed::TYPE];
                $types['statusTypes'] = ArrayParameterType::STRING;
            }

            $where[] = $typeClause;
            $params['types'] = $filter->types;
            $types['types'] = ArrayParameterType::STRING;
        }

        if ($filter->subject !== null) {
            $where[] = 'e.subject_type = :subjectType AND e.subject_id = :subjectId';
            $params['subjectType'] = $filter->subject->type->value;
            $params['subjectId'] = $filter->subject->id;
        } elseif ($filter->kind !== null) {
            $where[] = 'e.subject_type = :subjectType';
            $params['subjectType'] = $filter->kind->value;
        }

        if ($filter->from !== null) {
            $where[] = 'e.occurred_at >= :from';
            $params['from'] = $filter->from->format('Y-m-d H:i:s');
        }

        if ($filter->to !== null) {
            $where[] = 'e.occurred_at < :to';
            $params['to'] = $filter->to->format('Y-m-d H:i:s');
        }

        if ($filter->ip !== null) {
            $where[] = 'e.ip LIKE :ip';
            $params['ip'] = self::like($filter->ip, prefixOnly: true);
        }

        if ($filter->q !== null) {
            // Текст — у payload (назви, уривки коментарів, імена файлів, причини), імені того, хто діяв, і IP.
            // Лише значення payload, не ключі: «comment» не має знаходити кожну зміну статусу.
            $where[] = "(EXISTS (SELECT 1 FROM jsonb_each_text(e.payload) p WHERE p.value ILIKE :q)
                         OR CONCAT_WS(' ', u.first_name, u.last_name, u.username) ILIKE :q OR e.ip LIKE :q)";
            $params['q'] = self::like($filter->q);
        }

        return [$where, $params, $types];
    }

    private static function like(string $value, bool $prefixOnly = false): string
    {
        $escaped = addcslashes($value, '\\%_');

        return ($prefixOnly ? '' : '%') . $escaped . '%';
    }
}
