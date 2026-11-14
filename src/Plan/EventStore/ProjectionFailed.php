<?php

namespace App\Plan\EventStore;

use RuntimeException;

/** Ідемпотентні споживачі не впорались — Messenger повторить FanOut (решта споживачів пропуститься за позначками). */
final class ProjectionFailed extends RuntimeException
{
    /** @param list<string> $consumers */
    public function __construct(public readonly int $eventId, public readonly array $consumers)
    {
        parent::__construct(sprintf('plan event %d: projection failed (%s) — will be retried', $eventId, implode(', ', $consumers)));
    }
}
