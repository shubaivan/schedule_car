<?php

namespace App\Telegram\Access;

/** callback_data кнопок підтвердження реєстрації. */
final class AccessCallback
{
    public const string APPROVE_PREFIX = 'access:approve:';
    public const string REJECT_PREFIX = 'access:reject:';

    public static function approve(int $userId): string
    {
        return self::APPROVE_PREFIX . $userId;
    }

    public static function reject(int $userId): string
    {
        return self::REJECT_PREFIX . $userId;
    }
}
