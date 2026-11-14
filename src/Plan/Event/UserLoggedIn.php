<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/** Успішний вхід у CRM (суб'єкт — user). method: bot | password; refId — crm_login.id. */
final class UserLoggedIn extends PlanEvent
{
    public const string TYPE = 'user_logged_in';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly string $method = '',
        public readonly ?string $userAgent = null,
        public readonly ?int $refId = null,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
