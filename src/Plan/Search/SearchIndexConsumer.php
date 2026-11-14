<?php

namespace App\Plan\Search;

use App\Plan\Event\FileDownloaded;
use App\Plan\Event\LeadReceived;
use App\Plan\Event\LoginFailed;
use App\Plan\Event\PlanEvent;
use App\Plan\Event\ShareCreated;
use App\Plan\Event\StockMoved;
use App\Plan\Event\SubjectViewed;
use App\Plan\Event\UserLoggedIn;
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
    /** Події, які нічого в документах не змінюють, — не витрачаємо на них запити. */
    private const IGNORED = [SubjectViewed::class, UserLoggedIn::class, LoginFailed::class, FileDownloaded::class, ShareCreated::class, LeadReceived::class];

    public function __construct(private readonly SearchProjector $projector, private readonly TasksSection $plan)
    {
    }

    public function consume(PlanEvent $event): void
    {
        // Стенди заявок (TASKS_ENABLED=0) пошуку «Плану» не мають — і зайвих записів теж.
        if (! $this->plan->enabled()) {
            return;
        }

        foreach (self::IGNORED as $class) {
            if ($event instanceof $class) {
                return;
            }
        }

        $ref = $event->subject;

        if ($ref->type === SubjectType::Lead || $ref->type === SubjectType::WhCategory || $ref->id < 1) {
            return;
        }

        $this->projector->projectSubject($ref);

        if ($ref->type->isWarehouse()) {
            $this->projector->projectWarehouseDependents($ref, $event instanceof StockMoved ? array_map('intval', $event->itemIds) : []);
        }
    }
}
