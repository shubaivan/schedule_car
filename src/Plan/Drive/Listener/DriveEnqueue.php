<?php

namespace App\Plan\Drive\Listener;

use App\Plan\Drive\DriveClientFactory;
use App\Plan\Drive\SyncDriveFiles;
use App\Plan\Event\FileAdded;
use App\Plan\Event\PlanEvent;
use App\Plan\EventStore\PlanEventConsumer;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Share\FileType;
use App\Plan\Subject\SubjectType;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Новий файл на Плані — на Диск одразу, не чекаючи крону: окреме повідомлення
 * SyncDriveFiles у транспорт async (завантаження не гальмує решту споживачів подій).
 * Лише коли події йдуть через воркер (PLAN_EVENTS_TRANSPORT async) і розкладка plan:
 * у sync-режимі стендів файл просто чекає plan:drive-sync (рядок уже pending).
 */
final readonly class DriveEnqueue implements PlanEventConsumer
{
    public function __construct(
        private MessageBusInterface $bus,
        private DriveClientFactory $drives,
        private PlanEventRecorder $events,
    ) {
    }

    public function consume(PlanEvent $event): void
    {
        if (! $event instanceof FileAdded || $event->fileIds === [] || $this->events->isSync() || ! $this->drives->isPlanLayout() || ! $this->drives->isConfigured()) {
            return;
        }

        $type = match ($event->subject->type) {
            SubjectType::Task => FileType::Task,
            SubjectType::Supply => FileType::Supply,
            default => null,
        };

        if ($type !== null) {
            $this->bus->dispatch(new SyncDriveFiles($type->value, array_values(array_map('intval', $event->fileIds))));
        }
    }
}
