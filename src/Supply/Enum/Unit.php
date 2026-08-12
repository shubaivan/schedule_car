<?php

namespace App\Supply\Enum;

/** Одиниці виміру в заявці. Фіксований список — інакше звіти не збираються. */
enum Unit: string
{
    case Piece = 'piece';
    case Kg = 'kg';
    case Ton = 'ton';
    case CubicMeter = 'm3';
    case Meter = 'm';
    case Pack = 'pack';
    case Liter = 'l';

    public function label(): string
    {
        return match ($this) {
            self::Piece => 'шт',
            self::Kg => 'кг',
            self::Ton => 'т',
            self::CubicMeter => 'м³',
            self::Meter => 'м',
            self::Pack => 'уп',
            self::Liter => 'л',
        };
    }
}
