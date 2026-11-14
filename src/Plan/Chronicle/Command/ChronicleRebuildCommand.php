<?php

namespace App\Plan\Chronicle\Command;

use App\Plan\Chronicle\PlanChronicle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Перебудувати plan_subject_activity (рядки Хроніки) з журналу plan_event:
 *
 *     php bin/console plan:chronicle:rebuild --dry-run
 *     php bin/console plan:chronicle:rebuild
 *     php bin/console plan:chronicle:rebuild --mark-history-read   # перший раз після backfill
 *
 * Запускати після `plan:events:backfill` (історія потрапила в журнал без
 * розсилки споживачам). Ідемпотентно: повтор нічого не змінює.
 */
#[AsCommand(name: 'plan:chronicle:rebuild', description: 'Рядки Хроніки (plan_subject_activity) з журналу plan_event')]
final class ChronicleRebuildCommand extends Command
{
    public function __construct(private readonly PlanChronicle $chronicle)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише порахувати')
            ->addOption('mark-history-read', null, InputOption::VALUE_NONE, 'Першому запуску на стенді: стара історія заявок і складу — прочитана для всіх (без «+N» на кожній старій заявці)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('dry-run')) {
            $io->writeln(sprintf('Суб\'єктів із новинами в журналі: %d (пробний запуск, нічого не змінено).', $this->chronicle->subjectsInJournal()));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Оновлено рядків Хроніки: %d.', $this->chronicle->rebuild()));

        if ($input->getOption('mark-history-read')) {
            $io->success(sprintf('Позначено прочитаним (заявки, склад): %d.', $this->chronicle->markHistoryRead()));
        }

        return Command::SUCCESS;
    }
}
