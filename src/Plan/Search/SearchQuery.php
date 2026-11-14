<?php

namespace App\Plan\Search;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * Запит пошуку — те саме, що в адресі сторінки (`/crm/tasks/search?q=цемент&type=supply&author=5`),
 * тож посиланням можна поділитися, а «Назад» повертає попередні фільтри.
 *
 * Списки приймаються і як `type=supply,task`, і як `type[]=supply&type[]=task`.
 */
final readonly class SearchQuery
{
    public const int PAGE = 20;
    public const int MAX_PAGE = 50;

    /**
     * @param list<string> $types групи (task, supply, warehouse, comment, file, person) або doc_type
     * @param list<string> $statuses `task:in_work`, `supply:approval`…
     * @param list<int> $departments
     */
    public function __construct(
        public string $q = '',
        public array $types = [],
        public ?int $person = null,
        public ?int $author = null,
        public ?int $executor = null,
        public ?int $manager = null,
        public array $departments = [],
        public array $statuses = [],
        public string $client = '',
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public bool $starred = false,
        public string $sort = 'relevance',
        public int $offset = 0,
        public int $limit = self::PAGE,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = $request->query;

        return new self(
            q: mb_substr(trim((string) $query->get('q', '')), 0, 120),
            types: array_slice(self::strings($query->all()['type'] ?? null, '/^[a-z_]{1,16}$/'), 0, 12),
            person: self::id($query->get('person')),
            author: self::id($query->get('author')),
            executor: self::id($query->get('executor')),
            manager: self::id($query->get('manager')),
            departments: array_slice(array_map('intval', self::strings($query->all()['department'] ?? null, '/^[1-9]\d{0,9}$/')), 0, 10),
            statuses: array_slice(self::strings($query->all()['status'] ?? null, '/^[a-z_]{1,16}:[a-z_]{1,22}$/'), 0, 20),
            client: mb_substr(trim((string) $query->get('client', '')), 0, 80),
            from: self::date($query->get('from')),
            to: self::date($query->get('to')),
            starred: $query->getBoolean('starred'),
            sort: $query->get('sort') === 'recent' ? 'recent' : 'relevance',
            offset: min(5000, max(0, $query->getInt('offset'))),
            limit: min(self::MAX_PAGE, max(1, $query->getInt('limit', self::PAGE))),
        );
    }

    /** @return list<string> слова запиту */
    public function terms(): array
    {
        return mb_strlen($this->q) >= 2 ? SearchText::terms($this->q) : [];
    }

    /** Чи є хоч один фільтр (порожній q + фільтр = «усі прецеденти менеджера X»). */
    public function hasFilters(): bool
    {
        return $this->types !== [] || $this->person !== null || $this->author !== null || $this->executor !== null ||
            $this->manager !== null || $this->departments !== [] || $this->statuses !== [] || $this->client !== '' ||
            $this->from !== null || $this->to !== null || $this->starred;
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $pattern): array
    {
        $items = is_array($value) ? $value : explode(',', (string) ($value ?? ''));
        $out = [];

        foreach ($items as $item) {
            $item = trim((string) $item);

            if ($item !== '' && preg_match($pattern, $item) === 1) {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    private static function id(mixed $value): ?int
    {
        return is_scalar($value) && preg_match('/^[1-9]\d{0,9}$/', (string) $value) === 1 && (int) $value <= 2147483647 ? (int) $value : null;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d\d-\d\d$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
