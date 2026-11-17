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
 */
final readonly class PlanfixStaging
{
    private const int CHUNK = 500;

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param array{since?: ?DateTime, only?: string, events?: string} $options
     *
     * @return array<string, int|array<string, int>> лічильники
     */
    public function stage(string $dir, string $batch, array $options = []): array
    {
        $since = $options['since'] ?? null;
        $only = $options['only'] ?? 'all';
        $events = $options['events'] ?? PlanfixMapper::EVENTS_MEANINGFUL;

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

        $numbers = [];

        foreach ($tasks as $t) {
            $numbers[(int) $t['id']] = (int) ($t['number'] ?? 0);
        }

        $stats = ['tasks_in_export' => count($tasks), 'actions_in_export' => count($actions), 'tasks' => 0, 'deals' => 0, 'skipped_by_filter' => 0];
        $byStatus = [];
        $taskRows = [];
        $people = [];
        $kept = [];

        foreach ($tasks as $t) {
            $id = (int) $t['id'];
            $kind = PlanfixMapper::kind($t);
            $created = PlanfixMapper::date((string) ($t['createDate'] ?? '')) ?? new DateTime('2000-01-01');

            if (($only !== 'all' && $only !== $kind) || ($since !== null && $created < $since)) {
                ++$stats['skipped_by_filter'];
                continue;
            }

            $kept[$id] = true;
            ++$stats[$kind === PlanfixMapper::KIND_DEAL ? 'deals' : 'tasks'];
            $raw = trim((string) ($t['status'] ?? ''));
            [$status, $stage] = PlanfixMapper::status($raw);
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
            ];
        }

        $actionRows = [];
        $decisions = [];

        foreach ($actions as $a) {
            $taskId = (int) ($a['task']['id'] ?? 0);
            $type = (string) ($a['type'] ?? '');

            if (! isset($kept[$taskId])) {
                $decisions['поза задачами (контакти / фільтр)'] = ($decisions['поза задачами (контакти / фільтр)'] ?? 0) + 1;
                continue;
            }

            $at = PlanfixMapper::date((string) ($a['dateTime'] ?? '')) ?? new DateTime($taskRows[$taskId]['created_at']);
            [$decision, $eventType, $text] = PlanfixMapper::isNoise($type) && $events !== PlanfixMapper::EVENTS_ALL
                ? [PlanfixMapper::ACTION_SKIP, null, '']
                : PlanfixMapper::action($type, (string) ($a['description'] ?? ''), $events);
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
                'hidden' => (bool) ($a['hidden'] ?? false),
            ];
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

            $userRows[] = ['batch_id' => $batch, 'planfix_id' => $pid, 'name' => mb_substr($active[$pid] ?? '', 0, 255), 'active' => isset($active[$pid])];
        }

        $this->db->transactional(function () use ($batch, $dir, $options, $taskRows, $actionRows, $userRows): void {
            foreach (['planfix_import_action', 'planfix_import_task', 'planfix_import_user'] as $table) {
                $this->db->executeStatement("DELETE FROM {$table} WHERE batch_id = ?", [$batch]);
            }

            $this->db->executeStatement(
                'INSERT INTO planfix_import_batch (id, source, options, staged_at) VALUES (?, ?, ?, LOCALTIMESTAMP(0))
                 ON CONFLICT (id) DO UPDATE SET source = EXCLUDED.source, options = EXCLUDED.options, staged_at = EXCLUDED.staged_at, rolled_back_at = NULL',
                [$batch, $dir, json_encode([
                    'since' => isset($options['since']) ? $options['since']->format('Y-m-d') : null,
                    'only' => $options['only'] ?? 'all',
                    'events' => $options['events'] ?? PlanfixMapper::EVENTS_MEANINGFUL,
                ], JSON_THROW_ON_ERROR)],
            );
            $this->insertMany('planfix_import_task', array_values($taskRows));
            $this->insertMany('planfix_import_action', $actionRows);
            $this->insertMany('planfix_import_user', $userRows);
        });

        ksort($byStatus);
        ksort($decisions);
        $stats['actions_staged'] = count($actionRows);
        $stats['people'] = count($userRows);
        $stats['people_active'] = count(array_filter($userRows, static fn ($u) => $u['active']));
        $stats['statuses'] = $byStatus;
        $stats['actions'] = $decisions;

        $this->db->executeStatement('UPDATE planfix_import_batch SET stats = ? WHERE id = ?', [json_encode($stats, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $batch]);

        return $stats;
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
