<?php

namespace App\Plan\Access;

/**
 * Можливості в «Плані» — одна матриця замість розкиданих перевірок ролей.
 *
 * Атрибут voter-а — `attribute()`: `#[IsGranted('PLAN_WAREHOUSE_VIEW')]`.
 */
enum PlanCapability: string
{
    case TasksSeeAll = 'tasks.seeAll';
    case SupplyManage = 'supply.manage';
    case SupplyApprove = 'supply.approve';
    case WarehouseView = 'warehouse.view';
    case WarehouseEdit = 'warehouse.edit';
    case AuditView = 'audit.view';
    case LeadsReceive = 'leads.receive';

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
