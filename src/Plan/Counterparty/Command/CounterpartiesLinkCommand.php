<?php

namespace App\Plan\Counterparty\Command;

use App\Plan\Counterparty\Entity\Counterparty;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Привʼязати старі довідники до карток контрагентів: постачальника заявок
 * (supply_supplier) і клієнта складу (wh_client). Спершу за кодом (ЄДРПОУ /
 * РНОКПП), далі — за нормалізованою назвою, лише якщо збіг однозначний.
 * Нічого не створює й не перепривʼязує вже привʼязаних. Повторний запуск безпечний.
 *
 *     php bin/console plan:counterparties:link --dry-run
 *     php bin/console plan:counterparties:link
 *
 * Запускати після імпорту контрагентів з 1С.
 */
#[AsCommand(name: 'plan:counterparties:link', description: 'Привʼязати постачальників заявок і клієнтів складу до карток контрагентів (за кодом, потім за назвою)')]
final class CounterpartiesLinkCommand extends Command
{
    private const array TABLES = ['supply_supplier' => 'Постачальник заявок', 'wh_client' => 'Клієнт складу'];

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати, що буде привʼязано');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');

        $byCode = [];
        $byName = [];

        $codeOf = [];

        // Архівні картки не привʼязуємо: їх прибрали свідомо.
        foreach ($this->db->fetchAllAssociative('SELECT id, code, name_normalized FROM counterparty WHERE archived_at IS NULL') as $c) {
            if ($c['code'] !== null) {
                $byCode[(string) $c['code']] = (int) $c['id'];
                $codeOf[(int) $c['id']] = (string) $c['code'];
            }

            $byName[(string) $c['name_normalized']][] = (int) $c['id'];
        }

        $rows = [];
        $linked = 0;

        foreach (self::TABLES as $table => $label) {
            foreach ($this->db->fetchAllAssociative(sprintf('SELECT id, name, edrpou FROM %s WHERE counterparty_id IS NULL ORDER BY id', $table)) as $r) {
                $code = (string) preg_replace('/\D+/', '', (string) $r['edrpou']);
                $names = $byName[Counterparty::normalize((string) $r['name'])] ?? [];
                // За назвою — лише коли коди не суперечать: в обох різні ЄДРПОУ — це різні юрособи.
                $conflict = count($names) === 1 && $code !== '' && isset($codeOf[$names[0]]) && $codeOf[$names[0]] !== $code;
                [$target, $how] = match (true) {
                    $code !== '' && isset($byCode[$code]) => [$byCode[$code], 'код'],
                    $conflict => [null, 'та сама назва, інший код — вручну'],
                    count($names) === 1 => [$names[0], 'назва'],
                    default => [null, count($names) > 1 ? 'кілька збігів за назвою — вручну' : 'немає картки'],
                };

                $rows[] = [$label, $r['name'], $r['edrpou'] ?? '—', $target ?? '—', $how];

                if ($target !== null) {
                    ++$linked;

                    if (! $dry) {
                        $this->db->executeStatement(sprintf('UPDATE %s SET counterparty_id = ? WHERE id = ? AND counterparty_id IS NULL', $table), [$target, $r['id']]);
                    }
                }
            }
        }

        if ($rows !== []) {
            $io->table(['Довідник', 'Назва', 'Код', 'Контрагент', 'Як'], $rows);
        }

        $io->success(sprintf('%s: %d з %d.', $dry ? 'Буде привʼязано (dry-run)' : 'Привʼязано', $linked, count($rows)));

        return Command::SUCCESS;
    }
}
