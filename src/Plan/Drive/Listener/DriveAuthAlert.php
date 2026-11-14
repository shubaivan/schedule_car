<?php

namespace App\Plan\Drive\Listener;

use App\Plan\Event\DriveAuthBroken;
use App\Plan\Event\PlanEvent;
use App\Plan\EventStore\PlanEventConsumer;
use App\Plan\Share\Inbox;

/** Диск відкликав доступ → сповіщення всім суперадмінам (подія одна на поломку — див. DriveSync). */
final readonly class DriveAuthAlert implements PlanEventConsumer
{
    public function __construct(private Inbox $inbox)
    {
    }

    public function consume(PlanEvent $event): void
    {
        if ($event instanceof DriveAuthBroken && $event->id() !== null) {
            $this->inbox->deliver((int) $event->id(), $this->inbox->superadmins());
        }
    }
}
