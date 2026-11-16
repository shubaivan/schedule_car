<?php

namespace App\Plan\Counterparty\Enum;

/**
 * Умови оплати за замовчуванням для контрагента. Договір (коли з'являться
 * «договори light») зможе їх перевизначити.
 */
enum PaymentTerms: string
{
    case Prepayment = 'prepayment';
    case Partial = 'partial';
    case OnDelivery = 'on_delivery';
    case Deferral = 'deferral';

    public function label(): string
    {
        return match ($this) {
            self::Prepayment => 'Передоплата 100 %',
            self::Partial => 'Часткова передоплата',
            self::OnDelivery => 'Оплата по факту відвантаження',
            self::Deferral => 'Відстрочка платежу',
        };
    }
}
