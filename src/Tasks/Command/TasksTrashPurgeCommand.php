<?php

namespace App\Tasks\Command;

use App\Plan\Event\SubjectDeleted;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Tasks\Entity\Task;
use App\Tasks\Service\AttachmentStorage;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Кошик задач: задачі, що пролежали там довше за Task::TRASH_DAYS (30 днів), видаляються
 * остаточно — рядок задачі з чатом, учасниками, позначками (каскадом), рядки пошуку, Хроніки
 * й «прочитано» по задачі, посилання поширення її файлів, і самі файли з превʼю на диску.
 *
 * Журнал подій (plan_event) лишається — це історія; у журнал аудиту йде «Видалено в кошик»
 * з purged = true. Копії файлів на Google Диску не чіпаються.
 *
 *     php bin/console tasks:trash:purge --dry-run    # що буде видалено
 *     php bin/console tasks:trash:purge              # крон щоночі 03:50 (deploy/plan.cron)
 */
#[AsCommand(name: 'tasks:trash:purge', description: 'Остаточно видалити задачі, що пролежали в кошику довше за 30 днів')]
final class TasksTrashPurgeCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly PlanEventRecorder $events,
        private readonly AttachmentStorage $storage,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати, що буде видалено')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Скільки днів задача лежить у кошику', (string) Task::TRASH_DAYS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $days = $input->getOption('days');

        if (! is_string($days) || preg_match('/^[1-9]\d{0,4}$/', $days) !== 1) {
            $io->error('--days має бути додатним цілим.');

            return Command::INVALID;
        }

        $before = new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'))->modify(sprintf('-%d days', (int) $days))->format('Y-m-d H:i:s');
        $tasks = $this->db->fetchAllAssociative('SELECT id, title, deleted_at FROM task WHERE deleted_at IS NOT NULL AND deleted_at < ? ORDER BY id', [$before]);
        $rows = [];
        $purged = 0;

        foreach ($tasks as $task) {
            $id = (int) $task['id'];
            $files = $this->files($id);

            if (! $dryRun) {
                if (! $this->purge($id, (string) $task['title'], $before)) {
                    $rows[] = [$id, $task['title'], $task['deleted_at'], count($files), 'пропущено (відновили)'];
                    continue;
                }

                // Файли — після коміту: якщо транзакція відкотиться, файли мають лишитися.
                foreach ($files as $file) {
                    $this->storage->removeFiles((int) $file['id'], (string) $file['path']);
                }
                $this->logger->info('Task purged from trash', ['task' => $id, 'files' => count($files)]);
            }

            ++$purged;
            $rows[] = [$id, $task['title'], $task['deleted_at'], count($files), $dryRun ? 'буде видалено' : 'видалено'];
        }

        if ($rows !== []) {
            $io->table(['id', 'назва', 'у кошику з', 'файлів', 'результат'], $rows);
        }
        $io->success(sprintf('%s%d задач з кошика (старших за %s).', $dryRun ? '[dry-run] Буде видалено ' : 'Видалено ', $purged, $before));

        return Command::SUCCESS;
    }

    /** @return list<array{id: int|string, path: string}> */
    private function files(int $taskId): array
    {
        /** @var list<array{id: int|string, path: string}> */
        return $this->db->fetchAllAssociative(
            'SELECT a.id, a.path FROM task_attachment a JOIN task_message m ON m.id = a.message_id WHERE m.task_id = ? ORDER BY a.id',
            [$taskId],
        );
    }

    private function purge(int $id, string $title, string $before): bool
    {
        return $this->events->transactional(function () use ($id, $title, $before): bool {
            // Під блокуванням і з тією ж умовою: якщо задачу щойно відновили — не чіпаємо.
            if ($this->db->fetchOne('SELECT id FROM task WHERE id = ? AND deleted_at IS NOT NULL AND deleted_at < ? FOR UPDATE', [$id, $before]) === false) {
                return false;
            }

            $attachments = array_map('intval', $this->db->fetchFirstColumn(
                'SELECT a.id FROM task_attachment a JOIN task_message m ON m.id = a.message_id WHERE m.task_id = ?',
                [$id],
            ));

            if ($attachments !== []) {
                $this->db->executeStatement("DELETE FROM share_link WHERE file_type = 'task' AND file_id IN (" . implode(',', $attachments) . ')');
            }

            foreach (['search_document', 'plan_subject_activity', 'plan_read'] as $table) {
                $this->db->executeStatement(sprintf("DELETE FROM %s WHERE subject_type = 'task' AND subject_id = ?", $table), [$id]);
            }

            // Чат, вкладення (рядки), учасники, відділи, мітки, прочитане, ★ — каскадом.
            $this->db->executeStatement('DELETE FROM task WHERE id = ?', [$id]);
            $this->events->record(new SubjectDeleted(SubjectRef::task($id), null, title: $title, purged: true));

            return true;
        });
    }
}
