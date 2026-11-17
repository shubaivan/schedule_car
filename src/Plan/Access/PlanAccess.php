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
 * SupplyRole::canManage/canApprovePayment, WarehouseSection::canView/canManage), —
 * матриця лише збирає їх в одне місце для меню, API та voter-а.
 */
final class PlanAccess
{
    /** null — без розподілу розділів (юніт-тести, стенди заявок): повний доступ у всіх. */
    public function __construct(private readonly ?PlanSections $sections = null)
    {
    }

    /** Прихований розділ (PlanSections): без повного доступу — ні бачити, ні змінювати. */
    private const array FULL_ONLY = [
        PlanCapability::SupplyManage,
        PlanCapability::SupplyApprove,
        PlanCapability::WarehouseView,
        PlanCapability::WarehouseEdit,
        PlanCapability::CounterpartiesView,
        PlanCapability::CounterpartiesEdit,
        PlanCapability::CounterpartiesFinance,
        PlanCapability::FullAccess,
    ];

    public function can(?TelegramUser $user, PlanCapability $capability): bool
    {
        if ($user === null || $user->isArchived() || ! $user->isApproved()) {
            return false;
        }

        if (in_array($capability, self::FULL_ONLY, true) && $this->sections !== null && ! $this->sections->full($user)) {
            return false;
        }

        $work = $user->getWorkRole();
        $supply = $user->getSupplyRole();

        return match ($capability) {
            PlanCapability::FullAccess => true,
            PlanCapability::TasksAccess => $user->canSeeTasks(),
            PlanCapability::RolesManage => $user->managesRoles(),
            PlanCapability::TasksSeeAll => $user->seesAllTasks(),
            PlanCapability::SupplyManage => $supply->canManage(),
            PlanCapability::SupplyApprove => $supply->canApprovePayment(),
            // Склад на читання — ще й продажам і бухгалтерії; записують лише ті, хто його веде.
            PlanCapability::WarehouseView => WarehouseSection::canView($user),
            PlanCapability::WarehouseEdit => WarehouseSection::canManage($user),
            // Журнал аудиту — лише суперадмін: у ньому уривки коментарів задач.
            PlanCapability::AuditView => $user->isSuperadmin(),
            // Заявки на приєднання — налаштування доступу: суперадмін і системний адміністратор.
            PlanCapability::StaffJoin => $user->isSuperadmin() || $work === WorkRole::SysAdmin,
            // Далі — права ролі (RolePermission: значення з коду + сторінка «Ролі й доступ»).
            PlanCapability::LeadsReceive => RoleGrants::allows($work, RolePermission::LeadsReceive),
            // Контрагенти — комерційна інформація: продажі, бухгалтерія, керівництво й постачання
            // (менеджер заявок працює з постачальниками). Виробництву й робітникам — ні.
            PlanCapability::CounterpartiesView => RoleGrants::allows($work, RolePermission::CounterpartiesView) || $supply->canManage(),
            PlanCapability::CounterpartiesEdit => RoleGrants::allows($work, RolePermission::CounterpartiesEdit) || $supply->canManage(),
            // Кредитний ліміт, відстрочка й архів — рішення про гроші: бухгалтер і керівництво.
            PlanCapability::CounterpartiesFinance => RoleGrants::allows($work, RolePermission::CounterpartiesFinance),
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
