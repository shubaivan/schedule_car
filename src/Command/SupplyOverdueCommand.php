<?php

namespace App\Command;

use App\Supply\Entity\SupplyRequest;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\SupplyNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Нагадати про прострочені заявки:
 *
 *     php bin/console supply:overdue
 *
 * Сповіщення по простроченню були написані (SupplyNotifier::overdue), але
 * викликати їх не було кому: заявка тихо протухала, а «прострочено» бачив лише
 * той, хто сам відкрив картку. Ця команда і є той, хто натискає.
 *
 * Розрахована на ОДИН запуск на добу — нагадування шле щоразу, без памʼяті про
 * попередній раз. Частіше вішати на крон не можна: заявник отримає те саме
 * повідомлення десять разів на день і перестане їх читати.
 */
#[AsCommand(name: 'supply:overdue', description: 'Нагадування по прострочених заявках')]
class SupplyOverdueCommand extends Command
{
    public function __construct(
        private SupplyRequestRepository $requests,
        private SupplyNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Тільки показати, по яких заявках пішло б нагадування',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $overdue = $this->requests->findOverdue();

        if (! $overdue) {
            $io->success('Прострочених заявок немає.');

            return Command::SUCCESS;
        }

        $io->section(sprintf('Прострочених заявок: %d', count($overdue)));

        foreach ($overdue as $request) {
            $io->writeln($this->line($request));

            if (! $dryRun) {
                $this->notifier->overdue($request);
            }
        }

        $io->success($dryRun
            ? 'Це був сухий запуск — нічого не відправлено.'
            : 'Нагадування відправлені заявникам і менеджерам.');

        return Command::SUCCESS;
    }

    private function line(SupplyRequest $request): string
    {
        return sprintf(
            '  №%s · %s · %s · потрібно було до %s · %s',
            $request->getNumber(),
            $request->getItem(),
            $request->getQuantityLabel(),
            $request->getNeedBy()?->format('d.m.Y') ?? '—',
            $request->getStatus()->label(),
        );
    }
}
