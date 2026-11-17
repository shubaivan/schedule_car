<?php

namespace App\Plan\Planfix;

use App\Tasks\Entity\TaskMessage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Staging-партія → справжні задачі Плану (task, task_message, task_member, task_department,
 * task_task_label, task_read), кожен створений рядок — у planfix_import_map.
 *
 * Навмисно напряму через DBAL, повз TaskService і ORM: жодної події в plan_event, отже
 * без Telegram/push/дзвіночка (TaskNotificationListener, NotificationFanout), без Mercure,
 * без Хроніки й без черги Диска (task_attachment не створюємо — файлів у вивантаженні
 * немає). Дати — оригінальні з Planfix.
 *
 * Ідемпотентно: задача чи дія, що вже є в карті (з будь-якої партії), вдруге не пишеться;
 * до вже перенесеної задачі додаються лише нові дії. Поля перенесених задач не переписуються.
 */
final readonly class PlanfixApplier
{
    public const string PLACEHOLDER_PREFIX = 'planfix-';
    public const string PLACEHOLDER_NAME = 'Колишній співробітник';
    /** Службові облікові записи Planfix (роботи, сценарії) — один автор «Planfix (система)». */
    public const string SYSTEM_TELEGRAM_ID = 'planfix-system';

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param array<int, int> $userMap planfix id → id людини в Плані (підтверджена відповідність)
     * @param list<int> $systemIds службові облікові записи Planfix → «Planfix (система)»
     *
     * @return array<string, int>
     */
    public function apply(string $batch, array $userMap = [], int $chunk = 200, ?callable $progress = null, array $systemIds = []): array
    {
        if (! PlanfixImportGuard::allowed()) {
            throw new RuntimeException('Запис у задачі заборонено: потрібна змінна середовища ' . PlanfixImportGuard::ENV . '=1.');
        }

        $row = $this->db->fetchAssociative('SELECT applied_at, rolled_back_at FROM planfix_import_batch WHERE id = ?', [$batch]);

        if ($row === false) {
            throw new RuntimeException(sprintf('Партії «%s» немає в staging — спершу запустіть імпорт без --apply.', $batch));
        }

        // Задачі, які вже видалили руками в Плані, з карти прибираємо — інакше до них писали б дії.
        PlanfixRollback::pruneStaleMap($this->db);

        $stats = ['tasks_created' => 0, 'tasks_existing' => 0, 'messages_created' => 0, 'messages_existing' => 0, 'members_created' => 0, 'placeholders_created' => 0, 'labels_created' => 0, 'reads_created' => 0];

        $people = $this->resolvePeople($batch, $userMap, $systemIds, $stats);
        $labels = $this->labels($batch, $stats);
        $departments = [];

        foreach ($this->db->fetchAllAssociative('SELECT id, name FROM supply_department') as $d) {
            $departments[mb_strtolower(trim((string) $d['name']))] = (int) $d['id'];
        }

        $mappedTasks = $this->mapped('task', 'task');
        $mappedActions = $this->mapped('action', 'task_message');
        $real = array_flip(array_map('intval', array_values($userMap)));

        $ids = array_map('intval', $this->db->fetchFirstColumn('SELECT planfix_id FROM planfix_import_task WHERE batch_id = ? ORDER BY created_at, planfix_id', [$batch]));

        foreach (array_chunk($ids, max(1, $chunk)) as $part) {
            $this->db->transactional(function () use ($batch, $part, $people, $labels, $departments, $real, &$mappedTasks, &$mappedActions, &$stats): void {
                $tasks = $this->db->fetchAllAssociative('SELECT * FROM planfix_import_task WHERE batch_id = ? AND planfix_id IN (?)', [$batch, $part], [1 => ArrayParameterType::INTEGER]);
                $actions = [];

                foreach ($this->db->fetchAllAssociative('SELECT * FROM planfix_import_action WHERE batch_id = ? AND task_planfix_id IN (?) ORDER BY at, planfix_id', [$batch, $part], [1 => ArrayParameterType::INTEGER]) as $a) {
                    $actions[(int) $a['task_planfix_id']][] = $a;
                }

                foreach ($tasks as $t) {
                    $pid = (int) $t['planfix_id'];
                    $taskId = $mappedTasks[$pid] ?? null;
                    $isNew = $taskId === null;
                    $members = [];

                    if ($isNew) {
                        $taskId = $this->createTask($batch, $t, $people, $labels, $departments, $members, $stats);
                        $mappedTasks[$pid] = $taskId;
                        ++$stats['tasks_created'];
                    } else {
                        ++$stats['tasks_existing'];
                    }

                    $last = null;

                    foreach ($actions[$pid] ?? [] as $a) {
                        $aid = (int) $a['planfix_id'];

                        if (isset($mappedActions[$aid])) {
                            ++$stats['messages_existing'];
                            continue;
                        }

                        $kind = $a['decision'] === PlanfixMapper::ACTION_COMMENT ? TaskMessage::KIND_COMMENT : TaskMessage::KIND_EVENT;
                        $messageId = $this->message($taskId, $people[(int) $a['owner_id']] ?? null, $kind, (string) $a['text'], $a['event_type'], (string) $a['at']);
                        $this->map($batch, 'action', $aid, 'task_message', $messageId);
                        $mappedActions[$aid] = $messageId;
                        $last = $a['at'];
                        ++$stats['messages_created'];
                    }

                    if (! $isNew && $last !== null) {
                        $this->db->executeStatement('UPDATE task SET last_activity_at = GREATEST(last_activity_at, CAST(? AS timestamp)) WHERE id = ?', [$last, $taskId]);
                    }

                    // «+N» у живих людей на тисячах старих задач — шум: позначаємо прочитаним.
                    foreach ($members as $userId) {
                        if (isset($real[$userId])) {
                            $readId = (int) $this->db->fetchOne(
                                "INSERT INTO task_read (id, task_id, user_id, last_message_id, read_at)
                                 SELECT nextval('task_read_id_seq'), ?, ?, COALESCE(MAX(m.id), 0), LOCALTIMESTAMP(0) FROM task_message m WHERE m.task_id = ?
                                 ON CONFLICT (task_id, user_id) DO NOTHING RETURNING id",
                                [$taskId, $userId, $taskId],
                            );

                            if ($readId > 0) {
                                $this->map($batch, 'read', $pid, 'task_read', $readId);
                                ++$stats['reads_created'];
                            }
                        }
                    }
                }
            });

            if ($progress !== null) {
                $progress(count($part));
            }
        }

        $this->db->executeStatement('UPDATE planfix_import_batch SET applied_at = LOCALTIMESTAMP(0), rolled_back_at = NULL WHERE id = ?', [$batch]);

        return $stats;
    }

    /**
     * @param array<string, mixed> $t
     * @param array<int, int> $people
     * @param array<string, int> $labels
     * @param array<string, int> $departments
     * @param list<int> $members
     * @param array<string, int> $stats
     */
    private function createTask(string $batch, array $t, array $people, array $labels, array $departments, array &$members, array &$stats): int
    {
        $pid = (int) $t['planfix_id'];
        $creator = $people[(int) $t['owner_id']] ?? throw new RuntimeException('Немає автора для задачі Planfix ' . $pid);
        $closed = in_array($t['status'], ['done', 'rejected'], true) ? $t['last_at'] : null;

        $taskId = (int) $this->db->fetchOne(
            "INSERT INTO task (id, creator_id, title, description, client, status, due_at, created_at, last_activity_at, closed_at)
             VALUES (nextval('task_id_seq'), ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
            [$creator, $t['title'], $t['description'], $t['client'], $t['status'], $t['due_at'], $t['created_at'], $t['last_at'], $closed],
        );
        $this->map($batch, 'task', $pid, 'task', $taskId);

        $created = $this->message($taskId, $creator, TaskMessage::KIND_EVENT, sprintf('задачу створено в Planfix (№ %d)', $t['number']), TaskMessage::EVENT_CREATED, (string) $t['created_at']);
        $this->map($batch, 'task_created', $pid, 'task_message', $created);

        $members = [$creator];

        foreach (json_decode((string) $t['member_ids'], true) ?: [] as $m) {
            if (isset($people[(int) $m]) && ! in_array($people[(int) $m], $members, true)) {
                $members[] = $people[(int) $m];
            }
        }

        foreach ($members as $userId) {
            $memberId = (int) $this->db->fetchOne("INSERT INTO task_member (id, task_id, user_id) VALUES (nextval('task_member_id_seq'), ?, ?) RETURNING id", [$taskId, $userId]);
            $this->map($batch, 'member', $pid, 'task_member', $memberId);
            ++$stats['members_created'];
        }

        // Відділ лише запамʼятовується (кому адресовано); людей відділу не додаємо.
        foreach (json_decode((string) $t['group_names'], true) ?: [] as $group) {
            $dep = $departments[mb_strtolower(trim((string) $group))] ?? null;

            if ($dep !== null) {
                $this->db->executeStatement('INSERT INTO task_department (task_id, department_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$taskId, $dep]);
            }
        }

        $names = [PlanfixMapper::LABEL_ALL];

        if ($t['urgent']) {
            $names[] = PlanfixMapper::LABEL_URGENT;
        }

        if ($t['stage'] !== null) {
            $names[] = PlanfixMapper::STAGE_PREFIX . $t['stage'];
        }

        foreach ($names as $name) {
            if (isset($labels[$name])) {
                $this->db->executeStatement('INSERT INTO task_task_label (task_id, label_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$taskId, $labels[$name]]);
            }
        }

        return $taskId;
    }

    private function message(int $taskId, ?int $author, string $kind, string $text, ?string $eventType, string $at): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO task_message (id, task_id, author_id, kind, text, mentions, created_at, event_type)
             VALUES (nextval('task_message_id_seq'), ?, ?, ?, ?, '[]', ?, ?) RETURNING id",
            [$taskId, $author, $kind, $text, $at, $eventType],
        );
    }

    /**
     * Planfix id → id людини в Плані: явна відповідність або заглушка
     * «Колишній співробітник #id» (без входу, у кошику, без Telegram).
     *
     * @param array<int, int> $userMap
     * @param list<int> $systemIds
     * @param array<string, int> $stats
     *
     * @return array<int, int>
     */
    private function resolvePeople(string $batch, array $userMap, array $systemIds, array &$stats): array
    {
        $people = [];

        foreach ($userMap as $pid => $userId) {
            if ($this->db->fetchOne('SELECT 1 FROM telegram_user WHERE id = ?', [$userId]) === false) {
                throw new RuntimeException(sprintf('Відповідність: людини Плану #%d (для Planfix %d) немає.', $userId, $pid));
            }
        }

        foreach ($this->db->fetchFirstColumn('SELECT planfix_id FROM planfix_import_user WHERE batch_id = ? ORDER BY planfix_id', [$batch]) as $pid) {
            $pid = (int) $pid;

            if (isset($userMap[$pid])) {
                $people[$pid] = (int) $userMap[$pid];
                continue;
            }

            $system = in_array($pid, $systemIds, true);
            $telegramId = $system ? self::SYSTEM_TELEGRAM_ID : self::PLACEHOLDER_PREFIX . $pid;
            $existing = $this->db->fetchOne('SELECT id FROM telegram_user WHERE telegram_id = ?', [$telegramId]);

            if ($existing !== false) {
                $people[$pid] = (int) $existing;
                continue;
            }

            $id = (int) $this->db->fetchOne(
                "INSERT INTO telegram_user (id, telegram_id, first_name, last_name, language_code, created_at, supply_role, access_status, access_decided_at, archived_at, is_superadmin, plan_full_access, session_epoch)
                 VALUES (nextval('telegram_user_id_seq'), ?, ?, ?, 'uk', LOCALTIMESTAMP(0), 'worker', 'rejected', LOCALTIMESTAMP(0), LOCALTIMESTAMP(0), false, false, 0) RETURNING id",
                [$telegramId, $system ? 'Planfix' : self::PLACEHOLDER_NAME, $system ? '(система)' : '#' . $pid],
            );
            $this->map($batch, 'user', $system ? 0 : $pid, 'telegram_user', $id);
            $people[$pid] = $id;
            ++$stats['placeholders_created'];
        }

        return $people;
    }

    /**
     * Мітки «Planfix», «Planfix: термінова», «Етап: …» — наявну за назвою беремо, нову створюємо.
     *
     * @param array<string, int> $stats
     *
     * @return array<string, int>
     */
    private function labels(string $batch, array &$stats): array
    {
        $names = [PlanfixMapper::LABEL_ALL];

        if ($this->db->fetchOne('SELECT 1 FROM planfix_import_task WHERE batch_id = ? AND urgent LIMIT 1', [$batch]) !== false) {
            $names[] = PlanfixMapper::LABEL_URGENT;
        }

        foreach ($this->db->fetchFirstColumn('SELECT DISTINCT stage FROM planfix_import_task WHERE batch_id = ? AND stage IS NOT NULL ORDER BY stage', [$batch]) as $stage) {
            $names[] = mb_substr(PlanfixMapper::STAGE_PREFIX . $stage, 0, 60);
        }

        $sort = (int) $this->db->fetchOne('SELECT COALESCE(MAX(sort), 0) FROM task_label');
        $labels = [];

        foreach ($names as $name) {
            $id = $this->db->fetchOne('SELECT id FROM task_label WHERE name = ?', [$name]);

            if ($id === false) {
                $id = $this->db->fetchOne(
                    "INSERT INTO task_label (id, name, color, sort) VALUES (nextval('task_label_id_seq'), ?, ?, ?) RETURNING id",
                    [$name, $name === PlanfixMapper::LABEL_URGENT ? '#dc2626' : '#64748b', ++$sort],
                );
                $this->map($batch, 'label', crc32($name), 'task_label', (int) $id);
                ++$stats['labels_created'];
            }

            $labels[$name] = (int) $id;
        }

        return $labels;
    }

    /** @return array<int, int> planfix id → plan id */
    private function mapped(string $type, string $table): array
    {
        $out = [];

        foreach ($this->db->fetchAllAssociative('SELECT planfix_id, plan_id FROM planfix_import_map WHERE planfix_type = ? AND plan_table = ?', [$type, $table]) as $r) {
            $out[(int) $r['planfix_id']] = (int) $r['plan_id'];
        }

        return $out;
    }

    private function map(string $batch, string $type, int $planfixId, string $table, int $planId): void
    {
        $this->db->executeStatement(
            'INSERT INTO planfix_import_map (batch_id, planfix_type, planfix_id, plan_table, plan_id) VALUES (?, ?, ?, ?, ?)',
            [$batch, $type, $planfixId, $table, $planId],
        );
    }
}
