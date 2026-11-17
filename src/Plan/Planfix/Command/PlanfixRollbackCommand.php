<?php

namespace App\Plan\Planfix\Command;

use App\Plan\Planfix\PlanfixImportGuard;
use App\Plan\Planfix\PlanfixRollback;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Відкат партії імпорту з Planfix — видаляє рівно ті рядки, що записані в planfix_import_map.
 *
 *     php bin/console plan:planfix:rollback pf-1 --dry-run
 *     PLANFIX_IMPORT_ALLOW=1 php bin/console plan:planfix:rollback pf-1 --confirm=<назва бази>
 *
 * Якщо в задачах партії люди вже щось зробили (повідомлення, файли, учасники, ★), відкат
 * відмовляє й показує, що саме. Тоді: --keep-touched — такі задачі лишити (прибрати з них
 * лише імпортоване), --force-delete-user-content — видалити разом із чужим.
 * --purge-staging — ще й прибрати персональні дані зі staging (карта й партія лишаються).
 */
#[AsCommand(name: 'plan:planfix:rollback', description: 'Відкат партії імпорту з Planfix (рівно її рядки)')]
final class PlanfixRollbackCommand extends Command
{
    public function __construct(private readonly PlanfixRollback $rollback, private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('batch', InputArgument::REQUIRED, 'Ідентифікатор партії')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише порахувати')
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Назва бази — обовʼязкове підтвердження')
            ->addOption('keep-touched', null, InputOption::VALUE_NONE, 'Задачі з чужим вмістом лишити, прибрати з них лише імпортоване')
            ->addOption('force-delete-user-content', null, InputOption::VALUE_NONE, 'Видалити й задачі з чужим вмістом (разом із ним)')
            ->addOption('purge-staging', null, InputOption::VALUE_NONE, 'Прибрати й staging партії (персональні дані)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batch = (string) $input->getArgument('batch');
        $dryRun = (bool) $input->getOption('dry-run');
        $keep = (bool) $input->getOption('keep-touched');
        $force = (bool) $input->getOption('force-delete-user-content');

        if ($keep && $force) {
            $io->error('Або --keep-touched, або --force-delete-user-content.');

            return Command::INVALID;
        }

        if (! $dryRun && ($denied = PlanfixImportGuard::check($this->db, $input->getOption('confirm'))) !== null) {
            $io->error('Відкат видаляє задачі. ' . $denied);

            return Command::FAILURE;
        }

        $mode = $force ? PlanfixRollback::FORCE : ($keep ? PlanfixRollback::KEEP_TOUCHED : PlanfixRollback::REFUSE);

        try {
            // Спершу підрахунок (нічого не видаляє) — попередження до видалення, не після.
            $plan = $this->rollback->rollback($batch, dryRun: true);
            $this->table($io, $batch . ' [перевірка]', $plan);

            if ($plan['touched_tasks'] > 0) {
                $text = sprintf(
                    'У %d задачах партії люди вже працювали в Плані: повідомлень %d, файлів %d, учасників %d, ★ задач %d, ★ повідомлень %d.',
                    $plan['touched_tasks'],
                    $plan['foreign_messages'],
                    $plan['foreign_files'],
                    $plan['foreign_members'],
                    $plan['foreign_favorites'],
                    $plan['foreign_stars'],
                );

                if (! $dryRun && $mode === PlanfixRollback::REFUSE) {
                    $io->error($text . ' Нічого не видалено. --keep-touched — лишити ці задачі; --force-delete-user-content — видалити разом із цим.');

                    return Command::FAILURE;
                }

                $io->warning($text . ($mode === PlanfixRollback::FORCE ? ' Буде ВИДАЛЕНО разом із задачами.' : ($mode === PlanfixRollback::KEEP_TOUCHED ? ' Ці задачі лишаться, з них прибереться лише імпортоване.' : '')));
            }

            if ($dryRun) {
                $io->success('Dry-run: нічого не видалено.');

                return Command::SUCCESS;
            }

            $stats = $this->rollback->rollback($batch, false, $mode, (bool) $input->getOption('purge-staging'));
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->table($io, $batch, $stats);
        $io->success('Партію відкочено.');

        return Command::SUCCESS;
    }

    /** @param array<string, int> $stats */
    private function table(SymfonyStyle $io, string $title, array $stats): void
    {
        $io->table(['Партія ' . $title, 'Кількість'], array_map(static fn ($k, $v) => [$k, $v], array_keys($stats), $stats));
    }
}
