<?php

namespace App\Plan\Planfix;

use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Service\AttachmentStorage;
use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Throwable;

/**
 * Staging-партія → справжні задачі Плану (task, task_message, task_member, task_department,
 * task_task_label, task_read), кожен створений рядок — у planfix_import_map.
 *
 * Навмисно напряму через DBAL, повз TaskService і ORM: жодної події в plan_event, отже
 * без Telegram/push/дзвіночка (TaskNotificationListener, NotificationFanout), без Mercure,
 * без Хроніки. Дати — оригінальні з Planfix.
 *
 * Файли (staging planfix_import_file, з --files): копія в сховище задач, task_attachment у
 * повідомлення своєї дії (файли задачі — у «задачу створено»). Превʼю — у черзі tasks:previews,
 * на Google Диск не йдуть (drive_state = skipped): Олександр 19.11 — копія на Диск не потрібна.
 * Транзакція порції впала — скопійовані нею файли прибираються.
 *
 * Ідемпотентно: задача чи дія, що вже є в карті (з будь-якої партії), вдруге не пишеться;
 * до вже перенесеної задачі додаються лише нові дії. Поля перенесених задач не переписуються.
 * Задачу, яку в Плані видалили руками, карта памʼятає надгробком (deleted_at) — повторний
 * імпорт її не повертає.
 */
final readonly class PlanfixApplier
{
    public const string PLACEHOLDER_PREFIX = 'planfix-';
    /** Службові облікові записи Planfix (роботи, сценарії) — один автор «Planfix (система)». */
    public const string SYSTEM_TELEGRAM_ID = 'planfix-system';

    public const string DRIVE_NOTE = 'Planfix: без копії на Диск';

    public function __construct(private Connection $db, private AttachmentStorage $storage)
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

        // Задачі, які вже видалили руками в Плані, — надгробки: до них не пишемо й не відтворюємо.
        PlanfixRollback::pruneStaleMap($this->db);

        $stats = ['tasks_created' => 0, 'tasks_existing' => 0, 'tasks_tombstoned' => 0, 'messages_created' => 0, 'messages_existing' => 0, 'members_created' => 0, 'placeholders_created' => 0, 'labels_created' => 0, 'reads_created' => 0, 'files_attached' => 0, 'files_existing' => 0];

        $people = $this->resolvePeople($batch, $userMap, $systemIds, $stats);
        $labels = $this->labels($batch, $stats);
        $departments = [];

        foreach ($this->db->fetchAllAssociative('SELECT id, name FROM supply_department') as $d) {
            $departments[mb_strtolower(trim((string) $d['name']))] = (int) $d['id'];
        }

        $mappedTasks = $this->mapped('task', 'task');
        $tombstones = array_flip(array_map('intval', $this->db->fetchFirstColumn("SELECT planfix_id FROM planfix_import_map WHERE planfix_type = 'task' AND plan_table = 'task' AND deleted_at IS NOT NULL")));
        $mappedActions = $this->mapped('action', 'task_message');
        $mappedCreated = $this->mapped('task_created', 'task_message');
        $mappedFiles = $this->mapped('file', 'task_attachment');
        // Живі люди (не заглушки) — їм «+N» на старих задачах не потрібен.
        $real = [];

        foreach ($people as $userId) {
            $real[$userId] = true;
        }

        foreach ($this->db->fetchFirstColumn("SELECT id FROM telegram_user WHERE telegram_id LIKE 'planfix-%'") as $ghost) {
            unset($real[(int) $ghost]);
        }

        $ids = array_map('intval', $this->db->fetchFirstColumn('SELECT planfix_id FROM planfix_import_task WHERE batch_id = ? ORDER BY created_at, planfix_id', [$batch]));

        foreach (array_chunk($ids, max(1, $chunk)) as $part) {
            $copied = [];

            try {
                $this->db->transactional(function () use ($batch, $part, $people, $labels, $departments, $real, $tombstones, &$mappedTasks, &$mappedActions, &$mappedCreated, &$mappedFiles, &$copied, &$stats): void {
                    $tasks = $this->db->fetchAllAssociative('SELECT * FROM planfix_import_task WHERE batch_id = ? AND planfix_id IN (?)', [$batch, $part], [1 => ArrayParameterType::INTEGER]);
                    $actions = [];
                    $files = [];

                    foreach ($this->db->fetchAllAssociative('SELECT * FROM planfix_import_file WHERE batch_id = ? AND task_planfix_id IN (?) ORDER BY planfix_id', [$batch, $part], [1 => ArrayParameterType::INTEGER]) as $f) {
                        $files[(int) $f['task_planfix_id']][(int) ($f['action_planfix_id'] ?? 0)][] = $f;
                    }

                    foreach ($this->db->fetchAllAssociative('SELECT * FROM planfix_import_action WHERE batch_id = ? AND task_planfix_id IN (?) ORDER BY at, planfix_id', [$batch, $part], [1 => ArrayParameterType::INTEGER]) as $a) {
                        $actions[(int) $a['task_planfix_id']][] = $a;
                    }

                    foreach ($tasks as $t) {
                        $pid = (int) $t['planfix_id'];

                        if (isset($tombstones[$pid])) {
                            ++$stats['tasks_tombstoned'];
                            continue;
                        }

                        $taskId = $mappedTasks[$pid] ?? null;
                        $isNew = $taskId === null;

                        if ($isNew) {
                            [$taskId, $mappedCreated[$pid]] = $this->createTask($batch, $t, $people, $labels, $departments, $stats);
                            $mappedTasks[$pid] = $taskId;
                            ++$stats['tasks_created'];
                        } else {
                            ++$stats['tasks_existing'];
                        }

                        if (isset($mappedCreated[$pid])) {
                            $this->attachFiles($batch, $files[$pid][0] ?? [], $mappedCreated[$pid], $people[(int) $t['owner_id']] ?? null, (string) $t['created_at'], $mappedFiles, $copied, $stats);
                        }

                        $last = null;

                        foreach ($actions[$pid] ?? [] as $a) {
                            $aid = (int) $a['planfix_id'];

                            $author = $people[(int) $a['owner_id']] ?? null;

                            if (isset($mappedActions[$aid])) {
                                ++$stats['messages_existing'];
                                // Файли, яких минулого разу ще не було, — до вже перенесеного повідомлення.
                                $this->attachFiles($batch, $files[$pid][$aid] ?? [], $mappedActions[$aid], $author, (string) $a['at'], $mappedFiles, $copied, $stats);
                                continue;
                            }

                            $kind = $a['decision'] === PlanfixMapper::ACTION_COMMENT ? TaskMessage::KIND_COMMENT : TaskMessage::KIND_EVENT;
                            $messageId = $this->message($taskId, $author, $kind, (string) $a['text'], $a['event_type'], (string) $a['at']);
                            $this->map($batch, 'action', $aid, 'task_message', $messageId);
                            $mappedActions[$aid] = $messageId;
                            $this->attachFiles($batch, $files[$pid][$aid] ?? [], $messageId, $author, (string) $a['at'], $mappedFiles, $copied, $stats);
                            $last = $a['at'];
                            ++$stats['messages_created'];
                        }

                        if (! $isNew && $last !== null) {
                            $this->db->executeStatement('UPDATE task SET last_activity_at = GREATEST(last_activity_at, CAST(? AS timestamp)) WHERE id = ?', [$last, $taskId]);
                        }

                        if ($isNew || $last !== null) {
                            $this->markRead($batch, $pid, $taskId, $real, $stats);
                        }
                    }
                });
            } catch (Throwable $e) {
                // Рядків уже немає (відкат транзакції) — без них і скопійовані файли нікому не належать.
                foreach ($copied as [$attachmentId, $relative]) {
                    $this->storage->removeFiles($attachmentId, $relative);
                }

                throw $e;
            }

            if ($progress !== null) {
                $progress(count($part));
            }
        }

        $this->db->executeStatement('UPDATE planfix_import_batch SET applied_at = LOCALTIMESTAMP(0), rolled_back_at = NULL WHERE id = ?', [$batch]);

        return $stats;
    }

    /**
     * «+N» у живих учасників на старих задачах — шум: прочитано до останнього повідомлення
     * (і після дописаних повторним імпортом дій теж). Нове прочитання — у карту.
     *
     * @param array<int, true> $real
     * @param array<string, int> $stats
     */
    private function markRead(string $batch, int $pid, int $taskId, array $real, array &$stats): void
    {
        foreach ($this->db->fetchFirstColumn('SELECT user_id FROM task_member WHERE task_id = ?', [$taskId]) as $userId) {
            if (! isset($real[(int) $userId])) {
                continue;
            }

            $row = $this->db->fetchAssociative(
                "INSERT INTO task_read (id, task_id, user_id, last_message_id, read_at)
                 SELECT nextval('task_read_id_seq'), ?, ?, COALESCE(MAX(m.id), 0), LOCALTIMESTAMP(0) FROM task_message m WHERE m.task_id = ?
                 ON CONFLICT (task_id, user_id) DO UPDATE SET last_message_id = GREATEST(task_read.last_message_id, EXCLUDED.last_message_id)
                 RETURNING id, (xmax = 0) AS inserted",
                [$taskId, (int) $userId, $taskId],
            );

            if ($row !== false && $row['inserted']) {
                $this->map($batch, 'read', $pid, 'task_read', (int) $row['id']);
                ++$stats['reads_created'];
            }
        }
    }

    /**
     * @param array<string, mixed> $t
     * @param array<int, int> $people
     * @param array<string, int> $labels
     * @param array<string, int> $departments
     * @param array<string, int> $stats
     *
     * @return array{int, int} id задачі, id повідомлення «задачу створено»
     */
    private function createTask(string $batch, array $t, array $people, array $labels, array $departments, array &$stats): array
    {
        $pid = (int) $t['planfix_id'];
        $creator = $people[(int) $t['owner_id']] ?? throw new RuntimeException('Немає автора для задачі Planfix ' . $pid);
        // Закрито — коли востаннє змінили статус у Planfix (навіть якщо сама подія в чат не йде).
        $closed = in_array($t['status'], ['done', 'rejected'], true) ? ($t['closed_at'] ?? $t['last_at']) : null;

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
            $names[] = PlanfixMapper::stageLabel((string) $t['stage']);
        }

        foreach ($names as $name) {
            if (isset($labels[$name])) {
                $this->db->executeStatement('INSERT INTO task_task_label (task_id, label_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$taskId, $labels[$name]]);
            }
        }

        return [$taskId, $created];
    }

    /**
     * Файли staging → task_attachment у повідомлення + копія в сховище. Уже перенесений файл
     * (карта, будь-яка партія) — пропуск. Автор і дата — як у повідомлення.
     *
     * @param list<array<string, mixed>> $files
     * @param array<int, int> $mappedFiles
     * @param list<array{int, string}> $copied
     * @param array<string, int> $stats
     */
    private function attachFiles(string $batch, array $files, int $messageId, ?int $author, string $at, array &$mappedFiles, array &$copied, array &$stats): void
    {
        foreach ($files as $f) {
            $fid = (int) $f['planfix_id'];

            if (isset($mappedFiles[$fid])) {
                ++$stats['files_existing'];
                continue;
            }

            $id = (int) $this->db->fetchOne(
                "INSERT INTO task_attachment (id, message_id, uploaded_by_id, original_name, mime, size, sha256, path, kind, preview_state, created_at, drive_state, drive_error)
                 VALUES (nextval('task_attachment_id_seq'), ?, ?, ?, ?, ?, ?, '', ?, ?, ?, 'skipped', ?) RETURNING id",
                [$messageId, $author, $f['name'], $f['mime'], (int) $f['size'], $f['sha256'], $f['kind'], $f['kind'] === TaskAttachment::KIND_OTHER ? TaskAttachment::PREVIEW_NONE : TaskAttachment::PREVIEW_PENDING, $at, self::DRIVE_NOTE],
            );
            $relative = AttachmentStorage::relativePath($id, (string) $f['sha256'], (string) $f['name'], new DateTime($at));
            $copied[] = [$id, $relative];
            $this->storage->store($relative, (string) $f['local_path']);
            $this->db->executeStatement('UPDATE task_attachment SET path = ? WHERE id = ?', [$relative, $id]);
            $this->map($batch, 'file', $fid, 'task_attachment', $id);
            $mappedFiles[$fid] = $id;
            ++$stats['files_attached'];
        }
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
     * Planfix id → id людини в Плані: явна відповідність (--user-map або раніше plan:planfix:relink),
     * інакше заглушка «Співробітник Planfix / Колишній співробітник / Контакт з сайту #id»
     * (без входу, у кошику, без Telegram).
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

        $links = [];

        foreach ($this->db->fetchAllAssociative("SELECT x.planfix_id, x.plan_id FROM planfix_import_map x JOIN telegram_user u ON u.id = x.plan_id WHERE x.planfix_type = 'user_link'") as $l) {
            $links[(int) $l['planfix_id']] = (int) $l['plan_id'];
        }

        foreach ($this->db->fetchAllAssociative('SELECT planfix_id, role FROM planfix_import_user WHERE batch_id = ? ORDER BY planfix_id', [$batch]) as $u) {
            $pid = (int) $u['planfix_id'];

            if (isset($userMap[$pid]) || isset($links[$pid])) {
                $people[$pid] = (int) ($userMap[$pid] ?? $links[$pid]);
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
                [$telegramId, $system ? 'Planfix' : PlanfixMapper::personName((string) $u['role']), $system ? '(система)' : '#' . $pid],
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
            $names[] = PlanfixMapper::stageLabel((string) $stage);
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

        foreach ($this->db->fetchAllAssociative('SELECT planfix_id, plan_id FROM planfix_import_map WHERE planfix_type = ? AND plan_table = ? AND deleted_at IS NULL', [$type, $table]) as $r) {
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
