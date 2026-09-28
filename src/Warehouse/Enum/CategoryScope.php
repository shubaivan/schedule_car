<?php

namespace App\Warehouse\Enum;

/** Чого стосується категорія довідника. */
enum CategoryScope: string
{
    case Item = 'item';
    case Client = 'client';
    case Site = 'site';

    public function label(): string
    {
        return match ($this) {
            self::Item => 'Майно',
            self::Client => 'Клієнти',
            self::Site => "Об'єкти",
        };
    }
}
