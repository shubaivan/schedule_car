<?php

namespace App\Warehouse\Enum;

/** Стан позиції окремо від того, де вона: у ремонті річ може стояти й на складі. */
enum ItemState: string
{
    case Active = 'active';
    case Repair = 'repair';
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'У роботі',
            self::Repair => 'У ремонті',
            self::WrittenOff => 'Списано',
        };
    }
}
