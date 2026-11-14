<?php

namespace App\Plan\Search\Command;

use App\Plan\Search\SearchProjector;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Перебудувати пошуковий індекс із таблиць модулів:
 *
 *     php bin/console plan:search:reindex --dry-run      # скільки документів є / буде
 *     php bin/console plan:search:reindex                # усе
 *     php bin/console plan:search:reindex --type=supply --type=supply_comment
 *
 * Ідемпотентно: повторний запуск нічого не змінює (оновлюються лише рядки, що
 * розійшлися з джерелом; зайві видаляються). Синтетичні документи бенчмарка
 * не чіпає. Після першого заповнення — ANALYZE (робить сама команда).
 */
#[AsCommand(name: 'plan:search:reindex', description: 'Перебудувати search_document з задач, заявок, складу й людей')]
final class SearchReindexCommand extends Command
{
    public function __construct(private readonly SearchProjector $projector, private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Лише ці типи документів (task, comment, file, supply, wh_item, person…)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише порахувати, нічого не змінювати');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $types */
        $types = $input->getOption('type');
        $unknown = array_diff($types, $this->projector->docTypes());

        if ($unknown !== []) {
            $io->error('Невідомі типи: ' . implode(', ', $unknown) . '. Є: ' . implode(', ', $this->projector->docTypes()));

            return Command::INVALID;
        }

        if ($input->getOption('dry-run')) {
            $rows = [];

            foreach ($this->projector->stats() as $type => $s) {
                if ($types === [] || in_array($type, $types, true)) {
                    $rows[] = [$type, $s['source'], $s['indexed']];
                }
            }

            $io->table(['Тип', 'У джерелі', 'В індексі'], $rows);
            $io->note('Пробний запуск — індекс не змінено.');

            return Command::SUCCESS;
        }

        $started = microtime(true);
        $result = $this->projector->reindex($types === [] ? null : $types);
        $this->db->executeStatement('ANALYZE search_document');

        $io->table(
            ['Тип', 'Вставлено/оновлено', 'Видалено'],
            array_map(static fn (string $type, array $r) => [$type, $r['upserted'], $r['deleted']], array_keys($result), $result),
        );
        $io->success(sprintf('Індекс перебудовано за %.1f с.', microtime(true) - $started));

        return Command::SUCCESS;
    }
}
