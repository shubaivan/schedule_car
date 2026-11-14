<?php

namespace App\Tasks\Search;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Tasks\Repository\TaskVisibility;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Глобальний пошук «Плану»: задачі, коментарі, файли, люди.
 *
 * Збіг — «за частиною слова»: кожне слово запиту має знайтися (lower(x) LIKE
 * '%слово%', індекси pg_trgm по lower()). Повнотекстовий 'simple' (префікси
 * слів) — друга гілка OR, щоб планувальник міг узяти й GIN по tsvector, і для
 * ранжування: цілі слова та збіг у назві — вище. Видимість — як на дошці:
 * чужих задач, їхніх коментарів і файлів людина не знайде.
 */
class TaskSearch
{
    public const GROUPS = ['tasks', 'comments', 'files', 'people'];

    private const TASK_DOC = "to_tsvector('simple', coalesce(t.title, '') || ' ' || coalesce(t.client, '') || ' ' || coalesce(t.description, ''))";
    private const MESSAGE_DOC = "to_tsvector('simple', m.text)";

    public function __construct(private Connection $db, private TelegramUserRepository $users)
    {
    }

    /** @return list<string> слова запиту в нижньому регістрі (до 6) */
    public static function terms(string $q): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($q))) ?: [];
        $words = array_values(array_unique(array_filter($words, static fn (string $w) => $w !== '')));

        return array_slice($words, 0, 6);
    }

    /**
     * @param list<string> $terms
     *
     * @return array<string, int>
     */
    public function counts(TelegramUser $viewer, array $terms, bool $starred): array
    {
        $out = [];

        foreach (self::GROUPS as $group) {
            $out[$group] = $this->count($viewer, $group, $terms, $starred);
        }

        return $out;
    }

    /**
     * @param list<string> $terms
     *
     * @return list<array<string, mixed>>
     */
    public function find(TelegramUser $viewer, string $group, array $terms, bool $starred, int $limit, int $offset = 0): array
    {
        if ($terms === []) {
            return [];
        }

        if ($group === 'people') {
            return array_slice($this->people($terms, $starred), $offset, $limit);
        }

        [$sql, $params, $order] = $this->query($viewer, $group, $terms, $starred);

        return $this->db->fetchAllAssociative($sql . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
    }

    /** @param list<string> $terms */
    private function count(TelegramUser $viewer, string $group, array $terms, bool $starred): int
    {
        if ($terms === []) {
            return 0;
        }

        if ($group === 'people') {
            return count($this->people($terms, $starred));
        }

        [$sql, $params] = $this->query($viewer, $group, $terms, $starred);

        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM (' . $sql . ') c', $params);
    }

    /**
     * @param list<string> $terms
     *
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    private function query(TelegramUser $viewer, string $group, array $terms, bool $starred): array
    {
        [$visible, $params] = TaskVisibility::sql($viewer);
        $params['me'] = (int) $viewer->getId();
        $tsQuery = self::tsQuery($terms);
        $params['tsq'] = $tsQuery ?? '';

        $like = static function (array $columns) use ($terms, &$params): string {
            $all = [];

            foreach ($terms as $i => $term) {
                $params['p' . $i] = '%' . addcslashes($term, '%_\\') . '%';
                $all[] = '(' . implode(' OR ', array_map(static fn (string $c) => "lower({$c}) LIKE :p{$i}", $columns)) . ')';
            }

            return '(' . implode(' AND ', $all) . ')';
        };

        switch ($group) {
            case 'tasks':
                $match = $like(['t.title', 't.client', 't.description']);
                $titleOnly = $like(['t.title']);

                if ($tsQuery !== null) {
                    $match = '(' . self::TASK_DOC . " @@ to_tsquery('simple', :tsq) OR " . $match . ')';
                }

                // «12», «№ 12», «#0012» — номер задачі.
                if (count($terms) === 1 && preg_match('/^[№#]?0*(\d{1,9})$/u', $terms[0], $m) === 1) {
                    $params['num'] = (int) $m[1];
                    $match = '(' . $match . ' OR t.id = :num)';
                }

                $rank = ($tsQuery !== null ? 'ts_rank(' . self::TASK_DOC . ", to_tsquery('simple', :tsq))" : '0')
                    . ' + CASE WHEN ' . $titleOnly . ' THEN 1 ELSE 0 END';

                $sql = 'SELECT t.id, t.title, t.client, t.description, t.status, t.last_activity_at, t.created_at,
                               f.id IS NOT NULL AS favorite, ' . $rank . ' AS rank
                          FROM task t
                          LEFT JOIN task_favorite f ON f.task_id = t.id AND f.user_id = :me
                         WHERE ' . $visible . ' AND ' . $match . ($starred ? ' AND f.id IS NOT NULL' : '');

                return [$sql, $params, 'rank DESC, t.last_activity_at DESC, t.id DESC'];

            case 'comments':
                $match = $like(['m.text']);

                if ($tsQuery !== null) {
                    $match = '(' . self::MESSAGE_DOC . " @@ to_tsquery('simple', :tsq) OR " . $match . ')';
                }

                $rank = $tsQuery !== null ? 'ts_rank(' . self::MESSAGE_DOC . ", to_tsquery('simple', :tsq))" : '0';

                $sql = 'SELECT m.id, m.task_id, m.text, m.created_at, m.author_id, t.title, t.status,
                               s.id IS NOT NULL AS starred, ' . $rank . ' AS rank
                          FROM task_message m
                          JOIN task t ON t.id = m.task_id
                          LEFT JOIN task_message_star s ON s.message_id = m.id AND s.user_id = :me
                         WHERE m.kind = \'comment\' AND m.deleted_at IS NULL AND ' . $visible . ' AND ' . $match
                         . ($starred ? ' AND s.id IS NOT NULL' : '');

                return [$sql, $params, 'rank DESC, m.id DESC'];

            case 'files':
                $sql = 'SELECT a.id, a.original_name, a.kind, a.size, a.preview_state, a.created_at, m.task_id, t.title, t.status
                          FROM task_attachment a
                          JOIN task_message m ON m.id = a.message_id
                          JOIN task t ON t.id = m.task_id
                          ' . ($starred ? 'JOIN task_favorite f ON f.task_id = t.id AND f.user_id = :me' : '') . '
                         WHERE m.deleted_at IS NULL AND ' . $visible . ' AND ' . $like(['a.original_name']);

                return [$sql, $params, 'a.id DESC'];
        }

        throw new InvalidArgumentException('Unknown search group ' . $group);
    }

    /**
     * Люди — з того ж довідника, що й «Співробітники» (його бачать усі в CRM).
     *
     * @param list<string> $terms
     *
     * @return list<array<string, mixed>>
     */
    private function people(array $terms, bool $starred): array
    {
        if ($starred) {
            return [];
        }

        $out = [];

        foreach ($this->users->findBy([], ['first_name' => 'ASC']) as $user) {
            if ($user->isArchived() || ! $user->isApproved()) {
                continue;
            }

            $hay = mb_strtolower(implode(' ', [$user->shortName(), $user->getPosition() ?? '', $user->getDepartment()?->getName() ?? '']));

            foreach ($terms as $term) {
                if (! str_contains($hay, $term)) {
                    continue 2;
                }
            }

            $out[] = ['user' => $user];
        }

        return $out;
    }

    /** @param list<string> $terms 'плит:* & ПК:*' — лише літери й цифри, без операторів tsquery. */
    private static function tsQuery(array $terms): ?string
    {
        $words = array_values(array_filter(array_map(
            static fn (string $t) => (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $t),
            $terms,
        )));

        return $words === [] ? null : implode(' & ', array_map(static fn (string $w) => $w . ':*', $words));
    }
}
