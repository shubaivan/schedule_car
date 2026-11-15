<?php

namespace App\Supply\Telegram;

/**
 * Спільні callback_data для кнопок постачання: їх формує SupplyNotifier,
 * а обробляють хендлери бота. Тримаємо в одному місці, щоб не розповзались рядки.
 */
final class SupplyCallback
{
    public const string MENU = 'supply:menu';
    public const string NEW_REQUEST = 'supply:new';
    public const string MY_REQUESTS = 'supply:my';
    /** Спільний список: заявки всіх підрозділів бачить кожен. */
    public const string ALL_REQUESTS = 'supply:all';
    /** Одноразове посилання для входу в CRM. */
    public const string CRM_LOGIN = 'supply:crm';
    /** Вихід із будь-якої розмови; поза розмовою веде в меню постачання. */
    public const string CANCEL = 'supply:cancel';

    /** supply:view:<id> */
    public const string VIEW_PREFIX = 'supply:view:';
    /** supply:status:<id>:<status> */
    public const string STATUS_PREFIX = 'supply:status:';
    /** supply:reject:<id> — запитує причину */
    public const string REJECT_PREFIX = 'supply:reject:';
    /** supply:comment:<id> */
    public const string COMMENT_PREFIX = 'supply:comment:';
    /** supply:buy:<id> — записати, у кого купили */
    public const string PURCHASE_PREFIX = 'supply:buy:';
    /** supply:file:<id> — надіслати накладну чи інший документ фото/файлом */
    public const string ATTACH_PREFIX = 'supply:file:';
    /** supply:accent:<id> — вибір мітки для керівника */
    public const string ACCENT_PREFIX = 'supply:accent:';
    /** supply:mark:<id>:<accent> — сама мітка. Окремий префікс, щоб два
     * маршрути не сперечались за один шаблон. */
    public const string MARK_PREFIX = 'supply:mark:';

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

    public static function purchase(int $requestId): string
    {
        return self::PURCHASE_PREFIX . $requestId;
    }

    public static function attach(int $requestId): string
    {
        return self::ATTACH_PREFIX . $requestId;
    }

    public static function accent(int $requestId): string
    {
        return self::ACCENT_PREFIX . $requestId;
    }

    public static function mark(int $requestId, string $accent): string
    {
        return self::MARK_PREFIX . $requestId . ':' . $accent;
    }
}
