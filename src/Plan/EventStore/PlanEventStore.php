<?php

namespace App\Plan\EventStore;

use App\Plan\Event\PlanEvent;
use App\Plan\Event\PlanEventTypes;
use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/** Читання журналу plan_event назад у типізовані події. */
final class PlanEventStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function load(int $id): ?PlanEvent
    {
        $row = $this->db->fetchAssociative('SELECT * FROM plan_event WHERE id = ?', [$id]);

        return $row !== false ? self::hydrate($row) : null;
    }

    /**
     * Події суб'єкта від старих до нових (стрічка, тести).
     *
     * @return list<PlanEvent>
     */
    public function forSubject(SubjectRef $subject, int $afterId = 0, int $limit = 500): array
    {
        return array_map(
            self::hydrate(...),
            $this->db->fetchAllAssociative(
                'SELECT * FROM plan_event WHERE subject_type = ? AND subject_id = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limit),
                [$subject->type->value, $subject->id, $afterId],
            ),
        );
    }

    public function lastId(): int
    {
        return (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM plan_event');
    }

    /** @param array<string, mixed> $row */
    public static function hydrate(array $row): PlanEvent
    {
        $class = PlanEventTypes::classOf((string) $row['type']);
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

        return $class::restore(
            (int) $row['id'],
            SubjectRef::of((string) $row['subject_type'], (int) $row['subject_id']),
            $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
            new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('Europe/Kyiv')),
            is_array($payload) ? $payload : [],
            $row['channel'] !== null ? (string) $row['channel'] : null,
            $row['ip'] !== null ? (string) $row['ip'] : null,
        );
    }
}
