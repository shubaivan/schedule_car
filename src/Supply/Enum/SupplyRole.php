<?php

namespace App\Supply\Enum;

/**
 * Роль користувача в процесі постачання.
 *
 * Заявки бачать усі; різниця в тому, хто що може робити. Робітник подає свої,
 * менеджер веде чужі й закупівлі, директор погоджує витрату грошей,
 * адмін додатково призначає ролі та підрозділи.
 */
enum SupplyRole: string
{
    case Worker = 'worker';
    case Manager = 'manager';
    case Director = 'director';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Worker => 'Робітник',
            self::Manager => 'Менеджер із постачання',
            self::Director => 'Директор',
            self::Admin => 'Адміністратор',
        };
    }

    /** Може обробляти чужі заявки й змінювати статуси. */
    public function canManage(): bool
    {
        return $this === self::Manager || $this === self::Admin;
    }

    /**
     * Може погодити витрату грошей.
     *
     * Вимога директора: жодна оплата не проходить повз неї, тож заявку з
     * «На затвердженні» зрушує тільки вона. Адмін лишається запасним ключем —
     * інакше без директора в боті заявки застрягли б назавжди.
     */
    public function canApprovePayment(): bool
    {
        return $this === self::Director || $this === self::Admin;
    }

    /**
     * Чи має ця роль право саме на цей перехід.
     *
     * Одне місце правди для гварда в ChangeStatus і для кнопок у боті й CRM:
     * якщо кнопку видно, натискання не має впертись у помилку.
     */
    public function canMoveRequest(SupplyStatus $from, SupplyStatus $to): bool
    {
        // Відхилити заявку, яка чекає грошей, може і директор, і менеджер:
        // «не купуємо» — не витрата.
        if ($from === SupplyStatus::Approval && $to !== SupplyStatus::Rejected) {
            return $this->canApprovePayment();
        }

        return $this->canManage() || ($from === SupplyStatus::Approval && $this->canApprovePayment());
    }

    /** Symfony-роль для файрволу CRM. */
    public function securityRole(): string
    {
        return match ($this) {
            self::Worker => 'ROLE_SUPPLY_WORKER',
            self::Manager => 'ROLE_SUPPLY_MANAGER',
            self::Director => 'ROLE_SUPPLY_DIRECTOR',
            self::Admin => 'ROLE_SUPPLY_ADMIN',
        };
    }
}
