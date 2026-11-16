<?php

namespace App\Plan\Counterparty\Enum;

/** Хто він для заводу: купує, постачає чи і те, і те (перевізник, що купує бетон). */
enum CounterpartyKind: string
{
    case Client = 'client';
    case Supplier = 'supplier';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Клієнт',
            self::Supplier => 'Постачальник',
            self::Both => 'Клієнт і постачальник',
        };
    }
}
