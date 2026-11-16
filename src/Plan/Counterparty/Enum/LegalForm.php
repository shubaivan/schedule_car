<?php

namespace App\Plan\Counterparty\Enum;

/**
 * Юрособа, ФОП чи фізична особа. Від цього залежить код: у юрособи —
 * ЄДРПОУ (8 цифр), у ФОП і фізособи — РНОКПП / ІПН (10 цифр).
 */
enum LegalForm: string
{
    case Company = 'company';
    case Entrepreneur = 'fop';
    case Person = 'person';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Юридична особа',
            self::Entrepreneur => 'ФОП',
            self::Person => 'Фізична особа',
        };
    }

    /** Як називається код у формі й у помилках. */
    public function codeLabel(): string
    {
        return $this === self::Company ? 'ЄДРПОУ' : 'РНОКПП (ІПН)';
    }
}
