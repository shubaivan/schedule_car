<?php

namespace App\Plan\Planfix\Command;

use App\Plan\Planfix\PlanfixStaging;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Прибрати персональні дані партії зі staging (planfix_import_task, _action, _user — копія
 * вивантаження: ФІО, телефони, тексти). Карта й рядок партії лишаються: на них тримаються
 * ідемпотентність повторного імпорту й відкат. Задач Плану не чіпає.
 *
 *     php bin/console plan:planfix:purge-staging pf-1 [--dry-run]
 */
#[AsCommand(name: 'plan:planfix:purge-staging', description: 'Прибрати персональні дані партії Planfix зі staging (карта лишається)')]
final class PlanfixPurgeStagingCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('batch', InputArgument::REQUIRED, 'Ідентифікатор партії')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише порахувати');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batch = (string) $input->getArgument('batch');

        if ($this->db->fetchOne('SELECT 1 FROM planfix_import_batch WHERE id = ?', [$batch]) === false) {
            $io->error(sprintf('Партії «%s» немає.', $batch));

            return Command::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            $rows = 0;

            foreach (['planfix_import_action', 'planfix_import_task', 'planfix_import_user'] as $table) {
                $rows += (int) $this->db->fetchOne("SELECT COUNT(*) FROM {$table} WHERE batch_id = ?", [$batch]);
            }

            $io->success(sprintf('Dry-run: буде прибрано %d рядків staging партії %s.', $rows, $batch));

            return Command::SUCCESS;
        }

        $rows = $this->db->transactional(fn (): int => PlanfixStaging::purge($this->db, $batch));
        $io->success(sprintf('Прибрано %d рядків staging партії %s; карта й партія лишились.', $rows, $batch));

        return Command::SUCCESS;
    }
}
