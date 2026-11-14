<?php

namespace App\Plan\Search;

/**
 * Одна нормалізація тексту для індексу й для запиту (pg_trgm, LIKE): нижній регістр, усі
 * різновиди апострофа (’ ʼ ‘ ` ´) → «'», пробіли зведені. Інакше «будинок
 * з'єднання» і «з’єднання» не знаходили б одне одного.
 *
 * SQL-версія (sql()) — для SearchProjector, PHP-версія — для слів запиту;
 * тримати їх однаковими.
 */
final class SearchText
{
    private const APOSTROPHES = ['’', 'ʼ', '‘', '`', '´'];

    /** Скільки слів запиту беремо (решту ігноруємо — щоб запит лишався дешевим). */
    public const int MAX_TERMS = 6;

    /** Скільки тексту одного документа кладемо в search_text. */
    public const int MAX_TEXT = 20000;

    public static function normalize(string $text): string
    {
        $text = str_replace(self::APOSTROPHES, "'", mb_strtolower($text));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> слова запиту (до MAX_TERMS, без повторів) */
    public static function terms(string $q): array
    {
        $words = preg_split('/\s+/u', self::normalize($q)) ?: [];
        $words = array_values(array_unique(array_filter($words, static fn (string $w) => $w !== '')));

        return array_slice($words, 0, self::MAX_TERMS);
    }

    /** `%слово%` для LIKE з екрануванням % і _. */
    public static function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }

    /**
     * SQL-вираз тієї ж нормалізації над уже зібраним текстом.
     */
    public static function sql(string $expression): string
    {
        $from = implode('', self::APOSTROPHES);
        $to = str_repeat("''", count(self::APOSTROPHES));

        return sprintf(
            "left(btrim(regexp_replace(lower(translate(%s, '%s', '%s')), '\\s+', ' ', 'g')), %d)",
            $expression,
            $from,
            $to,
            self::MAX_TEXT,
        );
    }
}
