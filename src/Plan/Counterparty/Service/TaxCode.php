<?php

namespace App\Plan\Counterparty\Service;

/**
 * Перевірка українських кодів за контрольною цифрою — щоб опечатку в ЄДРПОУ
 * не знайшли лише тоді, коли податкова поверне накладну.
 *
 * - ЄДРПОУ (8 цифр): ваги 1…7 або 7,1…6 (для кодів між 30 000 000 і 60 000 000);
 *   залишок 10 — повтор з вагами +2; знову 10 — контрольна 0.
 * - РНОКПП / ІПН (10 цифр): ваги −1,5,7,9,4,6,10,5,7; контрольна = (сума mod 11) mod 10.
 * - IBAN (UA + 27): ISO 13616, mod 97 = 1.
 */
final class TaxCode
{
    /** Лише цифри; null — порожньо. */
    public static function digits(?string $value): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $value);

        return $digits !== '' ? $digits : null;
    }

    public static function isEdrpou(string $code): bool
    {
        if (preg_match('/^\d{8}$/', $code) !== 1) {
            return false;
        }

        $d = array_map('intval', str_split($code));
        $n = (int) $code;
        $weights = $n > 30000000 && $n < 60000000 ? [7, 1, 2, 3, 4, 5, 6] : [1, 2, 3, 4, 5, 6, 7];
        $check = self::weighted($d, $weights) % 11;

        if ($check === 10) {
            $check = self::weighted($d, array_map(static fn (int $w) => $w + 2, $weights)) % 11;
            $check = $check === 10 ? 0 : $check;
        }

        return $check === $d[7];
    }

    public static function isRnokpp(string $code): bool
    {
        if (preg_match('/^\d{10}$/', $code) !== 1) {
            return false;
        }

        $d = array_map('intval', str_split($code));

        return (self::weighted($d, [-1, 5, 7, 9, 4, 6, 10, 5, 7]) % 11) % 10 === $d[9];
    }

    /** Індивідуальний податковий номер платника ПДВ — 12 цифр. */
    public static function isVatNumber(string $code): bool
    {
        return preg_match('/^\d{12}$/', $code) === 1;
    }

    /** «UA21 3223 1300 …» → «UA213223130000026007233566001»; null — порожньо. */
    public static function iban(?string $value): ?string
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', (string) $value));

        return $iban !== '' ? $iban : null;
    }

    public static function isIban(string $iban): bool
    {
        if (preg_match('/^UA\d{27}$/', $iban) !== 1) {
            return false;
        }

        $moved = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';

        foreach (str_split($moved) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char[0]) - 55) : $char;
        }

        // mod 97 частинами: число довше за int64.
        $rest = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $rest = (int) ($rest . $chunk) % 97;
        }

        return $rest === 1;
    }

    /**
     * @param list<int> $digits
     * @param list<int> $weights
     */
    private static function weighted(array $digits, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $i => $weight) {
            $sum += $digits[$i] * $weight;
        }

        return $sum;
    }
}
