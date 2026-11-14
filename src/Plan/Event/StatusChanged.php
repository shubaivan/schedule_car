<?php

namespace App\Plan\Event;

/** Статус змінено (не на погодження й не закриття). */
final class StatusChanged extends StatusEvent
{
    public const string TYPE = 'status_changed';
}
