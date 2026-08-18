<?php

namespace App\Fleet\Telegram;

/** Спільні callback_data автопарку — тримаємо в одному місці, як і в постачанні. */
final class FleetCallback
{
    public const MENU = 'fleet:menu';
    public const SCHEDULE = 'fleet:schedule';
    public const MY_TRIPS = 'fleet:my';
    public const DRIVER_TRIPS = 'fleet:driver';
    public const BOOK = 'fleet:book';

    /** fleet:schedule:<зсув у днях> — гортання розкладу вперед і назад. */
    public const SCHEDULE_PREFIX = 'fleet:schedule:';
    /** fleet:cancel:<id> — зняти своє бронювання. */
    public const CANCEL_PREFIX = 'fleet:cancel:';

    public static function schedule(int $offsetDays): string
    {
        return self::SCHEDULE_PREFIX . $offsetDays;
    }

    public static function cancel(int $setId): string
    {
        return self::CANCEL_PREFIX . $setId;
    }
}
