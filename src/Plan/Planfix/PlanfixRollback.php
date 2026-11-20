<?php

namespace App\Plan\Planfix;

use App\Tasks\Service\AttachmentStorage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Відкат партії імпорту Planfix — рівно ті рядки Плану, що записані в planfix_import_map.
 *
 * Спершу рахуємо «чуже» — те, що в задачах партії вже зробили живі люди: свої повідомлення,
 * файли, учасників, ★ задачі, ★ повідомлень. Каскад ON DELETE знищив би це мовчки, тому:
 * - за замовчуванням, якщо чуже є, — відмова (нічого не видаляється);
 * - KEEP_TOUCHED — такі задачі лишаються, з них прибираються лише імпортовані повідомлення
 *   й учасники (крім автора); решта задач партії видаляється;
 * - FORCE — видаляється все, разом із чужим.
 *
 * Коли задача справді видаляється — разом із нею її події журналу (plan_event → каскадом
 * notification), plan_read, рядок Хроніки (plan_subject_activity) і пошуковий індекс.
 * Мітки й заглушки людей — лише коли на них більше ніщо не посилається.
 * Файли, що зникли з бази разом із задачами й повідомленнями, — і з диска (після коміту).
 */
final readonly class PlanfixRollback
{
    public const string REFUSE = 'refuse';
    public const string KEEP_TOUCHED = 'keep';
    public const string FORCE = 'force';

    /** Імпортований файл — не «чужий» вміст. */
    private const string NOT_IMPORTED_FILE = "NOT EXISTS (SELECT 1 FROM planfix_import_map xf WHERE xf.plan_table = 'task_attachment' AND xf.plan_id = a.id)";

    public function __construct(private Connection $db, private AttachmentStorage $storage)
    {
    }

    /**
     * @return array<string, int>
     */
    public function rollback(string $batch, bool $dryRun = false, string $mode = self::REFUSE, bool $purgeStaging = false): array
    {
        if (! $dryRun && ! PlanfixImportGuard::allowed()) {
            throw new RuntimeException(sprintf('Відкат заборонено: потрібна змінна середовища процесу %s=1.', PlanfixImportGuard::ENV));
        }

        if ($this->db->fetchOne('SELECT 1 FROM planfix_import_batch WHERE id = ?', [$batch]) === false) {
            throw new RuntimeException(sprintf('Партії «%s» немає.', $batch));
        }

        $tasks = array_map('intval', $this->db->fetchFirstColumn(
            "SELECT plan_id FROM planfix_import_map WHERE batch_id = ? AND plan_table = 'task' AND deleted_at IS NULL",
            [$batch],
        ));
        $stats = $this->foreign($tasks);
        $stats['tasks'] = count($tasks);
        $stats['messages'] = (int) $this->db->fetchOne("SELECT COUNT(*) FROM planfix_import_map WHERE batch_id = ? AND plan_table = 'task_message'", [$batch]);
        $stats['files'] = (int) $this->db->fetchOne("SELECT COUNT(*) FROM planfix_import_map WHERE batch_id = ? AND plan_table = 'task_attachment'", [$batch]);
        $stats['refused'] = (int) ($mode === self::REFUSE && $stats['touched_tasks'] > 0);
        $stats += ['tasks_deleted' => 0, 'tasks_kept' => 0, 'labels' => 0, 'placeholders' => 0, 'files_removed' => 0];

        if ($dryRun || $stats['refused'] === 1) {
            return $stats;
        }

        $touched = $mode === self::KEEP_TOUCHED ? $this->touched($tasks) : [];
        $delete = array_values(array_diff($tasks, $touched));

        $doomed = [];

        $this->db->transactional(function () use ($batch, $delete, $touched, $purgeStaging, &$stats, &$doomed): void {
            $ids = [$delete];
            $types = [ArrayParameterType::INTEGER];

            // Що з файлів зникне каскадом: усе в задачах, які видаляємо, і імпортоване цією партією.
            $doomed = $this->db->fetchAllAssociative(
                "SELECT a.id, a.path FROM task_attachment a JOIN task_message m ON m.id = a.message_id WHERE m.task_id IN (:t)
                 UNION SELECT a.id, a.path FROM task_attachment a JOIN planfix_import_map x ON x.plan_table = 'task_attachment' AND x.plan_id = a.id WHERE x.batch_id = :b",
                ['t' => $delete === [] ? [0] : $delete, 'b' => $batch],
                ['t' => ArrayParameterType::INTEGER],
            );

            if ($delete !== []) {
                foreach (['search_document', 'plan_subject_activity', 'plan_read', 'plan_event'] as $table) {
                    // plan_event → каскадом notification і plan_event_consumed.
                    $this->db->executeStatement("DELETE FROM {$table} WHERE subject_type = 'task' AND subject_id IN (?)", $ids, $types);
                }

                // Каскадом: повідомлення, учасники, мітки, відділи, прочитання, ★.
                $stats['tasks_deleted'] = $this->db->executeStatement('DELETE FROM task WHERE id IN (?)', $ids, $types);
            }

            if ($touched !== []) {
                // Лишаємо задачу з чужим вмістом: прибираємо тільки імпортоване.
                $this->db->executeStatement(
                    "DELETE FROM task_member tm USING task t, planfix_import_map x
                      WHERE t.id = tm.task_id AND tm.task_id IN (:t) AND tm.user_id <> t.creator_id
                        AND x.batch_id = :b AND x.plan_table = 'task_member' AND x.plan_id = tm.id",
                    ['t' => $touched, 'b' => $batch],
                    ['t' => ArrayParameterType::INTEGER],
                );
                $stats['tasks_kept'] = count($touched);
            }

            // Файли цієї партії (і в повідомленнях, що лишаються), далі дії — і в її задачах,
            // і дописані до задач інших партій.
            $this->db->executeStatement(
                "DELETE FROM task_attachment WHERE id IN (SELECT x.plan_id FROM planfix_import_map x WHERE x.batch_id = ? AND x.plan_table = 'task_attachment')",
                [$batch],
            );
            $this->db->executeStatement(
                "DELETE FROM task_message WHERE id IN (SELECT x.plan_id FROM planfix_import_map x WHERE x.batch_id = ? AND x.plan_table = 'task_message')",
                [$batch],
            );
            $stats['labels'] = $this->db->executeStatement(
                "DELETE FROM task_label l WHERE l.id IN (SELECT x.plan_id FROM planfix_import_map x WHERE x.batch_id = ? AND x.plan_table = 'task_label')
                   AND NOT EXISTS (SELECT 1 FROM task_task_label t WHERE t.label_id = l.id)",
                [$batch],
            );
            $stats['placeholders'] = $this->db->executeStatement(
                "DELETE FROM telegram_user u WHERE u.id IN (SELECT x.plan_id FROM planfix_import_map x WHERE x.batch_id = ? AND x.plan_table = 'telegram_user')
                   AND u.telegram_id LIKE 'planfix-%'
                   AND NOT EXISTS (SELECT 1 FROM task t WHERE t.creator_id = u.id)
                   AND NOT EXISTS (SELECT 1 FROM task_member m WHERE m.user_id = u.id)
                   AND NOT EXISTS (SELECT 1 FROM task_message m WHERE m.author_id = u.id)",
                [$batch],
            );

            // Рядки карти партії, чиїх рядків Плану вже немає, — геть (не надгробки: відкат
            // дозволяє імпортувати знову). Лишаються задачі, збережені через чужий вміст,
            // і заглушки, на які ще посилаються.
            foreach (['task', 'task_message', 'task_member', 'task_read', 'task_label', 'telegram_user', 'task_attachment'] as $table) {
                $this->db->executeStatement(
                    "DELETE FROM planfix_import_map x WHERE x.batch_id = ? AND x.plan_table = '{$table}' AND NOT EXISTS (SELECT 1 FROM {$table} p WHERE p.id = x.plan_id)",
                    [$batch],
                );
            }

            self::pruneStaleMap($this->db);

            if ($purgeStaging) {
                PlanfixStaging::purge($this->db, $batch);
            }

            $this->db->executeStatement('UPDATE planfix_import_batch SET rolled_back_at = LOCALTIMESTAMP(0), applied_at = NULL WHERE id = ?', [$batch]);
        });

        foreach ($doomed as $file) {
            $this->storage->removeFiles((int) $file['id'], (string) $file['path']);
        }

        $stats['files_removed'] = count($doomed);

        return $stats;
    }

    /**
     * Рядки карти, чиїх рядків Плану вже немає. Задача, видалена руками в Плані, лишає
     * надгробок (deleted_at) — повторний імпорт її не відтворить; решта — видаляється.
     */
    public static function pruneStaleMap(Connection $db): void
    {
        $db->executeStatement(
            "UPDATE planfix_import_map x SET deleted_at = LOCALTIMESTAMP(0)
              WHERE x.plan_table = 'task' AND x.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM task p WHERE p.id = x.plan_id)",
        );

        foreach (['task_message', 'task_member', 'task_read', 'task_label', 'telegram_user'] as $table) {
            $db->executeStatement(
                "DELETE FROM planfix_import_map x WHERE x.plan_table = '{$table}' AND NOT EXISTS (SELECT 1 FROM {$table} p WHERE p.id = x.plan_id)",
            );
        }
    }

    /**
     * Що в задачах партії зробили не імпортом.
     *
     * @param list<int> $tasks
     *
     * @return array<string, int>
     */
    private function foreign(array $tasks): array
    {
        $out = ['foreign_messages' => 0, 'foreign_files' => 0, 'foreign_members' => 0, 'foreign_favorites' => 0, 'foreign_stars' => 0, 'plan_events' => 0, 'touched_tasks' => 0];

        if ($tasks === []) {
            return $out;
        }

        $p = ['t' => $tasks];
        $t = ['t' => ArrayParameterType::INTEGER];
        $one = fn (string $sql): int => (int) $this->db->fetchOne($sql, $p, $t);

        $out['foreign_messages'] = $one("SELECT COUNT(*) FROM task_message m WHERE m.task_id IN (:t)
            AND NOT EXISTS (SELECT 1 FROM planfix_import_map x WHERE x.plan_table = 'task_message' AND x.plan_id = m.id)");
        $out['foreign_files'] = $one('SELECT COUNT(*) FROM task_attachment a JOIN task_message m ON m.id = a.message_id WHERE m.task_id IN (:t) AND ' . self::NOT_IMPORTED_FILE);
        $out['foreign_members'] = $one("SELECT COUNT(*) FROM task_member tm WHERE tm.task_id IN (:t)
            AND NOT EXISTS (SELECT 1 FROM planfix_import_map x WHERE x.plan_table = 'task_member' AND x.plan_id = tm.id)");
        $out['foreign_favorites'] = $one('SELECT COUNT(*) FROM task_favorite f WHERE f.task_id IN (:t)');
        $out['foreign_stars'] = $one('SELECT COUNT(*) FROM task_message_star s JOIN task_message m ON m.id = s.message_id WHERE m.task_id IN (:t)');
        $out['plan_events'] = $one("SELECT COUNT(*) FROM plan_event e WHERE e.subject_type = 'task' AND e.subject_id IN (:t)");
        $out['touched_tasks'] = count($this->touched($tasks));

        return $out;
    }

    /**
     * Задачі партії, де є чужий вміст (повідомлення, файли, учасники, ★).
     *
     * @param list<int> $tasks
     *
     * @return list<int>
     */
    private function touched(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }

        return array_map('intval', $this->db->fetchFirstColumn(
            "SELECT m.task_id FROM task_message m WHERE m.task_id IN (:t)
                AND NOT EXISTS (SELECT 1 FROM planfix_import_map x WHERE x.plan_table = 'task_message' AND x.plan_id = m.id)
             UNION SELECT m.task_id FROM task_attachment a JOIN task_message m ON m.id = a.message_id WHERE m.task_id IN (:t) AND " . self::NOT_IMPORTED_FILE . "
             UNION SELECT tm.task_id FROM task_member tm WHERE tm.task_id IN (:t)
                AND NOT EXISTS (SELECT 1 FROM planfix_import_map x WHERE x.plan_table = 'task_member' AND x.plan_id = tm.id)
             UNION SELECT f.task_id FROM task_favorite f WHERE f.task_id IN (:t)
             UNION SELECT m.task_id FROM task_message_star s JOIN task_message m ON m.id = s.message_id WHERE m.task_id IN (:t)",
            ['t' => $tasks],
            ['t' => ArrayParameterType::INTEGER],
        ));
    }
}
