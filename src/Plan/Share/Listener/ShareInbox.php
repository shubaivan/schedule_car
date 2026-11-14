<?php

namespace App\Plan\Share\Listener;

use App\Plan\Event\PlanEvent;
use App\Plan\Event\ShareCreated;
use App\Plan\EventStore\PlanEventConsumer;
use App\Plan\Share\Inbox;

/** «Поділитися з людьми» → рядок у центрі сповіщень кожного отримувача (крім автора). */
final readonly class ShareInbox implements PlanEventConsumer
{
    public function __construct(private Inbox $inbox)
    {
    }

    public function consume(PlanEvent $event): void
    {
        if (! $event instanceof ShareCreated || $event->userIds === [] || $event->id() === null) {
            return;
        }

        $this->inbox->deliver((int) $event->id(), array_values(array_filter(
            array_map('intval', $event->userIds),
            static fn (int $id) => $id !== $event->actorId,
        )));
    }
}
