<?php

namespace App\Plan\Notification;

/** Кому і в якій групі піде сповіщення про одну подію. */
final readonly class Recipient
{
    public function __construct(
        public int $userId,
        public EventGroup $group,
        /** Людина «в темі» суб'єкта, а не лише бачить усе за налаштуванням «Усе». */
        public bool $involved = true,
    ) {
    }

    public function important(): bool
    {
        return $this->group->important();
    }
}
