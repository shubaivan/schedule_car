<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/**
 * Картку співробітника змінено (суб'єкт — user). Лише аудит: хто кому змінив
 * роль, пароль, email чи телефон, кого переніс у кошик чи повернув.
 *
 * action: create | update | trash | restore; fields — назви змінених полів
 * (значень пароля, email і телефону тут немає ніколи); roleFrom/roleTo — коли
 * змінилась роль.
 */
final class StaffChanged extends PlanEvent
{
    public const string TYPE = 'staff_changed';

    /** @param list<string> $fields */
    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly string $action = 'update',
        public readonly array $fields = [],
        public readonly ?string $roleFrom = null,
        public readonly ?string $roleTo = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
