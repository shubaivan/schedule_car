<?php

namespace App\Plan\Planfix;

use DateTime;
use Doctrine\DBAL\Connection;
use JsonException;
use RuntimeException;

/**
 * Вивантаження Planfix (tasks.json, actions.json, users.json, projects.json) → staging-таблиці
 * planfix_import_* під ідентифікатором партії. Лише staging: задач і повідомлень Плану
 * тут не зʼявляється — це робить PlanfixApplier (--apply).
 *
 * Повторне розкладання тієї самої партії замінює її staging (якщо партію ще не застосовано).
 *
 * --files: тека з manifest.jsonl (файли, забрані через API) — кожен файл стає в чергу до
 * своєї дії. Дія, що мала лише файл без тексту, стає повідомленням із вкладенням (інакше
 * її пропускають як порожню); файли «задачу створено» і самої задачі — у перше повідомлення.
 */
final readonly class PlanfixStaging
{
    private const int CHUNK = 500;
    private const array STAGING_TABLES = ['planfix_import_action', 'planfix_import_task', 'planfix_import_user', 'planfix_import_file'];

    public function __construct(private Connection $db)
    {
    }

    /**
     * --since: задача лишається, якщо вона відкрита або її остання дія (будь-яка) — не раніше дати.
     * cleanupUnapplied: staging попередніх незастосованих партій (без рядків у карті) прибирається,
     * щоб повні копії вивантаження з персональними даними не накопичувались.
     *
     * @param array{since?: ?DateTime, only?: string, events?: string, includeHidden?: bool, cleanupUnapplied?: bool, files?: ?string} $options
     *
     * @return array<string, int|array<string, int>> лічильники
     */
    public function stage(string $dir, string $batch, array $options = []): array
    {
        $since = $options['since'] ?? null;
        $only = $options['only'] ?? 'all';
        $events = $options['events'] ?? PlanfixMapper::EVENTS_MEANINGFUL;
        $includeHidden = (bool) ($options['includeHidden'] ?? false);

        $tasks = $this->read($dir, 'tasks.json');
        $actions = $this->read($dir, 'actions.json');
        $users = $this->read($dir, 'users.json');
        $projects = [];

        foreach ($this->read($dir, 'projects.json', required: false) as $p) {
            $projects[(int) ($p['id'] ?? 0)] = trim((string) ($p['title'] ?? ''));
        }

        $existing = $this->db->fetchAssociative('SELECT applied_at FROM planfix_import_batch WHERE id = ?', [$batch]);

        if ($existing !== false && $existing['applied_at'] !== null) {
            throw new RuntimeException(sprintf('Партію «%s» уже застосовано — спершу plan:planfix:rollback %1$s.', $batch));
        }

        $manifest = ($options['files'] ?? null) !== null ? PlanfixFileManifest::read((string) $options['files']) : null;
        $numbers = [];
        $owners = [];

        foreach ($tasks as $t) {
            $numbers[(int) $t['id']] = (int) ($t['number'] ?? 0);
            $owners[(int) $t['id']] = (int) ($t['owner']['id'] ?? 0);
        }

        // Перший прохід по діях: остання дія й остання зміна статусу задачі (навіть тих дій,
        // що в чат не підуть), і хто що писав — для ролі людини (співробітник чи контакт).
        $lastAny = [];
        $lastStatus = [];
        $wrote = [];
        $foreignTask = [];

        foreach ($actions as $a) {
            $taskId = (int) ($a['task']['id'] ?? 0);

            if (! isset($numbers[$taskId])) {
                continue;
            }

            $at = PlanfixMapper::date((string) ($a['dateTime'] ?? ''))?->format('Y-m-d H:i:s');
            $type = (string) ($a['type'] ?? '');
            $owner = (int) ($a['owner']['id'] ?? 0);

            if ($at !== null) {
                $lastAny[$taskId] = max($lastAny[$taskId] ?? $at, $at);

                if ($type === 'StatusChanged') {
                    $lastStatus[$taskId] = max($lastStatus[$taskId] ?? $at, $at);
                }
            }

            if ($type === 'Comment' || $type === 'Action') {
                $wrote[$owner] = true;
            }

            if ($owners[$taskId] !== $owner) {
                $foreignTask[$owner] = true;
            }
        }

        $everWorker = [];

        foreach ($tasks as $t) {
            foreach ($t['workers']['users'] ?? [] as $u) {
                $everWorker[(int) $u['id']] = true;
            }
        }

        $ownsTasks = array_flip($owners);
        $sinceText = $since?->format('Y-m-d H:i:s');

        $stats = ['tasks_in_export' => count($tasks), 'actions_in_export' => count($actions), 'tasks' => 0, 'deals' => 0, 'skipped_by_filter' => 0];
        $byStatus = [];
        $taskRows = [];
        $people = [];
        $kept = [];

        foreach ($tasks as $t) {
            $id = (int) $t['id'];
            $kind = PlanfixMapper::kind($t);
            $created = PlanfixMapper::date((string) ($t['createDate'] ?? '')) ?? new DateTime('2000-01-01');

            $raw = trim((string) ($t['status'] ?? ''));
            [$status, $stage] = PlanfixMapper::status($raw);
            $recent = $sinceText === null || ! $status->isClosed() || max($lastAny[$id] ?? '', $created->format('Y-m-d H:i:s')) >= $sinceText;

            if (($only !== 'all' && $only !== $kind) || ! $recent) {
                ++$stats['skipped_by_filter'];
                continue;
            }

            $kept[$id] = true;
            ++$stats[$kind === PlanfixMapper::KIND_DEAL ? 'deals' : 'tasks'];
            $byStatus[$raw . ' → ' . $status->value] = ($byStatus[$raw . ' → ' . $status->value] ?? 0) + 1;

            $fields = is_array($t['customData'] ?? null) ? $t['customData'] : [];
            $members = [];

            foreach ($t['workers']['users'] ?? [] as $u) {
                $members[] = (int) $u['id'];
            }

            $groups = array_values(array_unique(array_map(static fn ($g) => trim((string) ($g['name'] ?? '')), $t['workers']['groups'] ?? [])));
            $owner = (int) ($t['owner']['id'] ?? 0);
            $parent = isset($t['parent']['id']) ? (int) $t['parent']['id'] : null;
            $project = isset($t['project']['id']) ? ($projects[(int) $t['project']['id']] ?? null) : null;
            $number = (int) ($t['number'] ?? 0);
            $people[$owner] = true;

            foreach ($members as $m) {
                $people[$m] = true;
            }

            $taskRows[$id] = [
                'batch_id' => $batch,
                'planfix_id' => $id,
                'number' => $number,
                'kind' => $kind,
                'title' => PlanfixMapper::title((string) ($t['title'] ?? ''), $number),
                'description' => PlanfixMapper::description(
                    $number,
                    $kind,
                    $project,
                    $parent !== null ? ($numbers[$parent] ?? null) : null,
                    $created->format('d.m.Y H:i'),
                    PlanfixMapper::fieldLines($fields),
                    (string) ($t['description'] ?? ''),
                ),
                'status_raw' => mb_substr($raw, 0, 80),
                'status' => $status->value,
                'stage' => $stage !== null ? mb_substr($stage, 0, 80) : null,
                'urgent' => (int) ($t['importance'] ?? 0) >= 10,
                'owner_id' => $owner,
                'member_ids' => json_encode(array_values(array_unique(array_filter($members, static fn ($m) => $m !== $owner))), JSON_THROW_ON_ERROR),
                'group_names' => json_encode(array_values(array_filter($groups)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'client' => PlanfixMapper::client($fields),
                'project' => $project !== null ? mb_substr($project, 0, 255) : null,
                'parent_planfix_id' => $parent,
                'due_at' => ($t['hasEndDate'] ?? false) ? PlanfixMapper::date((string) ($t['endTime'] ?? ''))?->format('Y-m-d H:i:s') : null,
                'created_at' => $created->format('Y-m-d H:i:s'),
                'last_at' => $created->format('Y-m-d H:i:s'),
                'closed_at' => $status->isClosed() ? ($lastStatus[$id] ?? null) : null,
            ];
        }

        $actionRows = [];
        $fileRows = [];
        $decisions = [];
        $stats['files_hidden'] = 0;
        $stats['hidden_in_export'] = 0;
        $stats['hidden_skipped'] = 0;

        foreach ($actions as $a) {
            $taskId = (int) ($a['task']['id'] ?? 0);
            $type = (string) ($a['type'] ?? '');

            if (! isset($kept[$taskId])) {
                $decisions['поза задачами (контакти / фільтр)'] = ($decisions['поза задачами (контакти / фільтр)'] ?? 0) + 1;
                continue;
            }

            $hidden = (bool) ($a['hidden'] ?? false);

            if ($hidden) {
                ++$stats['hidden_in_export'];

                // Приховані в Planfix записи — лише з --include-hidden.
                if (! $includeHidden) {
                    ++$stats['hidden_skipped'];
                    $stats['files_hidden'] += count($manifest?->byAction[(int) $a['id']] ?? []);
                    $decisions['пропуск: прихована (hidden)'] = ($decisions['пропуск: прихована (hidden)'] ?? 0) + 1;
                    continue;
                }
            }

            $at = PlanfixMapper::date((string) ($a['dateTime'] ?? '')) ?? new DateTime($taskRows[$taskId]['created_at']);
            [$decision, $eventType, $text] = PlanfixMapper::isNoise($type) && $events !== PlanfixMapper::EVENTS_ALL
                ? [PlanfixMapper::ACTION_SKIP, null, '']
                : PlanfixMapper::action($type, (string) ($a['description'] ?? ''), $events);
            $files = $manifest?->byAction[(int) $a['id']] ?? [];

            if ($files !== []) {
                if ($type === 'TaskCreated') {
                    // Файли, додані при створенні, — у повідомлення «задачу створено».
                    foreach ($files as $f) {
                        $fileRows[] = ['batch_id' => $batch, 'task_planfix_id' => $taskId, 'action_planfix_id' => null] + $f;
                    }
                } else {
                    // Дія з файлом — завжди повідомлення: порожня стає вкладенням без тексту,
                    // позначку «файл не перенесено» прибираємо — файл тепер поруч.
                    $text = trim(str_replace(PlanfixMapper::FILE_NOTE, '', $decision === PlanfixMapper::ACTION_SKIP ? '' : $text));
                    [$decision, $eventType] = [PlanfixMapper::ACTION_COMMENT, null];

                    foreach ($files as $f) {
                        $fileRows[] = ['batch_id' => $batch, 'task_planfix_id' => $taskId, 'action_planfix_id' => (int) $a['id']] + $f;
                    }
                }
            }

            $key = $decision === PlanfixMapper::ACTION_SKIP ? 'пропуск: ' . $type : $decision . ': ' . $type;
            $decisions[$key] = ($decisions[$key] ?? 0) + 1;

            if ($decision === PlanfixMapper::ACTION_SKIP) {
                continue;
            }

            $owner = (int) ($a['owner']['id'] ?? 0);
            $people[$owner] = true;
            $atText = $at->format('Y-m-d H:i:s');

            if ($atText > $taskRows[$taskId]['last_at']) {
                $taskRows[$taskId]['last_at'] = $atText;
            }

            $actionRows[] = [
                'batch_id' => $batch,
                'planfix_id' => (int) $a['id'],
                'task_planfix_id' => $taskId,
                'type' => mb_substr($type, 0, 40),
                'at' => $atText,
                'owner_id' => $owner,
                'decision' => $decision,
                'event_type' => $eventType,
                'text' => $text,
                'hidden' => $hidden,
            ];
        }

        if ($manifest !== null) {
            foreach (array_keys($kept) as $taskId) {
                foreach ($manifest->byTask[$numbers[$taskId]] ?? [] as $f) {
                    $fileRows[] = ['batch_id' => $batch, 'task_planfix_id' => $taskId, 'action_planfix_id' => null] + $f;
                }
            }

            $byTaskTotal = array_sum(array_map('count', $manifest->byTask));
            $byActionTotal = array_sum(array_map('count', $manifest->byAction));
            $notInExport = $this->notInExport($manifest, $actions);
            $stats['files_staged'] = count($fileRows);
            $stats['files_bytes'] = array_sum(array_column($fileRows, 'size'));
            $stats['files_not_in_export'] = $notInExport;
            // Решта — задачі, відсіяні фільтром (--since/--only), чи задачі, яких немає у вивантаженні.
            $stats['files_skipped'] = $byTaskTotal + $byActionTotal - $stats['files_staged'] - $stats['files_hidden'] - $notInExport;
            $stats['files_missing'] = $manifest->missing;
            $stats['files_rejected'] = $manifest->rejected;
        }

        $active = [];

        foreach ($users as $u) {
            $active[(int) $u['id']] = trim(sprintf('%s %s', $u['name'] ?? '', $u['lastName'] ?? ''));
        }

        $userRows = [];

        foreach (array_keys($people) as $pid) {
            if ($pid === 0) {
                continue;
            }

            $role = PlanfixMapper::personRole(isset($active[$pid]), isset($everWorker[$pid]), isset($wrote[$pid]), ! isset($foreignTask[$pid]), isset($ownsTasks[$pid]));
            $userRows[] = ['batch_id' => $batch, 'planfix_id' => $pid, 'name' => mb_substr($active[$pid] ?? '', 0, 255), 'active' => isset($active[$pid]), 'role' => $role];
        }

        $this->db->transactional(function () use ($batch, $dir, $options, $taskRows, $actionRows, $userRows, $fileRows): void {
            if ($options['cleanupUnapplied'] ?? false) {
                $stale = $this->db->fetchFirstColumn(
                    'SELECT b.id FROM planfix_import_batch b WHERE b.applied_at IS NULL AND b.id <> ?
                       AND NOT EXISTS (SELECT 1 FROM planfix_import_map x WHERE x.batch_id = b.id)',
                    [$batch],
                );

                foreach ($stale as $old) {
                    self::purge($this->db, (string) $old);
                    $this->db->executeStatement('DELETE FROM planfix_import_batch WHERE id = ?', [$old]);
                }
            }

            foreach (self::STAGING_TABLES as $table) {
                $this->db->executeStatement("DELETE FROM {$table} WHERE batch_id = ?", [$batch]);
            }

            $this->db->executeStatement(
                'INSERT INTO planfix_import_batch (id, source, options, staged_at) VALUES (?, ?, ?, LOCALTIMESTAMP(0))
                 ON CONFLICT (id) DO UPDATE SET source = EXCLUDED.source, options = EXCLUDED.options, staged_at = EXCLUDED.staged_at, rolled_back_at = NULL',
                [$batch, $dir, json_encode([
                    'since' => isset($options['since']) ? $options['since']->format('Y-m-d') : null,
                    'only' => $options['only'] ?? 'all',
                    'events' => $options['events'] ?? PlanfixMapper::EVENTS_MEANINGFUL,
                    'includeHidden' => (bool) ($options['includeHidden'] ?? false),
                    'files' => $options['files'] ?? null,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
            );
            $this->insertMany('planfix_import_task', array_values($taskRows));
            $this->insertMany('planfix_import_action', $actionRows);
            $this->insertMany('planfix_import_user', $userRows);
            $this->insertMany('planfix_import_file', $fileRows);
        });

        ksort($byStatus);
        ksort($decisions);
        $stats['actions_staged'] = count($actionRows);
        $stats['people'] = count($userRows);
        $stats['people_active'] = count(array_filter($userRows, static fn ($u) => $u['role'] === PlanfixMapper::PERSON_ACTIVE));
        $stats['people_former'] = count(array_filter($userRows, static fn ($u) => $u['role'] === PlanfixMapper::PERSON_FORMER));
        $stats['people_contact'] = count(array_filter($userRows, static fn ($u) => $u['role'] === PlanfixMapper::PERSON_CONTACT));
        $stats['statuses'] = $byStatus;
        $stats['actions'] = $decisions;

        $this->db->executeStatement('UPDATE planfix_import_batch SET stats = ? WHERE id = ?', [json_encode($stats, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $batch]);

        return $stats;
    }

    /**
     * Прибрати персональні дані партії (staging задач, дій, людей). Карта й рядок партії
     * лишаються — на них тримаються ідемпотентність і відкат.
     *
     * @return int видалено рядків
     */
    public static function purge(Connection $db, string $batch): int
    {
        $n = 0;

        foreach (self::STAGING_TABLES as $table) {
            $n += (int) $db->executeStatement("DELETE FROM {$table} WHERE batch_id = ?", [$batch]);
        }

        return $n;
    }

    /**
     * Файли дій, яких немає у вивантаженні (додані в Planfix пізніше) — дотягнуться в день переходу.
     *
     * @param list<array<string, mixed>> $actions
     */
    private function notInExport(PlanfixFileManifest $manifest, array $actions): int
    {
        $ids = [];

        foreach ($actions as $a) {
            $ids[(int) $a['id']] = true;
        }

        return array_sum(array_map('count', array_diff_key($manifest->byAction, $ids)));
    }

    /** @return list<array<string, mixed>> */
    private function read(string $dir, string $file, bool $required = true): array
    {
        $path = rtrim($dir, '/') . '/' . $file;

        if (! is_file($path)) {
            if ($required) {
                throw new RuntimeException(sprintf('У теці немає %s.', $file));
            }

            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('%s: не JSON (%s).', $file, $e->getMessage()), 0, $e);
        }

        if (! is_array($data) || ! array_is_list($data)) {
            throw new RuntimeException(sprintf('%s: очікувався список записів.', $file));
        }

        return $data;
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertMany(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $columns = array_keys($chunk[0]);
            $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $params = [];

            foreach ($chunk as $row) {
                foreach ($columns as $c) {
                    $v = $row[$c];
                    $params[] = is_bool($v) ? ($v ? 'true' : 'false') : $v;
                }
            }

            $this->db->executeStatement(
                sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, count($chunk), $placeholders))),
                $params,
            );
        }
    }
}
