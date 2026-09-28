<?php

namespace App\Warehouse\Enum;

/**
 * Місце, де може бути майно: власний склад або об'єкт.
 *
 * Склад — теж місце, а не «відсутність місця»: так приймання, відвантаження й
 * повернення стають одним рухом «звідки → куди», а складів може бути кілька.
 */
enum SiteKind: string
{
    case Warehouse = 'warehouse';
    case Site = 'site';

    public function label(): string
    {
        return match ($this) {
            self::Warehouse => 'Склад',
            self::Site => "Об'єкт",
        };
    }
}
