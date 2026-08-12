<?php

namespace App\Enum;

/**
 * Доступ користувача до бота. Нові люди чекають підтвердження менеджера:
 * бот заводський, і випадкові підписники не мають подавати заявки.
 */
enum AccessStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Очікує підтвердження',
            self::Approved => 'Підтверджений',
            self::Rejected => 'Відхилений',
        };
    }
}
