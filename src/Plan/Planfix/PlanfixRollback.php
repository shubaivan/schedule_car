<?php

namespace App\Plan\Planfix;

use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Відкат партії імпорту Planfix: видаляє рівно ті рядки Плану, що записані в
 * planfix_import_map цієї партії. Задача тягне за собою (ON DELETE CASCADE) свої
 * повідомлення, учасників, мітки, відділи, прочитання; окремо чистимо пошуковий
 * індекс і рядок Хроніки (якщо після імпорту зробили reindex). Мітки й заглушки людей
 * видаляються, лише коли на них більше ніщо не посилається.
 */
final readonly class PlanfixRollback
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array<string, int>
     */
    public function rollback(string $batch, bool $dryRun = false, bool $purgeStaging = false): array
    {
        if ($this->db->fetchOne('SELECT 1 FROM planfix_import_batch WHERE id = ?', [$batch]) === false) {
            throw new RuntimeException(sprintf('Партії «%s» немає.', $batch));
        }

        $count = fn (string $table): int => (int) $this->db->fetchOne('SELECT COUNT(*) FROM planfix_import_map WHERE batch_id = ? AND plan_table = ?', [$batch, $table]);
        $mine = static fn (string $table, string $column = 'id'): string => sprintf("%s IN (SELECT x.plan_id FROM planfix_import_map x WHERE x.batch_id = :batch AND x.plan_table = '%s')", $column, $table);

        // Чужі для партії повідомлення в її задачах (люди вже писали після імпорту) — попереджаємо.
        $foreign = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM task_message m WHERE ' . $mine('task', 'm.task_id') . "
               AND NOT EXISTS (SELECT 1 FROM planfix_import_map y WHERE y.plan_table = 'task_message' AND y.plan_id = m.id)",
            ['batch' => $batch],
        );

        $stats = ['tasks' => $count('task'), 'messages' => $count('task_message'), 'labels' => 0, 'placeholders' => 0, 'foreign_messages' => $foreign];

        if ($dryRun) {
            return $stats;
        }

        $this->db->transactional(function () use ($batch, $mine, $purgeStaging, &$stats): void {
            $p = ['batch' => $batch];
            $this->db->executeStatement("DELETE FROM search_document WHERE subject_type = 'task' AND " . $mine('task', 'subject_id'), $p);
            $this->db->executeStatement("DELETE FROM plan_subject_activity WHERE subject_type = 'task' AND " . $mine('task', 'subject_id'), $p);
            // Каскадом: повідомлення, учасники, мітки, відділи, прочитання, ★.
            $this->db->executeStatement('DELETE FROM task WHERE ' . $mine('task'), $p);
            // Дії, дописані цією партією до задач іншої партії.
            $this->db->executeStatement('DELETE FROM task_message WHERE ' . $mine('task_message'), $p);
            $stats['labels'] = $this->db->executeStatement(
                'DELETE FROM task_label l WHERE ' . $mine('task_label', 'l.id') . ' AND NOT EXISTS (SELECT 1 FROM task_task_label t WHERE t.label_id = l.id)',
                $p,
            );
            $stats['placeholders'] = $this->db->executeStatement(
                'DELETE FROM telegram_user u WHERE ' . $mine('telegram_user', 'u.id') . " AND u.telegram_id LIKE 'planfix-%'
                   AND NOT EXISTS (SELECT 1 FROM task t WHERE t.creator_id = u.id)
                   AND NOT EXISTS (SELECT 1 FROM task_member m WHERE m.user_id = u.id)
                   AND NOT EXISTS (SELECT 1 FROM task_message m WHERE m.author_id = u.id)",
                $p,
            );

            $this->db->executeStatement('DELETE FROM planfix_import_map WHERE batch_id = ?', [$batch]);
            self::pruneStaleMap($this->db);

            if ($purgeStaging) {
                foreach (['planfix_import_action', 'planfix_import_task', 'planfix_import_user'] as $table) {
                    $this->db->executeStatement(sprintf('DELETE FROM %s WHERE batch_id = ?', $table), [$batch]);
                }

                $this->db->executeStatement('DELETE FROM planfix_import_batch WHERE id = ?', [$batch]);
            } else {
                $this->db->executeStatement('UPDATE planfix_import_batch SET rolled_back_at = LOCALTIMESTAMP(0), applied_at = NULL WHERE id = ?', [$batch]);
            }
        });

        return $stats;
    }

    /** Рядки карти, чиїх рядків Плану вже немає (видалили руками або каскадом). */
    public static function pruneStaleMap(Connection $db): void
    {
        foreach (['task', 'task_message', 'task_member', 'task_read', 'task_label', 'telegram_user'] as $table) {
            $db->executeStatement(
                "DELETE FROM planfix_import_map x WHERE x.plan_table = '{$table}' AND NOT EXISTS (SELECT 1 FROM {$table} p WHERE p.id = x.plan_id)",
            );
        }
    }
}
