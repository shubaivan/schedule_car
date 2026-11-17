<?php

namespace App\Plan\Planfix\Command;

use App\Plan\Planfix\PlanfixImportGuard;
use App\Plan\Planfix\PlanfixRollback;
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
 *     PLANFIX_IMPORT_ALLOW=1 php bin/console plan:planfix:rollback pf-1 [--purge-staging]
 *
 * --purge-staging — ще й прибрати staging партії (у ньому копія вивантаження з персональними даними).
 */
#[AsCommand(name: 'plan:planfix:rollback', description: 'Відкат партії імпорту з Planfix (рівно її рядки)')]
final class PlanfixRollbackCommand extends Command
{
    public function __construct(private readonly PlanfixRollback $rollback)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('batch', InputArgument::REQUIRED, 'Ідентифікатор партії')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише порахувати')
            ->addOption('purge-staging', null, InputOption::VALUE_NONE, 'Видалити й staging-таблиці партії');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if (! $dryRun && ! PlanfixImportGuard::allowed()) {
            $io->error('Відкат видаляє задачі. Запустіть з ' . PlanfixImportGuard::ENV . '=1 у середовищі процесу або з --dry-run.');

            return Command::FAILURE;
        }

        try {
            $stats = $this->rollback->rollback((string) $input->getArgument('batch'), $dryRun, (bool) $input->getOption('purge-staging'));
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->table(['Партія ' . $input->getArgument('batch') . ($dryRun ? ' [dry-run]' : ''), 'Кількість'], array_map(static fn ($k, $v) => [$k, $v], array_keys($stats), $stats));

        if ($stats['foreign_messages'] > 0) {
            $io->warning(sprintf('У задачах партії є %d повідомлень, написаних уже в Плані — вони %s разом із задачами.', $stats['foreign_messages'], $dryRun ? 'зникнуть' : 'видалені'));
        }

        $io->success($dryRun ? 'Dry-run: нічого не видалено.' : 'Партію відкочено.');

        return Command::SUCCESS;
    }
}
