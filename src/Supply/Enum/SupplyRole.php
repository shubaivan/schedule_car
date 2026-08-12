<?php

namespace App\Supply\Enum;

/**
 * Роль користувача в процесі постачання.
 *
 * Робітник бачить лише свої заявки, менеджер — усі й керує статусами,
 * адмін додатково призначає ролі та підрозділи.
 */
enum SupplyRole: string
{
    case Worker = 'worker';
    case Manager = 'manager';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Worker => 'Робітник',
            self::Manager => 'Менеджер із постачання',
            self::Admin => 'Адміністратор',
        };
    }

    /** Може обробляти чужі заявки й змінювати статуси. */
    public function canManage(): bool
    {
        return $this === self::Manager || $this === self::Admin;
    }

    /** Symfony-роль для файрволу CRM. */
    public function securityRole(): string
    {
        return match ($this) {
            self::Worker => 'ROLE_SUPPLY_WORKER',
            self::Manager => 'ROLE_SUPPLY_MANAGER',
            self::Admin => 'ROLE_SUPPLY_ADMIN',
        };
    }
}
