<?php

namespace App\Plan\Access;

/**
 * Можливості в «Плані» — одна матриця замість розкиданих перевірок ролей.
 *
 * Атрибут voter-а — `attribute()`: `#[IsGranted('PLAN_WAREHOUSE_VIEW')]`.
 */
enum PlanCapability: string
{
    /** Розділи поза базовими (PlanSections): Постачання, Склад, Контрагенти, Автопарк, Диск, «Звʼязок». */
    case FullAccess = 'plan.full';
    /** Задачі й угоди взагалі (системний адміністратор — ні). */
    case TasksAccess = 'tasks.access';
    /** Сторінка «Ролі й доступ»: адмін, системний адмін, суперадмін. */
    case RolesManage = 'roles.manage';
    case TasksSeeAll = 'tasks.seeAll';
    case SupplyManage = 'supply.manage';
    case SupplyApprove = 'supply.approve';
    case WarehouseView = 'warehouse.view';
    case WarehouseEdit = 'warehouse.edit';
    case AuditView = 'audit.view';
    /** Заявки на приєднання (вхід через Telegram) — розглядає суперадмін (і системний адміністратор). */
    case StaffJoin = 'staff.join';
    case LeadsReceive = 'leads.receive';
    /** Контрагенти: бачити картки й заводити нові / правити реквізити й контакти. */
    case CounterpartiesView = 'counterparties.view';
    case CounterpartiesEdit = 'counterparties.edit';
    /** Гроші контрагента: умови оплати, відстрочка, кредитний ліміт; архів. */
    case CounterpartiesFinance = 'counterparties.finance';

    /** `warehouse.view` → `PLAN_WAREHOUSE_VIEW`. */
    public function attribute(): string
    {
        return 'PLAN_' . strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', str_replace('.', '_', $this->value)));
    }

    public static function fromAttribute(string $attribute): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->attribute() === $attribute) {
                return $case;
            }
        }

        return null;
    }
}
