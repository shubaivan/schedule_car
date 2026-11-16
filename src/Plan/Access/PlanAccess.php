<?php

namespace App\Plan\Access;

use App\Entity\TelegramUser;
use App\Tasks\Enum\WorkRole;
use App\Warehouse\Service\WarehouseSection;

/**
 * Хто що може в «Плані». Обчислюється з двох ортогональних ролей людини —
 * WorkRole (задачі) і SupplyRole (заявки, склад) — плюс прапорця суперадміна.
 *
 * Правила навмисно повторюють ті, що вже діють у модулях (TaskPolicy,
 * SupplyRole::canManage/canApprovePayment, WarehouseSection::canManage), —
 * матриця лише збирає їх в одне місце для меню, API та voter-а.
 */
final class PlanAccess
{
    public function can(?TelegramUser $user, PlanCapability $capability): bool
    {
        if ($user === null || $user->isArchived() || ! $user->isApproved()) {
            return false;
        }

        $work = $user->getWorkRole();
        $supply = $user->getSupplyRole();

        return match ($capability) {
            PlanCapability::TasksSeeAll => $user->seesAllTasks(),
            PlanCapability::SupplyManage => $supply->canManage(),
            PlanCapability::SupplyApprove => $supply->canApprovePayment(),
            PlanCapability::WarehouseView, PlanCapability::WarehouseEdit => WarehouseSection::canManage($user),
            PlanCapability::AuditView, PlanCapability::StaffJoin => $user->isSuperadmin(),
            PlanCapability::LeadsReceive => in_array($work, [WorkRole::Manager, WorkRole::Director, WorkRole::Admin], true),
            // Контрагенти — комерційна інформація: продажі, бухгалтерія, керівництво й постачання
            // (менеджер заявок працює з постачальниками). Виробництву й робітникам — ні.
            PlanCapability::CounterpartiesView, PlanCapability::CounterpartiesEdit => in_array($work, [WorkRole::Admin, WorkRole::Director, WorkRole::Manager, WorkRole::Accountant], true) || $supply->canManage(),
            // Кредитний ліміт, відстрочка й архів — рішення про гроші: бухгалтер і керівництво.
            PlanCapability::CounterpartiesFinance => in_array($work, [WorkRole::Admin, WorkRole::Director, WorkRole::Accountant], true),
        };
    }

    /**
     * Уся матриця для фронту (`/api/me` → `capabilities`).
     *
     * @return array<string, bool>
     */
    public function matrix(?TelegramUser $user): array
    {
        $out = [];

        foreach (PlanCapability::cases() as $capability) {
            $out[$capability->value] = $this->can($user, $capability);
        }

        return $out;
    }
}
