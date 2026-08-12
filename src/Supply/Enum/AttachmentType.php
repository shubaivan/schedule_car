<?php

namespace App\Supply\Enum;

/** Що саме прикріпили до заявки. Визначає і назву файлу в Google Drive. */
enum AttachmentType: string
{
    case Invoice = 'invoice';
    case Bill = 'bill';
    case Contract = 'contract';
    case Payment = 'payment';
    case Photo = 'photo';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Накладна',
            self::Bill => 'Рахунок',
            self::Contract => 'Договір',
            self::Payment => 'Платіжка',
            self::Photo => 'Фото',
            self::Other => 'Інше',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Invoice => '🧾',
            self::Bill => '💳',
            self::Contract => '📑',
            self::Payment => '🏦',
            self::Photo => '📷',
            self::Other => '📎',
        };
    }
}
