<?php

namespace App\Plan\EventStore;

/**
 * Повідомлення Messenger «розіслати подію споживачам». Несе лише id рядка
 * plan_event — сама подія читається з журналу, тож у черзі немає копії даних.
 */
final readonly class FanOut
{
    public function __construct(public int $eventId)
    {
    }
}
