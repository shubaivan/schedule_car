<?php

namespace App\Tasks\Search;

/**
 * Сниппет довкола першого збігу (±N символів) і розбиття на шматки
 * «звичайний / збіг». Фронт малює збіги як <mark> без v-html — жодного
 * HTML з бази в розмітку не потрапляє.
 */
final class Snippet
{
    /**
     * @param list<string> $terms
     *
     * @return list<array{t: string, m: bool}>
     */
    public static function around(string $text, array $terms, int $radius = 60): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return [];
        }

        $first = null;

        foreach ($terms as $term) {
            $pos = mb_stripos($text, $term);

            if ($pos !== false && ($first === null || $pos < $first)) {
                $first = $pos;
            }
        }

        $length = mb_strlen($text);
        $start = $first === null ? 0 : max(0, $first - $radius);
        $end = min($length, ($first ?? 0) + $radius * 2);

        // Не різати слово навпіл.
        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start);
            $start = $space !== false && $space < ($first ?? $start) ? $space + 1 : $start;
        }

        if ($end < $length) {
            $space = mb_strrpos(mb_substr($text, 0, $end), ' ');
            $end = $space !== false && $space > ($first ?? 0) ? $space : $end;
        }

        $piece = mb_substr($text, $start, $end - $start);
        $parts = self::mark($piece, $terms);

        if ($start > 0) {
            array_unshift($parts, ['t' => '… ', 'm' => false]);
        }

        if ($end < $length) {
            $parts[] = ['t' => ' …', 'm' => false];
        }

        return $parts;
    }

    /**
     * @param list<string> $terms
     *
     * @return list<array{t: string, m: bool}>
     */
    public static function mark(string $text, array $terms): array
    {
        $terms = array_values(array_filter($terms, static fn (string $t) => $t !== ''));

        if ($terms === [] || $text === '') {
            return $text === '' ? [] : [['t' => $text, 'm' => false]];
        }

        usort($terms, static fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/(' . implode('|', array_map(static fn (string $t) => preg_quote($t, '/'), $terms)) . ')/iu';
        $chunks = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];
        $out = [];

        foreach ($chunks as $chunk) {
            $out[] = ['t' => $chunk, 'm' => preg_match('/^(?:' . implode('|', array_map(static fn (string $t) => preg_quote($t, '/'), $terms)) . ')$/iu', $chunk) === 1];
        }

        return $out;
    }
}
