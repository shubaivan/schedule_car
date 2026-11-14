<?php

namespace App\Plan\Search;

use App\Plan\Event\CommentAdded;
use App\Plan\Event\CommentDeleted;
use App\Plan\Event\CommentEdited;
use App\Plan\Event\FileAdded;
use App\Plan\Event\LeadReceived;
use App\Plan\Event\PlanEvent;
use App\Plan\Event\PlanEventTypes;
use App\Plan\Event\StockMoved;
use App\Plan\EventStore\PlanEventConsumer;
use App\Plan\Subject\SubjectType;
use App\Tasks\Service\TasksSection;

/**
 * Тримає search_document свіжим: будь-яка подія суб'єкта → перечитати цей
 * суб'єкт (і його коментарі/файли) з таблиць модуля. Що саме змінилось, не
 * важливо — проєкція ідемпотентна, тож пропущена чи повторна подія не ламає
 * індекс (а `plan:search:reindex` вирівнює все разом).
 */
final class SearchIndexConsumer implements PlanEventConsumer
{
    public function __construct(private readonly SearchProjector $projector, private readonly TasksSection $plan)
    {
    }

    public function consume(PlanEvent $event): void
    {
        // Стенди заявок (TASKS_ENABLED=0) пошуку «Плану» не мають — і зайвих записів теж.
        if (! $this->plan->enabled()) {
            return;
        }

        // Події, які нічого в документах не змінюють (аудит, службові; лід — без документа), — не витрачаємо на них запити.
        if (PlanEventTypes::isAuditOnly($event) || $event instanceof LeadReceived) {
            return;
        }

        $ref = $event->subject;

        if ($ref->type === SubjectType::Lead || $ref->type === SubjectType::WhCategory || $ref->id < 1) {
            return;
        }

        // Коментар чи файли — лише ці рядки (+ сам суб'єкт), не вся стрічка задачі/заявки.
        // Зі згадками — повністю: згаданого додають в учасники, видимість усіх рядків змінюється.
        $child = $ref->type === SubjectType::Task ? ['comment', 'file'] : ['supply_comment', 'supply_file'];

        match (true) {
            ($ref->type === SubjectType::Task || $ref->type === SubjectType::Supply) &&
                ($event instanceof CommentAdded && $event->files === 0 && $event->mentions === [] || $event instanceof CommentEdited || $event instanceof CommentDeleted)
                => $this->projector->projectChildren($ref, $child[0], [(int) $event->refId]),
            ($ref->type === SubjectType::Task || $ref->type === SubjectType::Supply) && $event instanceof FileAdded && $event->fileIds !== []
                => $this->projector->projectChildren($ref, $child[1], array_map('intval', $event->fileIds)),
            default => $this->projector->projectSubject($ref),
        };

        if ($ref->type->isWarehouse()) {
            $this->projector->projectWarehouseDependents($ref, $event instanceof StockMoved ? array_map('intval', $event->itemIds) : []);
        }
    }
}
