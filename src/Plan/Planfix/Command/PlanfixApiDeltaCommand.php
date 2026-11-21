<?php

namespace App\Plan\Planfix\Command;

use App\Plan\Planfix\PlanfixApiDelta;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Добір із Planfix API після повного вивантаження → тека вигляду вивантаження. Задач Плану не
 * чіпає; далі звичайний імпорт тієї теки з тією самою картою людей:
 *
 *     PLANFIX_API_TOKEN=… php bin/console plan:planfix:api-delta <тека вивантаження> <нова тека>
 *     php bin/console plan:planfix:import <нова тека> --files=<нова тека> --batch=pf-api-… --all-done --user-map=… --system-ids=…
 *
 * Повторний запуск (новою текою й партією) безпечний: уже перенесені задачі, коментарі й файли
 * карта імпорту вдруге не пише.
 */
#[AsCommand(name: 'plan:planfix:api-delta', description: 'Добрати з Planfix API нові задачі й коментарі після вивантаження (лише читання Planfix)')]
final class PlanfixApiDeltaCommand extends Command
{
    public const string TOKEN_ENV = 'PLANFIX_API_TOKEN';

    public function __construct(private readonly PlanfixApiDelta $delta)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('base', InputArgument::REQUIRED, 'Тека повного вивантаження (tasks.json, actions.json, users.json)')
            ->addArgument('out', InputArgument::REQUIRED, 'Нова тека для добору (буде створена)')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Адреса Planfix REST', PlanfixApiDelta::BASE_URL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // Лише справжнє середовище процесу: токен не з .env і не з аргументів (історія shell).
        $token = trim((string) getenv(self::TOKEN_ENV));

        if ($token === '') {
            $io->error(sprintf('Потрібна змінна середовища процесу %s.', self::TOKEN_ENV));

            return Command::INVALID;
        }

        $out = (string) $input->getArgument('out');

        if (is_dir($out) && (glob(rtrim($out, '/') . '/*.json') ?: []) !== []) {
            $io->error('Тека добору вже містить json — візьміть нову (кожен добір — своя тека й партія).');

            return Command::INVALID;
        }

        if (ini_get('memory_limit') !== '-1') {
            ini_set('memory_limit', '2G');
        }

        try {
            $stats = $this->delta->build((string) $input->getArgument('base'), $out, $token, (string) $input->getOption('base-url'), static fn (string $m) => $io->writeln($m));
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->table(['Добір', 'Кількість'], array_map(static fn ($k, $v) => [$k, $v], array_keys($stats), $stats));
        $io->success(sprintf('Тека %s готова. Далі: plan:planfix:import %1$s --files=%1$s --batch=<нова партія> --all-done --user-map=… --system-ids=… (спершу dry-run).', $out));

        return Command::SUCCESS;
    }
}
