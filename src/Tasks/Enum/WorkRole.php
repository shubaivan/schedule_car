<?php

namespace App\Tasks\Enum;

use App\Supply\Enum\SupplyRole;

/**
 * Роль людини на «робочому місці» (модуль задач).
 *
 * Вимога клієнта від 13.11.2026: у кожної ролі своя функціональність і права —
 * продажі, бухгалтерія й виробництво бачать різне. Роль постачання (SupplyRole)
 * лишається окремою: у заявках людина може бути робітником, а в задачах — менеджером.
 */
enum WorkRole: string
{
    case Admin = 'admin';
    case Director = 'director';
    case Manager = 'manager';
    case Accountant = 'accountant';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Адміністратор',
            self::Director => 'Директор',
            self::Manager => 'Менеджер з продажу',
            self::Accountant => 'Бухгалтер',
            self::Production => 'Виробництво',
        };
    }

    /** Хто бачить усі задачі заводу, а не лише ті, де він учасник. */
    public function seesAllTasks(): bool
    {
        return $this === self::Admin || $this === self::Director;
    }

    /** Веде людей, відділи й кошик. */
    public function managesStaff(): bool
    {
        return $this === self::Admin;
    }

    /** Рішення директора: погодити, відхилити, відкласти. Адмін — запасний ключ. */
    public function decides(): bool
    {
        return $this === self::Admin || $this === self::Director;
    }

    /** Править будь-яку задачу (назву, учасників, мітки) і рухає її статуси. */
    public function editsAnyTask(): bool
    {
        return $this === self::Admin || $this === self::Manager;
    }

    /** Роль для тих, кому її ще не призначили: виводимо з ролі в заявках. */
    public static function fromSupply(SupplyRole $role): self
    {
        return match ($role) {
            SupplyRole::Admin => self::Admin,
            SupplyRole::Director => self::Director,
            SupplyRole::Manager => self::Manager,
            SupplyRole::Worker => self::Production,
        };
    }
}
