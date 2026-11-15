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

    /** Для посилання tel: — «+380933504442». */
    public static function tel(?string $phone): ?string
    {
        $full = self::e164($phone);

        return $full !== null ? '+' . $full : null;
    }
}
