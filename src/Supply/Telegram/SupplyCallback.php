<?php

namespace App\Supply\Telegram;

/**
 * Спільні callback_data для кнопок постачання: їх формує SupplyNotifier,
 * а обробляють хендлери бота. Тримаємо в одному місці, щоб не розповзались рядки.
 */
final class SupplyCallback
{
    public const MENU = 'supply:menu';
    public const NEW_REQUEST = 'supply:new';
    public const MY_REQUESTS = 'supply:my';
    /** Одноразове посилання для входу в CRM — лише менеджерам. */
    public const CRM_LOGIN = 'supply:crm';

    /** supply:view:<id> */
    public const VIEW_PREFIX = 'supply:view:';
    /** supply:status:<id>:<status> */
    public const STATUS_PREFIX = 'supply:status:';
    /** supply:reject:<id> — запитує причину */
    public const REJECT_PREFIX = 'supply:reject:';
    /** supply:comment:<id> */
    public const COMMENT_PREFIX = 'supply:comment:';

    public static function view(int $requestId): string
    {
        return self::VIEW_PREFIX . $requestId;
    }

    public static function status(int $requestId, string $status): string
    {
        return self::STATUS_PREFIX . $requestId . ':' . $status;
    }

    public static function reject(int $requestId): string
    {
        return self::REJECT_PREFIX . $requestId;
    }

    public static function comment(int $requestId): string
    {
        return self::COMMENT_PREFIX . $requestId;
    }
}
