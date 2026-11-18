<?php

namespace App\Plan\Zvyazok;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanSections;
use App\Tasks\Service\TasksSection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Хто користується «Звʼязком». Олександр 17.11: «після погодження — одразу на
 * загальний доступ». До того — лише люди з повним доступом (PlanSections); після —
 * PLAN_ZVYAZOK_FOR_ALL=1 у .env.local, без нового деплою коду.
 * Лише «План» (TASKS_ENABLED=1): на стендах заявок модуля немає.
 */
final class ZvyazokAccess
{
    public function __construct(
        private readonly PlanSections $sections,
        private readonly TasksSection $plan,
        #[Autowire('%env(bool:default::PLAN_ZVYAZOK_FOR_ALL)%')]
        private readonly bool $forAll = false,
    ) {
    }

    public function can(?TelegramUser $user): bool
    {
        if ($user === null || ! $this->plan->enabled() || $user->isArchived() || ! $user->isApproved()) {
            return false;
        }

        // Роль без «Звʼязку» (Ролі й доступ) чатів не бачить — ні модуля, ні людей у ньому.
        if (! $user->canUseZvyazok()) {
            return false;
        }

        return $this->forAll || $this->sections->full($user);
    }
}
