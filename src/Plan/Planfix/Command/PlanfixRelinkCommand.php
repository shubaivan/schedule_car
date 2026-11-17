<?php

namespace App\Plan\Planfix\Command;

use App\Plan\Planfix\PlanfixImportGuard;
use App\Plan\Planfix\PlanfixRelink;
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
 * Злити заглушку людини Planfix із живою людиною Плану (після підтвердження, хто є хто):
 *
 *     php bin/console plan:planfix:relink <planfix id> <id людини в Плані> --dry-run
 *     PLANFIX_IMPORT_ALLOW=1 php bin/console plan:planfix:relink <planfix id> <id> --confirm=<назва бази>
 */
#[AsCommand(name: 'plan:planfix:relink', description: 'Заглушку людини Planfix злити з живою людиною Плану')]
final class PlanfixRelinkCommand extends Command
{
    public function __construct(private readonly PlanfixRelink $relink, private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('planfix-id', InputArgument::REQUIRED, 'id людини в Planfix')
            ->addArgument('user-id', InputArgument::REQUIRED, 'id людини в Плані')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати')
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Назва бази — обовʼязкове підтвердження');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if (! $dryRun && ($denied = PlanfixImportGuard::check($this->db, $input->getOption('confirm'))) !== null) {
            $io->error($denied);

            return Command::FAILURE;
        }

        try {
            $stats = $this->relink->relink((int) $input->getArgument('planfix-id'), (int) $input->getArgument('user-id'), $dryRun);
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%sPlanfix %d → людина #%d: заглушка %s, задач %d.',
            $dryRun ? 'Dry-run: ' : '',
            $input->getArgument('planfix-id'),
            $input->getArgument('user-id'),
            $stats['placeholder'] > 0 ? '#' . $stats['placeholder'] : 'немає',
            $stats['tasks'],
        ));

        return Command::SUCCESS;
    }
}
