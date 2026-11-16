<?php

namespace App\Plan\Counterparty\Enum;

/** Статус платника ПДВ: від нього залежать рахунки (з ПДВ чи без) і податкові накладні. */
enum VatStatus: string
{
    case Payer = 'payer';
    case NonPayer = 'non_payer';

    public function label(): string
    {
        return match ($this) {
            self::Payer => 'Платник ПДВ',
            self::NonPayer => 'Неплатник ПДВ',
        };
    }
}
