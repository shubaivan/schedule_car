<?php

namespace App\Plan\People;

/**
 * Телефон людини поруч з іменем (рішення Івана 15.11: «Олександр Чигрин (093 350 44 42)»).
 * Одне місце, де номер нормалізується й форматується; фронт форматує так само (plan/people.ts).
 */
final class PhoneFormat
{
    /** 0631234567 / 631234567 / +380 63 123 45 67 → 380631234567; з іншим кодом країни — як є; null — замало цифр. */
    public static function e164(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $phone);

        return match (true) {
            strlen($digits) === 9 => '380' . $digits,
            strlen($digits) === 10 && str_starts_with($digits, '0') => '38' . $digits,
            strlen($digits) >= 11 => $digits,
            default => null,
        };
    }

    /** «093 350 44 42» для українських, «+48 …» (цифрами) для інших; null — номера немає. */
    public static function display(?string $phone): ?string
    {
        $full = self::e164($phone);

        if ($full === null) {
            return null;
        }

        if (strlen($full) === 12 && str_starts_with($full, '380')) {
            $local = '0' . substr($full, 3);

            return sprintf('%s %s %s %s', substr($local, 0, 3), substr($local, 3, 3), substr($local, 6, 2), substr($local, 8, 2));
        }

        return '+' . $full;
    }

    /**
     * Для повідомлень бота: «+380 93 350 44 42» — Telegram сам робить такий номер
     * клікабельним (у локальному «093 …» він його не впізнає). Інші країни — «+48512345678».
     */
    public static function international(?string $phone): ?string
    {
        $full = self::e164($phone);

        if ($full === null) {
            return null;
        }

        return strlen($full) === 12 && str_starts_with($full, '380')
            ? sprintf('+%s %s %s %s %s', substr($full, 0, 3), substr($full, 3, 2), substr($full, 5, 3), substr($full, 8, 2), substr($full, 10, 2))
            : '+' . $full;
    }

    /** «Олександр Чигрин (+380 93 350 44 42)» — ім'я людини в текстах бота й push; без номера — лише ім'я. */
    public static function named(string $name, ?string $phone): string
    {
        $intl = self::international($phone);

        return $intl !== null ? sprintf('%s (%s)', $name, $intl) : $name;
    }

    /** Для посилання tel: — «+380933504442». */
    public static function tel(?string $phone): ?string
    {
        $full = self::e164($phone);

        return $full !== null ? '+' . $full : null;
    }
}
