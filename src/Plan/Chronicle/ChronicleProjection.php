<?php

namespace App\Plan\Chronicle;

use App\Plan\Event\PlanEvent;
use App\Plan\Event\PlanEventTypes;
use App\Plan\EventStore\PlanEventConsumer;
use App\Tasks\Service\TasksSection;
use Doctrine\DBAL\Connection;

/**
 * Веде plan_subject_activity — останню «новину» кожного суб'єкта (задачі,
 * заявки, позиції/руху складу). «Остання» — за часом події, потім за id (історія з
 * plan:events:backfill має більші id, але старіший час). З неї Хроніка будує рядки одним індексним
 * проходом, не сканаючи весь журнал. Перегляди, входи, правки й видалення
 * коментарів новиною не є і рядок угору не піднімають.
 */
final class ChronicleProjection implements PlanEventConsumer
{
    public function __construct(private readonly Connection $db, private readonly TasksSection $plan)
    {
    }

    public function consume(PlanEvent $event): void
    {
        $id = $event->id();

        // Хроніка — частина «Плану»; стенди заявок (TASKS_ENABLED=0) її не ведуть.
        if ($id === null || ! $this->plan->enabled() || PlanEventTypes::isAuditOnly($event) || ! PlanChronicle::isNews($event::type()) || ! in_array($event->subject->type->value, PlanChronicle::subjectTypes(), true)) {
            return;
        }

        $this->db->executeStatement(
            'INSERT INTO plan_subject_activity (subject_type, subject_id, last_event_id, last_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (subject_type, subject_id) DO UPDATE
                SET last_event_id = EXCLUDED.last_event_id, last_at = EXCLUDED.last_at
              WHERE (plan_subject_activity.last_at, plan_subject_activity.last_event_id) < (EXCLUDED.last_at, EXCLUDED.last_event_id)',
            [$event->subject->type->value, $event->subject->id, $id, $event->at->format('Y-m-d H:i:s')],
        );

        // Хто щось зробив із заявкою / складом — той її бачив: «+N» у нього рахується від
        // цієї події (як у задачах, де свій коментар позначає задачу прочитаною).
        if ($event->actorId !== null && $event->subject->type->value !== 'task') {
            $this->db->executeStatement(
                'INSERT INTO plan_read (user_id, subject_type, subject_id, last_event_id, read_at)
                 SELECT u.id, ?, ?, ?, LOCALTIMESTAMP(0) FROM telegram_user u WHERE u.id = ?
                 ON CONFLICT (user_id, subject_type, subject_id) DO UPDATE
                    SET last_event_id = GREATEST(plan_read.last_event_id, EXCLUDED.last_event_id), read_at = EXCLUDED.read_at',
                [$event->subject->type->value, $event->subject->id, $id, $event->actorId],
            );
        }
    }
}
