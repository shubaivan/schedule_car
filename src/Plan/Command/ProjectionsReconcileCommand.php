<?php

namespace App\Plan\Command;

use App\Plan\EventStore\FanOutHandler;
use App\Plan\EventStore\PlanEventStore;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Страховка проєкцій: події за останні --hours (24), які ідемпотентний споживач
 * (пошук, Хроніка, центр сповіщень — IdempotentConsumer) так і не позначив у
 * plan_event_consumed, — роздати йому ще раз. Буває, коли повтори Messenger
 * вичерпано (подія в черзі failed), у sync-режимі стендів (повторів там немає)
 * або воркер лежав.
 *
 * Telegram і Web Push звідси не йдуть: споживачі з зовнішнім ефектом не
 * ідемпотентні й сюди не потрапляють, а NotificationFanout шле Mercure/Push лише
 * для щойно вставлених рядків notification. Перенесена історія (backfill) і
 * демо-дані пропускаються — вони не мали й не мають розсилки.
 *
 * Вікно — менше за 7 днів, які plan:audit:prune тримає plan_event_consumed.
 * Свіжіші за --min-age хвилин (5) не чіпаються: їх, найпевніше, ще обробляє воркер.
 *
 *     php bin/console plan:projections:reconcile --dry-run
 *     php bin/console plan:projections:reconcile            # крон щогодини (deploy/plan.cron)
 *
 * Повне вирівнювання з нуля — plan:search:reindex і plan:chronicle:rebuild.
 */
#[AsCommand(name: 'plan:projections:reconcile', description: 'Дороздати пошуку, Хроніці й центру сповіщень події, які вони пропустили (страховка FanOut)')]
final class ProjectionsReconcileCommand extends Command
{
    private const int MAX_HOURS = 144;

    public function __construct(
        private readonly Connection $db,
        private readonly PlanEventStore $store,
        private readonly FanOutHandler $fanOut,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'За скільки годин переглянути події (≤ 144)', '24')
            ->addOption('min-age', null, InputOption::VALUE_REQUIRED, 'Не чіпати події, молодші за стільки хвилин', '5')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати, скільки пропущено');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hours = filter_var($input->getOption('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_HOURS]]);
        $minAge = filter_var($input->getOption('min-age'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1440]]);

        if ($hours === false || $minAge === false) {
            $io->error(sprintf('--hours — від 1 до %d, --min-age — від 0 до 1440.', self::MAX_HOURS));

            return Command::INVALID;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
        $from = $now->modify(sprintf('-%d hours', $hours))->format('Y-m-d H:i:s');
        $to = $now->modify(sprintf('-%d minutes', $minAge))->format('Y-m-d H:i:s');
        $rows = [];
        $total = 0;
        $failed = 0;

        foreach ($this->fanOut->idempotentConsumers() as $consumer) {
            $ids = array_map('intval', $this->db->fetchFirstColumn(
                "SELECT e.id FROM plan_event e
                  WHERE e.occurred_at >= ? AND e.occurred_at <= ?
                    AND e.payload->>'backfill' IS NULL AND e.payload->>'demoSeed' IS NULL
                    AND NOT EXISTS (SELECT 1 FROM plan_event_consumed c WHERE c.event_id = e.id AND c.consumer = ?)
                  ORDER BY e.id",
                [$from, $to, FanOutHandler::consumerKey($consumer::class)],
            ));
            $done = 0;

            if (! $input->getOption('dry-run')) {
                foreach ($ids as $id) {
                    $event = $this->store->load($id);

                    if ($event !== null && $this->fanOut->deliver($event, $consumer)) {
                        ++$done;
                    } elseif ($event !== null) {
                        ++$failed;
                    }
                }
            }

            $rows[] = [(new ReflectionClass($consumer))->getShortName(), count($ids), $input->getOption('dry-run') ? '—' : $done];
            $total += count($ids);
        }

        $io->table(['Споживач', 'Пропущено подій', 'Дороздано'], $rows);

        if ($failed > 0) {
            $io->warning(sprintf('%d подій знову не вдалось (див. лог «проєкція події впала»).', $failed));

            return Command::FAILURE;
        }

        $io->success(sprintf('%sПропущених подій за %d год: %d.', $input->getOption('dry-run') ? '[dry-run] ' : '', $hours, $total));

        return Command::SUCCESS;
    }
}
