<?php

namespace App\Plan\Search;

use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\Unit;
use App\Tasks\Enum\WorkRole;
use App\Warehouse\Enum\MovementType;
use Doctrine\DBAL\Connection;

/**
 * Веде search_document — пошуковий індекс «Плану».
 *
 * Кожне джерело (задачі, коментарі, файли, заявки, склад, люди) — один
 * SELECT, що будує рядки індексу прямо з таблиць модулів. Той самий SELECT
 * працює і для бекфілу (`plan:search:reindex`, без обмеження), і для живого
 * оновлення одного суб'єкта (SearchIndexConsumer: `WHERE subject = :sid`):
 *
 *     INSERT … SELECT … ON CONFLICT (doc_type, ref_id) DO UPDATE … WHERE змінилось
 *     DELETE рядки цього джерела, яких у SELECT більше немає
 *
 * Тому проєкція ідемпотентна (повторний прогін нічого не змінює) і не залежить
 * від того, що саме сталося: подія лише каже, ЯКИЙ суб'єкт перечитати.
 * Дочірні документи (коментарі, файли) успадковують статус, контрагента й
 * видимість батька — тож зміна учасників задачі одразу ховає/відкриває і її
 * коментарі.
 */
class SearchProjector
{
    /** Синтетичні документи `plan:search:bench` — ref_id від цього числа; бекфіл їх не чіпає. */
    public const int BENCH_REF_BASE = 1_500_000_000;

    private const array COLUMNS = [
        'doc_type', 'subject_type', 'subject_id', 'ref_id', 'title', 'body', 'client', 'search_text',
        'author_id', 'manager_id', 'executor_ids', 'department_ids', 'status',
        'visible_all', 'visible_to', 'capability', 'created_at', 'updated_at',
    ];

    /** @var array<string, array{subject: list<string>, scope: string, sql: string, ref?: string}>|null */
    private ?array $sources = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<string> типи документів, які вміє будувати проєктор */
    public function docTypes(): array
    {
        return array_keys($this->sources());
    }

    /**
     * Повний прогін (бекфіл, відновлення). $docTypes = null — усі.
     *
     * @param list<string>|null $docTypes
     *
     * @return array<string, array{upserted: int, deleted: int}>
     */
    public function reindex(?array $docTypes = null): array
    {
        $out = [];

        foreach ($this->sources() as $docType => $source) {
            if ($docTypes !== null && ! in_array($docType, $docTypes, true)) {
                continue;
            }

            $out[$docType] = $this->db->transactional(fn () => [
                'upserted' => $this->upsert($docType, $source['sql'], '', []),
                'deleted' => $this->prune($docType, $source['sql'], '', []),
            ]);
        }

        return $out;
    }

    /**
     * Скільки документів кожного типу дав би повний прогін і скільки зараз в індексі —
     * для `plan:search:reindex --dry-run`.
     *
     * @return array<string, array{source: int, indexed: int}>
     */
    public function stats(): array
    {
        $out = [];

        foreach ($this->sources() as $docType => $source) {
            $out[$docType] = [
                'source' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM (' . $this->scoped($source['sql'], '') . ') s'),
                'indexed' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM search_document WHERE doc_type = ? AND ref_id < ?', [$docType, self::BENCH_REF_BASE]),
            ];
        }

        return $out;
    }

    /**
     * Перечитати один суб'єкт і всі його документи (задача + коментарі + файли,
     * заявка + коментарі + файли, позиція складу + документи…).
     *
     * @return int скільки рядків індексу змінилось (вставлено, оновлено, видалено)
     */
    public function projectSubject(SubjectRef $ref): int
    {
        // Окрема транзакція (або savepoint усередині чужої): збій індексу не отруює
        // зовнішню транзакцію PostgreSQL і не лишає суб'єкт напівоновленим.
        return $this->db->transactional(function () use ($ref): int {
            $this->lock($ref);

            return $this->project($ref);
        });
    }

    /**
     * Лише окремі дочірні документи суб'єкта (новий/змінений/видалений коментар, нові
     * файли) і сам суб'єкт (його «оновлено») — без перечитування всієї стрічки задачі.
     *
     * @param list<int> $refIds id коментарів / файлів
     */
    public function projectChildren(SubjectRef $ref, string $docType, array $refIds): int
    {
        $source = $this->sources()[$docType] ?? null;
        $refIds = array_values(array_unique(array_filter(array_map('intval', $refIds), static fn (int $id) => $id > 0)));

        if ($source === null || ! isset($source['ref']) || $refIds === [] || ! in_array($ref->type->value, $source['subject'], true)) {
            return $this->projectSubject($ref);
        }

        return $this->db->transactional(function () use ($ref, $docType, $source, $refIds): int {
            $this->lock($ref);
            $changed = $this->project($ref, only: $ref->type->value);
            $scope = $source['scope'] . ' AND ' . $source['ref'] . ' = ANY(CAST(:rids AS int[]))';
            $params = ['sid' => $ref->id, 'stype' => $ref->type->value, 'rids' => '{' . implode(',', $refIds) . '}'];
            $changed += $this->upsert($docType, $source['sql'], $scope, $params);

            return $changed + $this->prune($docType, $source['sql'], $scope, $params, refScoped: true);
        });
    }

    /** Дві одночасні події одного суб'єкта не перечитують його навперейми (і без взаємоблокувань). */
    private function lock(SubjectRef $ref): void
    {
        $this->db->executeStatement('SELECT pg_advisory_xact_lock(hashtext(?), ?)', [$ref->type->value, $ref->id]);
    }

    /** @param string|null $only лише документ цього типу (сам суб'єкт) */
    private function project(SubjectRef $ref, ?string $only = null): int
    {
        $changed = 0;

        foreach ($this->sources() as $docType => $source) {
            if (! in_array($ref->type->value, $source['subject'], true) || ($only !== null && $docType !== $only)) {
                continue;
            }

            $params = ['sid' => $ref->id, 'stype' => $ref->type->value];
            $changed += $this->upsert($docType, $source['sql'], $source['scope'], $params);
            $changed += $this->prune($docType, $source['sql'], $source['scope'], $params);
        }

        return $changed;
    }

    /**
     * Рух складу переносить позиції між об'єктами — у їхніх документах змінюється
     * «де зараз» і контрагент. Об'єкт/клієнт перейменували — теж.
     *
     * @param list<int> $itemIds
     */
    public function projectWarehouseDependents(SubjectRef $ref, array $itemIds = []): int
    {
        $changed = 0;
        $ids = $itemIds;

        $sites = [];
        $movements = [];

        if ($ref->type === SubjectType::WhSite) {
            $sites = [$ref->id];
        } elseif ($ref->type === SubjectType::WhClient) {
            $sites = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM wh_site WHERE client_id = ?', [$ref->id]));

            foreach ($sites as $siteId) {
                $changed += $this->projectSubject(SubjectRef::of(SubjectType::WhSite, $siteId));
            }
        } elseif ($ref->type === SubjectType::WhItem) {
            // Назва/інв. номер позиції — у текстах рухів, де вона є.
            $movements = array_map('intval', $this->db->fetchFirstColumn('SELECT DISTINCT movement_id FROM wh_movement_line WHERE item_id = ?', [$ref->id]));
        }

        if ($sites !== []) {
            $list = '{' . implode(',', $sites) . '}';
            array_push($ids, ...array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM wh_item WHERE current_site_id = ANY(CAST(? AS int[]))', [$list])));
            // Рухи на/з цих об'єктів показують їхні назви й клієнтів.
            array_push($movements, ...array_map('intval', $this->db->fetchFirstColumn(
                'SELECT id FROM wh_movement WHERE from_site_id = ANY(CAST(? AS int[])) OR to_site_id = ANY(CAST(? AS int[]))',
                [$list, $list],
            )));
        }

        foreach (array_values(array_unique(array_filter($ids, static fn (int $id) => $id > 0))) as $id) {
            $changed += $this->projectSubject(SubjectRef::of(SubjectType::WhItem, $id));
        }

        foreach (array_values(array_unique($movements)) as $id) {
            if (! ($ref->type === SubjectType::WhMovement && $ref->id === $id)) {
                $changed += $this->projectSubject(SubjectRef::of(SubjectType::WhMovement, $id));
            }
        }

        return $changed;
    }

    /** @param array<string, int|string> $params */
    private function upsert(string $docType, string $sql, string $scope, array $params): int
    {
        $cols = implode(', ', self::COLUMNS);
        $set = implode(', ', array_map(static fn (string $c) => "{$c} = EXCLUDED.{$c}", array_slice(self::COLUMNS, 1)));
        $current = implode(', ', array_map(static fn (string $c) => 'search_document.' . $c, array_slice(self::COLUMNS, 1)));
        $next = implode(', ', array_map(static fn (string $c) => 'EXCLUDED.' . $c, array_slice(self::COLUMNS, 1)));

        return (int) $this->db->executeStatement(
            "INSERT INTO search_document (id, {$cols})
             SELECT nextval('search_document_id_seq'), s.doc_type, s.subject_type, s.subject_id, s.ref_id,
                    s.title, s.body, s.client, " . SearchText::sql('s.hay') . ',
                    s.author_id, s.manager_id, s.executor_ids, s.department_ids, s.status,
                    s.visible_all, s.visible_to, s.capability, s.created_at, s.updated_at
               FROM (' . $this->scoped($sql, $scope) . ') ' . self::alias() . "
              ORDER BY s.ref_id
             ON CONFLICT (doc_type, ref_id) DO UPDATE SET {$set}
              WHERE ({$current}) IS DISTINCT FROM ({$next})",
            $params,
        );
    }

    /**
     * Рядки цього джерела, яких у SELECT більше немає (видалили коментар, людину в архів…).
     *
     * @param array<string, int|string> $params
     */
    private function prune(string $docType, string $sql, string $scope, array $params, bool $refScoped = false): int
    {
        $where = $scope === '' ? '' : ' AND d.subject_type = :stype AND d.subject_id = :sid';

        if ($refScoped) {
            $where .= ' AND d.ref_id = ANY(CAST(:rids AS int[]))';
        }

        return (int) $this->db->executeStatement(
            'DELETE FROM search_document d
              WHERE d.doc_type = :docType AND d.ref_id < :bench' . $where . '
                AND d.ref_id NOT IN (SELECT s.ref_id FROM (' . $this->scoped($sql, $scope) . ') ' . self::alias() . ')',
            [...$params, 'docType' => $docType, 'bench' => self::BENCH_REF_BASE],
        );
    }

    /** Імена колонок джерела задаємо тут, а не в кожному SELECT (там вони позиційні). */
    private static function alias(): string
    {
        return 's (doc_type, subject_type, subject_id, ref_id, title, body, client, hay, author_id, manager_id, executor_ids, department_ids, status, visible_all, visible_to, capability, created_at, updated_at)';
    }

    private function scoped(string $sql, string $scope): string
    {
        return str_replace('{scope}', $scope === '' ? '' : ' AND ' . $scope, $sql);
    }

    /**
     * Джерела індексу. Кожен SELECT повертає колонки: doc_type, subject_type,
     * subject_id, ref_id, title, body, client, hay (те, по чому шукати),
     * author_id, manager_id, executor_ids, department_ids, status, visible_all,
     * visible_to, capability, created_at, updated_at. `{scope}` — місце для
     * умови «лише цей суб'єкт».
     *
     * @return array<string, array{subject: list<string>, scope: string, sql: string, ref?: string}>
     */
    private function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        // --- задачі: видно автору й учасникам (директор/адмін — усе, умова в запиті).
        $taskVisible = "(SELECT COALESCE(jsonb_agg(DISTINCT x.u ORDER BY x.u), '[]') FROM (SELECT t.creator_id AS u UNION SELECT tm.user_id FROM task_member tm WHERE tm.task_id = t.id) x)";
        $taskExecutors = "(SELECT COALESCE(jsonb_agg(DISTINCT tm.user_id ORDER BY tm.user_id), '[]') FROM task_member tm WHERE tm.task_id = t.id AND tm.user_id <> t.creator_id)";
        $taskDepartments = "(SELECT COALESCE(jsonb_agg(DISTINCT td.department_id ORDER BY td.department_id), '[]') FROM task_department td WHERE td.task_id = t.id)";
        $taskTitle = "'№ ' || lpad(t.id::text, 4, '0') || ' · ' || t.title";
        $taskInherited = "t.creator_id, {$taskExecutors}, {$taskDepartments}, 'task:' || t.status, false, {$taskVisible}, NULL::varchar";

        // --- заявки: відкриті всім у CRM (рішення клієнта 13.08).
        $supplyTitle = "'№ ' || r.number || ' · ' || r.item";
        $supplyManager = '(SELECT l.author_id FROM supply_status_log l WHERE l.request_id = r.id AND l.author_id IS DISTINCT FROM r.author_id ORDER BY l.id DESC LIMIT 1)';
        $supplyExecutors = "(SELECT COALESCE(jsonb_agg(DISTINCT x.u ORDER BY x.u), '[]') FROM (
                SELECT l.author_id AS u FROM supply_status_log l WHERE l.request_id = r.id
                UNION SELECT p.created_by_id FROM supply_purchase p WHERE p.request_id = r.id) x
              WHERE x.u IS NOT NULL AND x.u IS DISTINCT FROM r.author_id)";
        $supplyDepartments = "CASE WHEN r.department_id IS NULL THEN '[]'::jsonb ELSE jsonb_build_array(r.department_id) END";
        $supplySuppliers = "(SELECT string_agg(DISTINCT ss.name, ', ') FROM supply_purchase p JOIN supply_supplier ss ON ss.id = p.supplier_id WHERE p.request_id = r.id)";
        $supplyClient = "COALESCE(concat_ws(', ', NULLIF(r.site, ''), {$supplySuppliers}), '')";
        $supplyInherited = "{$supplyManager}, {$supplyExecutors}, {$supplyDepartments}, 'supply:' || r.status, true, '[]'::jsonb, NULL::varchar";

        $movementLabel = SearchCatalog::caseSql('m.type', MovementType::cases());
        $unitLabel = SearchCatalog::caseSql('r.unit', Unit::cases());
        $roleCase = 'COALESCE(u.work_role, CASE u.supply_role';

        foreach (['admin', 'director', 'manager'] as $role) {
            $roleCase .= sprintf(" WHEN '%s' THEN '%s'", $role, WorkRole::fromSupply(SupplyRole::from($role))->value);
        }

        $roleCase .= " ELSE '" . WorkRole::Production->value . "' END)";
        $roleLabel = 'CASE ' . $roleCase;

        foreach (WorkRole::cases() as $case) {
            $roleLabel .= sprintf(" WHEN '%s' THEN '%s'", $case->value, $case->label());
        }

        $roleLabel .= " ELSE '' END";
        $userName = "COALESCE(NULLIF(btrim(concat_ws(' ', u.first_name, u.last_name)), ''), '@' || NULLIF(u.username, ''), u.phone_number, '—')";
        $warehouse = "'warehouse.view'";

        return $this->sources = [
            'task' => [
                'subject' => ['task'],
                'scope' => 't.id = :sid',
                'sql' => "SELECT 'task' AS doc_type, 'task' AS subject_type, t.id AS subject_id, t.id AS ref_id,
                        {$taskTitle} AS title, COALESCE(t.description, '') AS body, COALESCE(t.client, '') AS client,
                        concat_ws(' ', t.title, t.client, t.description, lpad(t.id::text, 4, '0')) AS hay,
                        t.creator_id AS author_id, {$taskInherited}, t.created_at, t.last_activity_at
                   FROM task t WHERE TRUE {scope}",
            ],
            'comment' => [
                'subject' => ['task'],
                'scope' => 'm.task_id = :sid',
                'ref' => 'm.id',
                'sql' => "SELECT 'comment', 'task', m.task_id, m.id, {$taskTitle}, m.text, COALESCE(t.client, ''), m.text,
                        m.author_id, {$taskInherited}, m.created_at, COALESCE(m.edited_at, m.created_at)
                   FROM task_message m JOIN task t ON t.id = m.task_id
                  WHERE m.kind = 'comment' AND m.deleted_at IS NULL AND btrim(m.text) <> '' {scope}",
            ],
            'file' => [
                'subject' => ['task'],
                'scope' => 'm.task_id = :sid',
                'ref' => 'a.id',
                'sql' => "SELECT 'file', 'task', m.task_id, a.id, a.original_name, {$taskTitle}, COALESCE(t.client, ''), a.original_name,
                        a.uploaded_by_id, {$taskInherited}, a.created_at, a.created_at
                   FROM task_attachment a JOIN task_message m ON m.id = a.message_id JOIN task t ON t.id = m.task_id
                  WHERE m.deleted_at IS NULL {scope}",
            ],
            'supply' => [
                'subject' => ['supply'],
                'scope' => 'r.id = :sid',
                'sql' => "SELECT 'supply', 'supply', r.id, r.id, {$supplyTitle},
                        concat_ws(' · ', rtrim(rtrim(r.quantity::text, '0'), '.') || ' ' || {$unitLabel}, NULLIF(r.site, ''), NULLIF(r.note, '')),
                        {$supplyClient},
                        concat_ws(' ', r.number, replace(r.number, '/', '-'), r.item, r.site, r.note, {$supplySuppliers},
                            (SELECT string_agg(p.invoice_number, ' ') FROM supply_purchase p WHERE p.request_id = r.id)),
                        r.author_id, {$supplyInherited}, r.created_at,
                        GREATEST(r.updated_at, (SELECT MAX(c.created_at) FROM supply_comment c WHERE c.request_id = r.id))
                   FROM supply_request r WHERE TRUE {scope}",
            ],
            'supply_comment' => [
                'subject' => ['supply'],
                'scope' => 'c.request_id = :sid',
                'ref' => 'c.id',
                'sql' => "SELECT 'supply_comment', 'supply', c.request_id, c.id, {$supplyTitle}, c.text, {$supplyClient}, c.text,
                        c.author_id, {$supplyInherited}, c.created_at, c.created_at
                   FROM supply_comment c JOIN supply_request r ON r.id = c.request_id
                  WHERE btrim(c.text) <> '' {scope}",
            ],
            'supply_file' => [
                'subject' => ['supply'],
                'scope' => 'a.request_id = :sid',
                'ref' => 'a.id',
                'sql' => "SELECT 'supply_file', 'supply', a.request_id, a.id, a.original_name, {$supplyTitle}, {$supplyClient}, a.original_name,
                        a.uploaded_by_id, {$supplyInherited}, a.created_at, a.created_at
                   FROM supply_attachment a JOIN supply_request r ON r.id = a.request_id WHERE TRUE {scope}",
            ],
            'wh_item' => [
                'subject' => ['wh_item'],
                'scope' => 'i.id = :sid',
                'sql' => "SELECT 'wh_item', 'wh_item', i.id, i.id, concat_ws(' · ', i.inventory_number, i.name),
                        concat_ws(' · ', cat.name, s.name, NULLIF(i.serial_number, ''), NULLIF(i.description, '')),
                        COALESCE(c.name, ''),
                        concat_ws(' ', i.inventory_number, i.name, i.serial_number, i.description, cat.name, s.name, c.name, sup.name),
                        i.created_by_id, NULL::int, '[]'::jsonb, '[]'::jsonb, 'wh_item:' || i.state, true, '[]'::jsonb, {$warehouse},
                        i.created_at, i.updated_at
                   FROM wh_item i
                   LEFT JOIN wh_category cat ON cat.id = i.category_id
                   LEFT JOIN wh_site s ON s.id = i.current_site_id
                   LEFT JOIN wh_client c ON c.id = s.client_id
                   LEFT JOIN supply_supplier sup ON sup.id = i.supplier_id
                  WHERE TRUE {scope}",
            ],
            'wh_movement' => [
                'subject' => ['wh_movement'],
                'scope' => 'm.id = :sid',
                'sql' => "SELECT 'wh_movement', 'wh_movement', m.id, m.id,
                        concat_ws(' · ', {$movementLabel} || COALESCE(' № ' || NULLIF(m.document_number, ''), ''), to_char(m.occurred_at, 'DD.MM.YYYY')),
                        concat_ws(' · ', lines.names, NULLIF(concat_ws(' → ', fs.name, ts.name), ''), NULLIF(m.note, '')),
                        COALESCE(tc.name, fc.name, ''),
                        concat_ws(' ', {$movementLabel}, m.document_number, m.note, fs.name, ts.name, fc.name, tc.name, sup.name, lines.names),
                        m.created_by_id, m.created_by_id, '[]'::jsonb, '[]'::jsonb, 'wh_movement:' || m.type, true, '[]'::jsonb, {$warehouse},
                        m.created_at, m.updated_at
                   FROM wh_movement m
                   LEFT JOIN wh_site fs ON fs.id = m.from_site_id
                   LEFT JOIN wh_site ts ON ts.id = m.to_site_id
                   LEFT JOIN wh_client fc ON fc.id = fs.client_id
                   LEFT JOIN wh_client tc ON tc.id = ts.client_id
                   LEFT JOIN supply_supplier sup ON sup.id = m.supplier_id
                   LEFT JOIN LATERAL (
                        SELECT string_agg(concat_ws(' ', i.inventory_number, i.name, CASE WHEN l.quantity > 1 THEN '×' || l.quantity END), ', ' ORDER BY l.id) AS names
                          FROM wh_movement_line l JOIN wh_item i ON i.id = l.item_id WHERE l.movement_id = m.id
                   ) lines ON TRUE
                  WHERE TRUE {scope}",
            ],
            'wh_client' => [
                'subject' => ['wh_client'],
                'scope' => 'c.id = :sid',
                'sql' => "SELECT 'wh_client', 'wh_client', c.id, c.id, c.name,
                        concat_ws(' · ', 'ЄДРПОУ ' || NULLIF(c.edrpou, ''), NULLIF(c.phone, ''), NULLIF(c.contact_person, ''), NULLIF(c.note, '')),
                        c.name, concat_ws(' ', c.name, c.edrpou, c.phone, c.contact_person, c.note),
                        NULL::int, NULL::int, '[]'::jsonb, '[]'::jsonb, NULL::varchar, true, '[]'::jsonb, {$warehouse},
                        c.created_at, c.updated_at
                   FROM wh_client c WHERE TRUE {scope}",
            ],
            'wh_site' => [
                'subject' => ['wh_site'],
                'scope' => 's.id = :sid',
                'sql' => "SELECT 'wh_site', 'wh_site', s.id, s.id, s.name,
                        concat_ws(' · ', NULLIF(s.address, ''), NULLIF(s.note, '')),
                        COALESCE(c.name, ''), concat_ws(' ', s.name, s.address, s.note, c.name),
                        NULL::int, NULL::int, '[]'::jsonb, '[]'::jsonb, NULL::varchar, true, '[]'::jsonb, {$warehouse},
                        s.created_at, s.updated_at
                   FROM wh_site s LEFT JOIN wh_client c ON c.id = s.client_id WHERE TRUE {scope}",
            ],
            'wh_document' => [
                'subject' => ['wh_item', 'wh_movement', 'wh_site', 'wh_client'],
                'scope' => "(CASE WHEN d.item_id IS NOT NULL THEN 'wh_item' WHEN d.movement_id IS NOT NULL THEN 'wh_movement' WHEN d.site_id IS NOT NULL THEN 'wh_site' ELSE 'wh_client' END) = :stype
                    AND COALESCE(d.item_id, d.movement_id, d.site_id, d.client_id) = :sid",
                'sql' => "SELECT 'wh_document',
                        CASE WHEN d.item_id IS NOT NULL THEN 'wh_item' WHEN d.movement_id IS NOT NULL THEN 'wh_movement' WHEN d.site_id IS NOT NULL THEN 'wh_site' ELSE 'wh_client' END,
                        COALESCE(d.item_id, d.movement_id, d.site_id, d.client_id), d.id, d.original_name,
                        COALESCE(concat_ws(' · ', i.inventory_number, i.name), s.name, c.name, ''),
                        COALESCE(c.name, sc.name, ''), concat_ws(' ', d.original_name, i.name, s.name, c.name),
                        d.uploaded_by_id, NULL::int, '[]'::jsonb, '[]'::jsonb, NULL::varchar, true, '[]'::jsonb, {$warehouse},
                        d.created_at, d.updated_at
                   FROM wh_document d
                   LEFT JOIN wh_item i ON i.id = d.item_id
                   LEFT JOIN wh_site s ON s.id = d.site_id
                   LEFT JOIN wh_client c ON c.id = d.client_id
                   LEFT JOIN wh_client sc ON sc.id = s.client_id
                  WHERE COALESCE(d.item_id, d.movement_id, d.site_id, d.client_id) IS NOT NULL {scope}",
            ],
            'person' => [
                'subject' => ['user'],
                'scope' => 'u.id = :sid',
                'sql' => "SELECT 'person', 'user', u.id, u.id, {$userName},
                        concat_ws(' · ', NULLIF(u.position, ''), dep.name, NULLIF({$roleLabel}, COALESCE(u.position, ''))),
                        '', concat_ws(' ', {$userName}, u.username, u.position, dep.name, u.email, {$roleLabel}),
                        NULL::int, NULL::int, '[]'::jsonb,
                        CASE WHEN u.department_id IS NULL THEN '[]'::jsonb ELSE jsonb_build_array(u.department_id) END,
                        'person:' || {$roleCase}, true, '[]'::jsonb, NULL::varchar,
                        u.created_at, COALESCE(u.updated_at, u.created_at)
                   FROM telegram_user u LEFT JOIN supply_department dep ON dep.id = u.department_id
                  WHERE u.access_status = 'approved' AND u.archived_at IS NULL {scope}",
            ],
        ];
    }
}
