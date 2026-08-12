<?php

namespace App\Supply\Enum;

/** Як розрахувались із постачальником. */
enum PaymentType: string
{
    case Bank = 'bank';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Безготівка',
            self::Cash => 'Готівка',
        };
    }
}
