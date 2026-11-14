<?php

namespace App\Plan\Audit;

use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Фільтри журналу аудиту — з адреси сторінки (/crm/admin/audit?…), API і CSV
 * однаково: actor, group[], type[], kind (тип суб'єкта), subject (`task-12`),
 * from/to (дати, включно), ip, q (текст), before/after (курсор — id події).
 *
 * Невідоме значення фільтра — помилка (400), а не мовчки «усе»: інакше
 * суперадмін бачив би журнал, якого не просив, і думав, що фільтр працює.
 */
final readonly class AuditFilter
{
    public const int PAGE = 50;
    public const int MAX_PAGE = 200;

    /**
     * @param list<string> $types типи plan_event (групи вже розгорнуті)
     */
    public function __construct(
        public ?int $actor = null,
        public array $types = [],
        public bool $withStatusComments = false,
        public ?SubjectType $kind = null,
        public ?SubjectRef $subject = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?string $ip = null,
        public ?string $q = null,
        public ?int $before = null,
        public ?int $after = null,
        public int $limit = self::PAGE,
    ) {
    }

    /** @param InputBag<string> $query */
    public static function fromQuery(InputBag $query): self
    {
        $groups = self::list($query, 'group');
        $types = self::list($query, 'type');

        foreach ($groups as $group) {
            if (! isset(AuditCatalog::GROUPS[$group])) {
                throw new InvalidArgumentException(sprintf('Невідома група подій «%s».', $group));
            }
        }

        foreach ($types as $type) {
            if (! isset(AuditCatalog::TYPES[$type])) {
                throw new InvalidArgumentException(sprintf('Невідомий тип події «%s».', $type));
            }
        }

        $kind = self::string($query, 'kind');
        $subject = self::string($query, 'subject');
        $from = self::date($query, 'from');
        $to = self::date($query, 'to');

        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('Період: «з» пізніше за «по».');
        }

        try {
            $subjectRef = $subject !== null ? SubjectRef::fromKey($subject) : null;
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Суб\'єкт: очікується ключ виду task-12.');
        }

        $q = self::string($query, 'q');

        return new self(
            actor: self::int($query, 'actor'),
            types: AuditCatalog::expand($groups, $types),
            // Живий журнал пише статус і коментар до нього ОДНІЄЮ подією (коментар у payload),
            // перенесена історія задач — двома. «Коментарі» мають знаходити обидва варіанти.
            withStatusComments: in_array('comment', $groups, true) || in_array('comment_added', $types, true),
            kind: $kind !== null ? (SubjectType::tryFrom($kind) ?? throw new InvalidArgumentException(sprintf('Невідомий тип суб\'єкта «%s».', $kind))) : null,
            subject: $subjectRef,
            from: $from,
            to: $to?->modify('+1 day'),
            ip: self::string($query, 'ip'),
            q: $q !== null ? mb_substr($q, 0, 200) : null,
            before: self::int($query, 'before'),
            after: self::int($query, 'after'),
            limit: max(1, min(self::MAX_PAGE, self::int($query, 'limit') ?? self::PAGE)),
        );
    }

    /** Той самий фільтр без курсора — для CSV і лічильника. */
    public function withoutCursor(): self
    {
        return new self($this->actor, $this->types, $this->withStatusComments, $this->kind, $this->subject, $this->from, $this->to, $this->ip, $this->q, null, null, $this->limit);
    }

    /** @param InputBag<string> $query */
    private static function string(InputBag $query, string $key): ?string
    {
        $value = $query->get($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @param InputBag<string> $query */
    private static function int(InputBag $query, string $key): ?int
    {
        $value = self::string($query, $key);

        if ($value === null) {
            return null;
        }

        // id у БД — int4 (події — bigint, але курсору вистачає): більше — 400, а не 500 від PostgreSQL.
        if (preg_match('/^\d{1,10}$/', $value) !== 1 || (int) $value > 2147483647) {
            throw new InvalidArgumentException(sprintf('Параметр %s: очікується число.', $key));
        }

        return (int) $value;
    }

    /**
     * `type[]=a&type[]=b`, а також `type=a,b` (зручно в адресі сторінки).
     *
     * @param InputBag<string> $query
     *
     * @return list<string>
     */
    private static function list(InputBag $query, string $key): array
    {
        $raw = $query->all()[$key] ?? [];
        $values = is_array($raw) ? $raw : explode(',', (string) $raw);
        $out = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[] = trim($value);
            }
        }

        return array_values(array_unique($out));
    }

    /** @param InputBag<string> $query */
    private static function date(InputBag $query, string $key): ?DateTimeImmutable
    {
        $value = self::string($query, $key);

        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Kyiv'));

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException(sprintf('Параметр %s: очікується дата РРРР-ММ-ДД.', $key));
        }

        return $date;
    }
}
