<?php

namespace App\Plan\Access;

use App\Entity\TelegramUser;
use App\Tasks\Service\TasksSection;

/**
 * Які розділи «Плану» людина бачить (Олександр, 17.11.2026).
 *
 * Базові — для всіх: Огляд, Хроніка, Задачі, Пошук, Сповіщення, Люди (і Профіль).
 * Решта — Постачання, Склад, Контрагенти, Автопарк, Google Диск, «Звʼязок» (до
 * погодження) — лише з повним доступом: суперадмін або прапорець plan_full_access
 * (картка співробітника — змінює суперадмін; консоль `plan:full-access`).
 *
 * Лише в «Плані» (TASKS_ENABLED=1): стенди заявок не зачіпаємо — там повний доступ у всіх.
 * Спирається на це: PlanAccess (можливості розділів), SupplyVisibility, Хроніка,
 * PlanHiddenSectionGate (URL прихованих розділів → 403).
 */
final class PlanSections
{
    public function __construct(private readonly TasksSection $plan)
    {
    }

    public function full(?TelegramUser $user): bool
    {
        if ($user === null) {
            return false;
        }

        return ! $this->plan->enabled() || $user->isSuperadmin() || $user->hasPlanFullAccess() ||
            // Або всій ролі — клітинку «Приховані розділи» на «Ролі й доступ» змінює лише суперадмін.
            RoleGrants::allows($user->getWorkRole(), RolePermission::SectionsFull);
    }
}
