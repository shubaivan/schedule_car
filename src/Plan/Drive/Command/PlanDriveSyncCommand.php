<?php

namespace App\Plan\Drive\Command;

use App\Plan\Drive\DriveClientFactory;
use App\Plan\Drive\DriveSync;
use App\Plan\Drive\DriveSyncResult;
use App\Plan\Drive\Source\DriveSource;
use App\Plan\Share\FileType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Копіює файли «Плану» (задачі, заявки, склад) на Google Диск деревом
 * План/<рік>/<Задачі|Постачання|Склад>/№… назва/ (файли — «РРРР-ММ-ДД назва»). Крон на стенді План — раз на 5 хв:
 *
 *     0-59/5 * * * * deploy php8.5 /var/www/plan/bin/console plan:drive-sync --limit=50 -q
 *
 * Працює лише з GOOGLE_DRIVE_LAYOUT=plan; на стендах заявок дзеркало веде
 * supply:drive-sync старим деревом (воно не змінюється).
 */
#[AsCommand(name: 'plan:drive-sync', description: 'Скопіювати файли «Плану» на Google Диск (черга з повторами)')]
class PlanDriveSyncCommand extends Command
{
    /** @var list<DriveSource> */
    private array $sources;

    /** @param iterable<DriveSource> $sources */
    public function __construct(
        private readonly DriveSync $sync,
        private readonly DriveClientFactory $drives,
        private readonly Connection $db,
        #[AutowireIterator('plan.drive_source')]
        iterable $sources,
    ) {
        parent::__construct();
        $this->sources = [...$sources];
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Скільки файлів кожного типу за раз', '50')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати, що й куди поїде, — без звернень до Диска')
            ->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Спершу повернути в чергу всі файли зі станом failed')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Зняти зупинку після invalid_grant (новий токен уже в .env.local) і паузу квоти');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        if (! $this->drives->isPlanLayout()) {
            $io->note('GOOGLE_DRIVE_LAYOUT ≠ plan — це стенд заявок, його дзеркало веде supply:drive-sync. Нічого не робимо.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            return $this->dryRun($io, $limit);
        }

        if ($input->getOption('resume')) {
            $this->sync->resume();
            $io->writeln('Зупинку й паузу знято.');
        }

        if ($input->getOption('retry-failed')) {
            $io->writeln(sprintf('Повернуто в чергу: %d.', $this->sync->retryAllFailed()));
        }

        $client = $this->drives->client();

        if ($client === null) {
            $waiting = $this->sync->waiting();

            if ($waiting > 0) {
                $io->warning(sprintf('Google Drive не налаштований (немає GOOGLE_DRIVE_* в оточенні), файлів у черзі: %d. Вони лежать у нашому сховищі.', $waiting));
            }

            return Command::SUCCESS;
        }

        $result = $this->sync->run($client, $limit);

        foreach ($result->lines as $line) {
            $io->writeln($line);
        }

        $summary = sprintf('Скопійовано: %d, уже були на Диску: %d, не вдалось: %d, пропущено: %d.', $result->sent, $result->alreadyThere, $result->failed, $result->skipped);

        match ($result->stopped) {
            DriveSyncResult::STOP_AUTH => $io->error($summary . ' Доступ до Диска відкликано: видайте новий токен (supply:drive-auth) і запустіть із --resume.'),
            DriveSyncResult::STOP_QUOTA => $io->warning($summary . ' Квота Google — продовжимо за годину.'),
            DriveSyncResult::STOP_LOCKED => $io->note('Попередній прогін ще працює.'),
            default => $result->failed > 0 ? $io->warning($summary) : $io->success($summary),
        };

        // Збої файлів — не збій команди: крон не має засипати пошту, стан видно в адмінці.
        return $result->stopped === DriveSyncResult::STOP_AUTH ? Command::FAILURE : Command::SUCCESS;
    }

    private function dryRun(SymfonyStyle $io, int $limit): int
    {
        $io->writeln($this->drives->isConfigured() ? 'Диск налаштований.' : 'Диск НЕ налаштований (немає GOOGLE_DRIVE_*).');
        $total = 0;

        foreach ($this->sources as $source) {
            $type = $source->type();
            $ids = $this->db->fetchFirstColumn(sprintf("SELECT id FROM %s WHERE drive_file_id IS NULL AND drive_state = 'pending' ORDER BY id LIMIT %d", $type->table(), $limit));

            foreach ($ids as $id) {
                $item = $source->item((int) $id);
                $io->writeln($item !== null ? '  → ' . $item->path() : sprintf('  – %s:%d — видалено, буде пропущено', $type->value, $id));
                ++$total;
            }
        }

        $failed = 0;

        foreach (FileType::cases() as $type) {
            $failed += (int) $this->db->fetchOne(sprintf("SELECT COUNT(*) FROM %s WHERE drive_file_id IS NULL AND drive_state = 'failed'", $type->table()));
        }

        $io->success(sprintf('[dry-run] У черзі (до %d кожного типу): %d. Зі станом failed: %d. Нічого не відправлено.', $limit, $total, $failed));

        return Command::SUCCESS;
    }
}
