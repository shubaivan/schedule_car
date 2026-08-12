<?php

namespace App\Telegram\Access;

/** callback_data кнопок підтвердження реєстрації. */
final class AccessCallback
{
    public const APPROVE_PREFIX = 'access:approve:';
    public const REJECT_PREFIX = 'access:reject:';

    public static function approve(int $userId): string
    {
        return self::APPROVE_PREFIX . $userId;
    }

    public static function reject(int $userId): string
    {
        return self::REJECT_PREFIX . $userId;
    }
}
