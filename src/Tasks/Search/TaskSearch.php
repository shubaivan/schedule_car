<?php

namespace App\Tasks\Search;

use App\Entity\TelegramUser;
use App\Plan\Search\PlanSearch;
use App\Plan\Search\SearchQuery;
use App\Plan\Search\SearchText;
use App\Repository\TelegramUserRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Старий API пошуку задач (`/api/tasks/search`, вкладки задачі / коментарі /
 * файли / люди) — тепер адаптер до спільного пошуку «Плану» (PlanSearch по
 * search_document): збіг, ранг і видимість ті самі, що в `/api/plan/search`.
 * Тут лише добираються з таблиць задач поля, яких чекає стара відповідь.
 * Люди — як і раніше з довідника співробітників.
 */
class TaskSearch
{
    public const GROUPS = ['tasks', 'comments', 'files', 'people'];

    /** Вкладка → тип документа в індексі. */
    private const DOC_TYPES = ['tasks' => 'task', 'comments' => 'comment', 'files' => 'file'];

    public function __construct(private Connection $db, private TelegramUserRepository $users, private PlanSearch $search)
    {
    }

    /** @return list<string> слова запиту в нижньому регістрі (до 6) */
    public static function terms(string $q): array
    {
        return SearchText::terms($q);
    }

    /**
     * @param list<string> $terms
     *
     * @return array<string, int>
     */
    public function counts(TelegramUser $viewer, array $terms, bool $starred): array
    {
        $out = array_fill_keys(self::GROUPS, 0);

        if ($terms === []) {
            return $out;
        }

        $facets = $this->search->search($viewer, new SearchQuery(q: implode(' ', $terms), types: array_values(self::DOC_TYPES), starred: $starred, limit: 1));

        foreach (self::DOC_TYPES as $group => $docType) {
            $out[$group] = $facets['facets']['docType'][$docType] ?? 0;
        }

        $out['people'] = count($this->people($terms, $starred));

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

        $docType = self::DOC_TYPES[$group] ?? throw new InvalidArgumentException('Unknown search group ' . $group);
        $hits = $this->search->search($viewer, new SearchQuery(q: implode(' ', $terms), types: [$docType], starred: $starred, offset: $offset, limit: $limit))['hits'];
        $ids = array_map(static fn (array $h) => (int) $h['ref_id'], $hits);

        if ($ids === []) {
            return [];
        }

        $me = (int) $viewer->getId();
        $sql = match ($group) {
            'tasks' => 'SELECT t.id, t.title, t.client, t.description, t.status, t.last_activity_at, t.created_at, f.id IS NOT NULL AS favorite
                          FROM task t LEFT JOIN task_favorite f ON f.task_id = t.id AND f.user_id = :me WHERE t.id IN (:ids)',
            'comments' => 'SELECT m.id, m.task_id, m.text, m.created_at, m.author_id, t.title, t.status, s.id IS NOT NULL AS starred
                          FROM task_message m JOIN task t ON t.id = m.task_id
                          LEFT JOIN task_message_star s ON s.message_id = m.id AND s.user_id = :me WHERE m.id IN (:ids)',
            default => 'SELECT a.id, a.original_name, a.kind, a.size, a.preview_state, a.created_at, m.task_id, t.title, t.status
                          FROM task_attachment a JOIN task_message m ON m.id = a.message_id JOIN task t ON t.id = m.task_id WHERE a.id IN (:ids)',
        };

        $byId = [];

        foreach ($this->db->fetchAllAssociative($sql, ['me' => $me, 'ids' => $ids], ['ids' => ArrayParameterType::INTEGER]) as $row) {
            $byId[(int) $row['id']] = $row;
        }

        // Порядок — як у спільному пошуку (ранг, потім новіші).
        return array_values(array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $ids)));
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

            $hay = SearchText::normalize(implode(' ', [$user->shortName(), $user->getPosition() ?? '', $user->getDepartment()?->getName() ?? '']));

            foreach ($terms as $term) {
                if (! str_contains($hay, $term)) {
                    continue 2;
                }
            }

            $out[] = ['user' => $user];
        }

        return $out;
    }
}
