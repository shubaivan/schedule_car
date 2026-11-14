<?php

namespace App\Plan\Search;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanAccess;
use App\Plan\Access\PlanCapability;
use App\Warehouse\Service\WarehouseSection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Пошук «Плану» по search_document: повний текст + фільтри + фасети одним
 * проходом по таблиці.
 *
 * - Збіг: КОЖНЕ слово має знайтися «за частиною слова» (search_text LIKE '%w%',
 *   GIN pg_trgm). Ранг — збіг у заголовку, слово, що починається з набраного,
 *   документ-суб'єкт вище за його коментар; далі — новіші. (ts_rank відмовились:
 *   розбір tsvector на кожному збігу з'їдав більшу частину бюджету 150 мс.)
 * - Видимість — у самому SQL, до фільтрів і фасетів (лічильники не видають
 *   чужого): документ або відкритий усім (`visible_all`), або людина є в
 *   `visible_to` (задачі: автор + учасники), директор/адмін бачать усі задачі;
 *   склад — лише з можливістю warehouse.view (і коли склад увімкнено).
 * - Фасети (тип, статус, автор, виконавець, відділ, контрагент): кожен рахується
 *   з УСІМА фільтрами, крім власного, — щоб було видно, скільки дасть інший вибір.
 *   Беруться з не більше ніж FACET_CAP збігів (тоді `approximate: true`, «2000+»).
 */
class PlanSearch
{
    public const int FACET_CAP = 2000;
    private const int TOP = 12;

    public function __construct(
        private readonly Connection $db,
        private readonly PlanAccess $access,
        private readonly WarehouseSection $warehouse,
    ) {
    }

    /**
     * @param bool $bench лише для plan:search:bench — шукати й серед синтетичних документів
     *
     * @return array{
     *     total: int,
     *     approximate: bool,
     *     hits: list<array<string, mixed>>,
     *     facets: array{type: array<string, int>, docType: array<string, int>, status: array<string, int>, author: array<int, int>, executor: array<int, int>, department: array<int, int>, client: array<string, int>},
     *     more: bool
     * }
     */
    public function search(TelegramUser $viewer, SearchQuery $query, bool $bench = false): array
    {
        $sql = $this->build($viewer, $query, $bench);
        $facets = $this->parseFacets($this->db->fetchAllAssociative($sql['facets'], $sql['params'], $sql['types']));

        if ($facets['approximate']) {
            // Збігів більше, ніж беруть фасети, — сторінку рахуємо по всій таблиці. Кількість:
            // без фільтрів-фасетів — «2000+» (і так ясно, що варто уточнити запит); з ними —
            // точно, бо обрізаний набір міг відкинути саме ті рядки, що проходять фільтр.
            $rows = $this->rowsByIds(array_map('intval', $this->db->fetchFirstColumn($sql['hits'], $sql['params'], $sql['types'])));

            if ($sql['flagged']) {
                $facets['total'] = (int) $this->db->fetchOne($sql['count'], $sql['params'], $sql['types']);
                $facets['approximate'] = false;
            } else {
                $facets['total'] = self::FACET_CAP;
            }
        } else {
            // Усі збіги вже в наборі фасетів — сторінку взято з нього ж (id по порядку), без другого проходу.
            $rows = $this->rowsByIds($facets['page']);
        }

        return [
            'total' => $facets['total'],
            'approximate' => $facets['approximate'],
            'hits' => array_slice($rows, 0, $query->limit),
            'facets' => $facets['facets'],
            'more' => count($rows) > $query->limit,
        ];
    }

    /**
     * EXPLAIN (ANALYZE, BUFFERS) запитів пошуку — для plan:search:bench.
     *
     * @return array{facets: string, hits: string}
     */
    public function explain(TelegramUser $viewer, SearchQuery $query, bool $bench = false): array
    {
        $sql = $this->build($viewer, $query, $bench);
        $plan = fn (string $q) => implode("\n", $this->db->fetchFirstColumn('EXPLAIN (ANALYZE, BUFFERS) ' . $q, $sql['params'], $sql['types']));

        return ['facets' => $plan($sql['facets']), 'hits' => $plan($sql['hits'])];
    }

    /**
     * @return array{facets: string, hits: string, count: string, params: array<string, mixed>, types: array<string, ArrayParameterType>, flagged: bool}
     */
    private function build(TelegramUser $viewer, SearchQuery $query, bool $bench): array
    {
        $terms = $query->terms();
        $params = [];
        $types = [];
        $base = $this->visibility($viewer, $params, $types, $bench);
        $match = $this->match($terms, $params);

        if ($match !== null) {
            $base[] = $match;
        }

        // Фільтри без власного фасета — частина бази.
        if ($query->person !== null) {
            $params['person'] = $query->person;
            $params['personJson'] = json_encode([$query->person]);
            $base[] = '(d.author_id = :person OR d.manager_id = :person OR d.executor_ids @> CAST(:personJson AS jsonb))';
        }

        if ($query->client !== '') {
            $params['client'] = SearchText::like(mb_strtolower($query->client));
            $base[] = 'lower(d.client) LIKE :client';
        }

        if ($query->from !== null) {
            $params['from'] = $query->from->format('Y-m-d 00:00:00');
            $base[] = 'd.created_at >= CAST(:from AS timestamp)';
        }

        if ($query->to !== null) {
            $params['to'] = $query->to->modify('+1 day')->format('Y-m-d 00:00:00');
            $base[] = 'd.created_at < CAST(:to AS timestamp)';
        }

        if ($query->starred) {
            $params['me'] = (int) $viewer->getId();
            // ★: задача в обраному (і її файли), коментар із зірочкою.
            $base[] = "((d.doc_type IN ('task', 'file') AND EXISTS (SELECT 1 FROM task_favorite f WHERE f.task_id = d.subject_id AND f.user_id = :me))
                OR (d.doc_type = 'comment' AND EXISTS (SELECT 1 FROM task_message_star st WHERE st.message_id = d.ref_id AND st.user_id = :me)))";
        }

        // Фільтри з фасетами — окремими прапорцями: фасет рахується без власного.
        $flags = $this->flags($query, $params, $types);
        $where = implode(' AND ', $base);
        $all = implode(' AND ', array_values($flags) ?: ['TRUE']);
        $rank = $this->rank($terms, $params);
        $order = $terms !== [] && $query->sort === 'relevance' ? 'rank DESC, updated_at DESC, id DESC' : 'updated_at DESC, id DESC';
        $page = ' LIMIT ' . ($query->limit + 1) . ' OFFSET ' . $query->offset;

        return [
            'facets' => $this->facetSql($where, $flags, $rank, $order, $page),
            'hits' => "SELECT x.id FROM (SELECT d.id, d.updated_at, {$rank} AS rank FROM search_document d WHERE {$where} AND {$all}) x ORDER BY {$order}{$page}",
            'count' => "SELECT COUNT(*) FROM search_document d WHERE {$where} AND {$all}",
            'params' => $params,
            'types' => $types,
            'flagged' => $flags !== [],
        ];
    }

    /**
     * Рядки документів за id у заданому порядку.
     *
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function rowsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $byId = [];

        foreach ($this->db->fetchAllAssociative(
            'SELECT d.id, d.doc_type, d.subject_type, d.subject_id, d.ref_id, d.title, d.body, d.client, d.author_id,
                    d.status, d.department_ids, d.created_at, d.updated_at
               FROM search_document d WHERE d.id IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER],
        ) as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $out = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /** Які групи типів людина взагалі може побачити (для фільтра «тип» навіть з нулем збігів). @return list<string> */
    public function groupsFor(TelegramUser $viewer): array
    {
        return array_values(array_filter(
            array_keys(SearchCatalog::GROUPS),
            fn (string $group) => $group !== 'warehouse' || $this->seesWarehouse($viewer),
        ));
    }

    public function seesWarehouse(TelegramUser $viewer): bool
    {
        return $this->warehouse->isEnabled() && $this->access->can($viewer, PlanCapability::WarehouseView);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType> $types
     *
     * @return list<string>
     */
    private function visibility(TelegramUser $viewer, array &$params, array &$types, bool $bench): array
    {
        $caps = [];

        if ($this->seesWarehouse($viewer)) {
            $caps[] = PlanCapability::WarehouseView->value;
        }

        $out = [];

        if ($caps === []) {
            $out[] = 'd.capability IS NULL';
        } else {
            $params['caps'] = $caps;
            $types['caps'] = ArrayParameterType::STRING;
            $out[] = '(d.capability IS NULL OR d.capability IN (:caps))';
        }

        $params['meJson'] = json_encode([(int) $viewer->getId()]);
        $seesAllTasks = $this->access->can($viewer, PlanCapability::TasksSeeAll);
        $out[] = '(d.visible_all OR d.visible_to @> CAST(:meJson AS jsonb)' . ($seesAllTasks ? " OR d.subject_type = 'task'" : '') . ')';

        // Синтетика бенчмарка видна лише бенчмарку.
        if (! $bench) {
            $out[] = 'd.ref_id < ' . SearchProjector::BENCH_REF_BASE;
        }

        return $out;
    }

    /**
     * @param list<string> $terms
     * @param array<string, mixed> $params
     */
    private function match(array $terms, array &$params): ?string
    {
        if ($terms === []) {
            return null;
        }

        $all = [];

        foreach ($terms as $i => $term) {
            $word = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $term);

            if (mb_strlen($term) < 3 && $word !== '') {
                // 1–2 літери: трigrам-індекс тут безсилий (повний перебір таблиці), тож шукаємо
                // слово, що ПОЧИНАЄТЬСЯ з них («пк» → «ПК 60-12»), — через GIN по tsvector.
                $params['t' . $i] = $word . ':*';
                $all[] = "to_tsvector('simple', d.search_text) @@ to_tsquery('simple', :t{$i})";
            } else {
                $params['p' . $i] = SearchText::like($term);
                $all[] = 'd.search_text LIKE :p' . $i;
            }
        }

        $match = '(' . implode(' AND ', $all) . ')';

        // «12», «№ 12», «#0012» — номер задачі.
        if (count($terms) === 1 && preg_match('/^[№#]?0*(\d{1,9})$/u', $terms[0], $m) === 1) {
            $params['num'] = (int) $m[1];
            $match = "({$match} OR (d.doc_type = 'task' AND d.ref_id = :num))";
        }

        return $match;
    }

    /**
     * @param list<string> $terms
     * @param array<string, mixed> $params
     */
    private function rank(array $terms, array &$params): string
    {
        // Суб'єкт (задача, заявка, позиція) — вище за його коментарі й файли.
        $rank = "CASE WHEN d.doc_type IN ('task', 'supply', 'wh_item', 'wh_client', 'person') THEN 0.3 ELSE 0 END";

        if ($terms === []) {
            return $rank;
        }

        // Лише по заголовку (короткий): повний текст на кожному збігу — це вже другий прохід таблицею.
        $title = [];

        foreach ($terms as $i => $term) {
            $params['r' . $i] = SearchText::like($term);
            $params['w' . $i] = ' ' . $term;
            $title[] = 'lower(d.title) LIKE :r' . $i;
            // Слово заголовка починається з набраного («плит» → «Плити ПК») — ще вище.
            $rank .= " + CASE WHEN position(:w{$i} in ' ' || lower(d.title)) > 0 THEN 0.5 ELSE 0 END";
        }

        return $rank . ' + CASE WHEN ' . implode(' AND ', $title) . ' THEN 1 ELSE 0 END';
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType> $types
     *
     * @return array<string, string> фасет → умова (немає фільтра — немає ключа)
     */
    private function flags(SearchQuery $query, array &$params, array &$types): array
    {
        $flags = [];
        $docTypes = SearchCatalog::expand($query->types);

        if ($query->types !== []) {
            // Невідомий тип — нічого не знайдено, а не «усе».
            $params['docTypes'] = $docTypes === [] ? ['-'] : $docTypes;
            $types['docTypes'] = ArrayParameterType::STRING;
            $flags['type'] = 'd.doc_type IN (:docTypes)';
        }

        if ($query->statuses !== []) {
            $params['statuses'] = $query->statuses;
            $types['statuses'] = ArrayParameterType::STRING;
            $flags['status'] = 'd.status IN (:statuses)';
        }

        if ($query->author !== null) {
            $params['author'] = $query->author;
            $flags['author'] = 'd.author_id = :author';
        }

        if ($query->executor !== null) {
            $params['executorJson'] = json_encode([$query->executor]);
            $flags['executor'] = 'd.executor_ids @> CAST(:executorJson AS jsonb)';
        }

        if ($query->manager !== null) {
            $params['manager'] = $query->manager;
            $flags['manager'] = 'd.manager_id = :manager';
        }

        if ($query->departments !== []) {
            $any = [];

            foreach ($query->departments as $i => $id) {
                $params['dep' . $i] = json_encode([$id]);
                $any[] = 'd.department_ids @> CAST(:dep' . $i . ' AS jsonb)';
            }

            $flags['department'] = '(' . implode(' OR ', $any) . ')';
        }

        return $flags;
    }

    /**
     * Усі фасети й загальна кількість — одним запитом по матеріалізованих збігах.
     *
     * Плюс перша сторінка (id по порядку) — якщо всі збіги вмістились у набір, другого
     * проходу таблицею не буде.
     *
     * @param array<string, string> $flags
     */
    private function facetSql(string $where, array $flags, string $rank, string $order, string $page): string
    {
        $names = ['type', 'status', 'author', 'executor', 'manager', 'department'];
        $select = [];

        foreach ($names as $name) {
            $select[] = ($flags[$name] ?? 'TRUE') . ' AS f_' . $name;
        }

        // Умова «усі фільтри, крім $except».
        $except = static function (?string $except) use ($names): string {
            $parts = [];

            foreach ($names as $name) {
                if ($name !== $except) {
                    $parts[] = 'f_' . $name;
                }
            }

            return implode(' AND ', $parts);
        };

        // Рядок, що не проходить два й більше фільтрів-фасетів, не потрапляє ні в один лічильник —
        // відсікаємо його ще до ліміту, щоб ліміт не «з'їдав» потрібні рядки.
        if (count($flags) > 1) {
            $where .= ' AND (' . implode(' + ', array_map(static fn (string $f) => "(CASE WHEN {$f} THEN 0 ELSE 1 END)", $flags)) . ') <= 1';
        }

        return 'WITH m AS MATERIALIZED (
                SELECT d.id, d.updated_at, ' . $rank . ' AS rank, d.doc_type, d.status, d.author_id, d.executor_ids, d.department_ids, d.client, ' . implode(', ', $select) . '
                  FROM search_document d WHERE ' . $where . ' LIMIT ' . (self::FACET_CAP + 1) . "
             )
             SELECT 'total' AS dim, '' AS key, COUNT(*) AS n FROM m WHERE " . $except(null) . "
             UNION ALL SELECT 'all', '', COUNT(*) FROM m
             UNION ALL SELECT 'type', doc_type, COUNT(*) FROM m WHERE " . $except('type') . " GROUP BY doc_type
             UNION ALL SELECT 'status', status, COUNT(*) FROM m WHERE status IS NOT NULL AND " . $except('status') . " GROUP BY status
             UNION ALL SELECT 'author', author_id::text, COUNT(*) FROM m WHERE author_id IS NOT NULL AND " . $except('author') . " GROUP BY author_id
             UNION ALL SELECT 'executor', e.v, COUNT(*) FROM m, jsonb_array_elements_text(m.executor_ids) e(v) WHERE " . $except('executor') . " GROUP BY e.v
             UNION ALL SELECT 'department', e.v, COUNT(*) FROM m, jsonb_array_elements_text(m.department_ids) e(v) WHERE " . $except('department') . " GROUP BY e.v
             UNION ALL SELECT 'client', client, COUNT(*) FROM m WHERE client <> '' AND " . $except(null) . " GROUP BY client
             UNION ALL SELECT 'page', p.id::text, p.n FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY " . $order . ') AS n FROM m WHERE ' . $except(null) . ' ORDER BY n' . $page . ') p';
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{total: int, approximate: bool, page: list<int>, facets: array{type: array<string, int>, docType: array<string, int>, status: array<string, int>, author: array<int, int>, executor: array<int, int>, department: array<int, int>, client: array<string, int>}}
     */
    private function parseFacets(array $rows): array
    {
        $facets = ['type' => [], 'docType' => [], 'status' => [], 'author' => [], 'executor' => [], 'department' => [], 'client' => []];
        $total = 0;
        $seen = 0;
        $page = [];

        foreach ($rows as $row) {
            $n = (int) $row['n'];
            $key = (string) $row['key'];

            switch ($row['dim']) {
                case 'total':
                    $total = $n;
                    break;
                case 'all':
                    $seen = $n;
                    break;
                case 'page':
                    $page[$n] = (int) $key;
                    break;
                case 'type':
                    $facets['docType'][$key] = $n;
                    $group = SearchCatalog::groupOf($key);
                    $facets['type'][$group] = ($facets['type'][$group] ?? 0) + $n;
                    break;
                case 'author':
                case 'executor':
                case 'department':
                    $facets[$row['dim']][(int) $key] = $n;
                    break;
                default:
                    $facets[(string) $row['dim']][$key] = $n;
            }
        }

        foreach (['status', 'author', 'executor', 'department', 'client'] as $dim) {
            arsort($facets[$dim]);
        }

        foreach (['author', 'executor', 'client'] as $dim) {
            $facets[$dim] = array_slice($facets[$dim], 0, self::TOP, true);
        }

        // Порядок фасета «тип» — як у каталозі.
        $ordered = [];

        foreach (array_keys(SearchCatalog::GROUPS) as $group) {
            if (isset($facets['type'][$group])) {
                $ordered[$group] = $facets['type'][$group];
            }
        }

        $facets['type'] = $ordered;

        /** @var array{type: array<string, int>, docType: array<string, int>, status: array<string, int>, author: array<int, int>, executor: array<int, int>, department: array<int, int>, client: array<string, int>} $facets */
        ksort($page);

        return ['total' => $total, 'approximate' => $seen > self::FACET_CAP, 'facets' => $facets, 'page' => array_values($page)];
    }
}
