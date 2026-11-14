<?php

namespace App\Plan\Drive\Listener;

use App\Plan\Drive\DriveClientFactory;
use App\Plan\Drive\DriveSync;
use App\Plan\Event\FileAdded;
use App\Plan\Event\PlanEvent;
use App\Plan\EventStore\PlanEventConsumer;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Share\FileType;
use App\Plan\Subject\SubjectType;

/**
 * Новий файл на Плані — одразу на Диск, не чекаючи крону. Лише у воркері
 * (PLAN_EVENTS_TRANSPORT async): у sync-режимі це був би виклик Google посеред
 * HTTP-запиту людини, тож там файл просто чекає plan:drive-sync (рядок уже
 * pending з дефолту колонки). Збій тут нічого не ламає: файл лишається в черзі.
 */
final readonly class DriveEnqueue implements PlanEventConsumer
{
    public function __construct(
        private DriveSync $sync,
        private DriveClientFactory $drives,
        private PlanEventRecorder $events,
    ) {
    }

    public function consume(PlanEvent $event): void
    {
        if (! $event instanceof FileAdded || $event->fileIds === [] || $this->events->isSync() || ! $this->drives->isPlanLayout()) {
            return;
        }

        $type = match ($event->subject->type) {
            SubjectType::Task => FileType::Task,
            SubjectType::Supply => FileType::Supply,
            default => null,
        };
        $client = $this->drives->client();

        if ($type !== null && $client !== null) {
            $this->sync->run($client, count($event->fileIds), only: $type, ids: array_map('intval', $event->fileIds));
        }
    }
}
