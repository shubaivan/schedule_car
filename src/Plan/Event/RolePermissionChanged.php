<?php

namespace App\Plan\Event;

use App\Plan\Subject\SubjectRef;
use DateTimeImmutable;

/**
 * Право ролі змінено на сторінці «Ролі й доступ» (суб'єкт — людина, що змінила). Лише аудит.
 * role — WorkRole, permission — RolePermission, allowed — нове значення, before — старе.
 */
final class RolePermissionChanged extends PlanEvent
{
    public const string TYPE = 'role_permission_changed';

    public function __construct(
        SubjectRef $subject,
        ?int $actorId,
        public readonly string $role = '',
        public readonly string $permission = '',
        public readonly bool $allowed = false,
        public readonly bool $before = false,
        ?DateTimeImmutable $at = null,
    ) {
        parent::__construct($subject, $actorId, $at);
    }
}
