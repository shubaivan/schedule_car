<?php

namespace App\Warehouse\Enum;

/** Який документ прикріпили. Назва потрапляє і в ім'я файлу на Google Диску. */
enum DocumentType: string
{
    case Contract = 'contract';
    case Waybill = 'waybill';
    case Expense = 'expense';
    case Act = 'act';
    case Bill = 'bill';
    case Photo = 'photo';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Договір',
            self::Waybill => 'Накладна',
            self::Expense => 'Видаткова',
            self::Act => 'Акт',
            self::Bill => 'Рахунок',
            self::Photo => 'Фото',
            self::Other => 'Інше',
        };
    }
}
