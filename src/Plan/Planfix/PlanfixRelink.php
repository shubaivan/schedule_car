<?php

namespace App\Plan\Planfix;

use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Заглушку людини Planfix («Колишній співробітник #id» тощо) злити з живою людиною Плану:
 * автор задач і повідомлень, учасник — тепер вона; заглушка видаляється. Відповідність
 * запамʼятовується в карті (planfix_type = user_link) — наступні імпорти одразу пишуть на
 * живу людину. Перенесені задачі для неї позначаються прочитаними (без «+N» на старому).
 */
final readonly class PlanfixRelink
{
    public const string BATCH = 'relink';

    public function __construct(private Connection $db)
    {
    }

    /** @return array<string, int> */
    public function relink(int $planfixId, int $userId, bool $dryRun = false): array
    {
        if (! $dryRun && ! PlanfixImportGuard::allowed()) {
            throw new RuntimeException(sprintf('Заборонено: потрібна змінна середовища процесу %s=1.', PlanfixImportGuard::ENV));
        }

        $target = $this->db->fetchAssociative('SELECT id, telegram_id FROM telegram_user WHERE id = ?', [$userId]);

        if ($target === false || str_starts_with((string) $target['telegram_id'], PlanfixApplier::PLACEHOLDER_PREFIX)) {
            throw new RuntimeException(sprintf('Людини Плану #%d немає (або це сама заглушка Planfix).', $userId));
        }

        $ghost = $this->db->fetchOne('SELECT id FROM telegram_user WHERE telegram_id = ?', [PlanfixApplier::PLACEHOLDER_PREFIX . $planfixId]);
        $ghost = $ghost === false ? null : (int) $ghost;
        $tasks = $ghost === null ? [] : array_map('intval', $this->db->fetchFirstColumn(
            'SELECT id FROM task WHERE creator_id = :g UNION SELECT task_id FROM task_member WHERE user_id = :g UNION SELECT task_id FROM task_message WHERE author_id = :g',
            ['g' => $ghost],
        ));
        $stats = ['placeholder' => $ghost ?? 0, 'tasks' => count($tasks)];

        if ($dryRun) {
            return $stats;
        }

        $this->db->transactional(function () use ($planfixId, $userId, $ghost, $tasks): void {
            if ($ghost !== null) {
                $p = ['g' => $ghost, 'u' => $userId];
                $this->db->executeStatement('UPDATE task SET creator_id = :u WHERE creator_id = :g', $p);
                $this->db->executeStatement('UPDATE task_message SET author_id = :u WHERE author_id = :g', $p);
                $this->db->executeStatement('UPDATE task_attachment SET uploaded_by_id = :u WHERE uploaded_by_id = :g', $p);
                // Де жива людина вже учасник — дубль заглушки прибираємо, інакше переписуємо.
                $this->db->executeStatement('DELETE FROM task_member WHERE user_id = :g AND task_id IN (SELECT task_id FROM task_member WHERE user_id = :u)', $p);
                $this->db->executeStatement('UPDATE task_member SET user_id = :u WHERE user_id = :g', $p);
                $this->db->executeStatement('DELETE FROM task_read WHERE user_id = :g', ['g' => $ghost]);

                foreach ($tasks as $taskId) {
                    $this->db->executeStatement(
                        "INSERT INTO task_read (id, task_id, user_id, last_message_id, read_at)
                         SELECT nextval('task_read_id_seq'), ?, ?, COALESCE(MAX(m.id), 0), LOCALTIMESTAMP(0) FROM task_message m WHERE m.task_id = ?
                         ON CONFLICT (task_id, user_id) DO UPDATE SET last_message_id = GREATEST(task_read.last_message_id, EXCLUDED.last_message_id)",
                        [$taskId, $userId, $taskId],
                    );
                }

                $this->db->executeStatement('DELETE FROM telegram_user WHERE id = ?', [$ghost]);
            }

            $this->db->executeStatement("DELETE FROM planfix_import_map WHERE planfix_type = 'user_link' AND planfix_id = ?", [$planfixId]);
            $this->db->executeStatement(
                "INSERT INTO planfix_import_map (batch_id, planfix_type, planfix_id, plan_table, plan_id) VALUES (?, 'user_link', ?, 'telegram_user', ?)",
                [self::BATCH, $planfixId, $userId],
            );
            PlanfixRollback::pruneStaleMap($this->db);
        });

        return $stats;
    }
}
