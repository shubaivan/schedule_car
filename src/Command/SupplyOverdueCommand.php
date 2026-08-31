<?php

namespace App\Command;

use App\Supply\Entity\SupplyRequest;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\SupplyNotifier;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
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
 * По кожній заявці нагадує ОДИН раз на строк: після відправки ставить мітку
 * overdueNotifiedAt, і завтрашній запуск цю заявку вже не візьме. Перенесли
 * строк — заявка знову потрапить у список. Без мітки (як було до 31.08.2026)
 * менеджер щоранку отримував той самий десяток повідомлень і перестав читати.
 *
 * --dry-run показує список, нікого не смикаючи і мітку не ставлячи.
 * --force шле повторно навіть по тих, де мітка вже стоїть.
 */
#[AsCommand(name: 'supply:overdue', description: 'Нагадування по прострочених заявках')]
class SupplyOverdueCommand extends Command
{
    public function __construct(
        private SupplyRequestRepository $requests,
        private SupplyNotifier $notifier,
        private EntityManagerInterface $em,
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

        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Нагадати й по тих заявках, по яких на цей строк уже нагадували',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $overdue = $this->requests->findOverdue(includeReminded: (bool) $input->getOption('force'));

        if (! $overdue) {
            $io->success('Прострочених заявок, по яких ще не нагадували, немає.');

            return Command::SUCCESS;
        }

        $io->section(sprintf('Прострочених заявок: %d', count($overdue)));

        foreach ($overdue as $request) {
            $io->writeln($this->line($request));

            if (! $dryRun) {
                $this->notifier->overdue($request);
                $request->setOverdueNotifiedAt(new DateTime('now', new DateTimeZone('Europe/Kyiv')));
            }
        }

        if (! $dryRun) {
            $this->em->flush();
        }

        $io->success($dryRun
            ? 'Це був сухий запуск — нічого не відправлено.'
            : 'Нагадування відправлені менеджерам.');

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
