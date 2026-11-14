<?php

namespace App\Plan\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Строк зберігання журналу аудиту:
 * - доменні події (створення, статуси, коментарі, файли… — вони ж Хроніка) — 36 міс;
 * - журнал доступу (входи, невдалі входи, скачування, перегляди) — 12 міс;
 *   разом із ним — рядки crm_login того ж віку (там IP і браузер), інакше
 *   plan:events:backfill переніс би видалені входи назад.
 *
 * Це єдине місце в коді, що видаляє рядки plan_event. Видаляє порціями
 * (--batch), кожна — окрема коротка транзакція: журнал не блокується надовго.
 * Залежні рядки (plan_event_consumed, notification) ідуть каскадом.
 *
 *     php bin/console plan:audit:prune --dry-run        # лише порахувати
 *     php bin/console plan:audit:prune                  # крон щоночі 03:30
 */
#[AsCommand(name: 'plan:audit:prune', description: 'Видалити з журналу аудиту події, старші за строк зберігання (36/12 міс)')]
final class AuditPruneCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати, скільки буде видалено')
            ->addOption('domain-months', null, InputOption::VALUE_REQUIRED, 'Строк для доменних подій, міс', (string) AuditCatalog::DOMAIN_MONTHS)
            ->addOption('access-months', null, InputOption::VALUE_REQUIRED, 'Строк для входів, скачувань і переглядів, міс', (string) AuditCatalog::ACCESS_MONTHS)
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Рядків за одну транзакцію', '5000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $domainMonths = self::positive($input->getOption('domain-months'));
        $accessMonths = self::positive($input->getOption('access-months'));
        $batch = self::positive($input->getOption('batch'));

        if ($domainMonths === null || $accessMonths === null || $batch === null) {
            $io->error('--domain-months, --access-months і --batch мають бути додатними цілими.');

            return Command::INVALID;
        }

        // Журнал доступу не живе довше за доменний — інакше правило не має сенсу.
        if ($accessMonths > $domainMonths) {
            $io->error('--access-months не може бути більшим за --domain-months.');

            return Command::INVALID;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
        $domainBefore = $now->modify(sprintf('-%d months', $domainMonths))->format('Y-m-d H:i:s');
        $accessBefore = $now->modify(sprintf('-%d months', $accessMonths))->format('Y-m-d H:i:s');

        $scopes = [
            'access' => [
                'where' => 'type IN (:types) AND occurred_at < :before',
                'params' => ['types' => AuditCatalog::ACCESS_TYPES, 'before' => $accessBefore],
                'label' => sprintf('Входи, скачування, перегляди (старші за %s)', $accessBefore),
            ],
            'domain' => [
                'where' => 'type NOT IN (:types) AND occurred_at < :before',
                'params' => ['types' => AuditCatalog::ACCESS_TYPES, 'before' => $domainBefore],
                'label' => sprintf('Доменні події (старші за %s)', $domainBefore),
            ],
        ];
        $types = ['types' => ArrayParameterType::STRING];

        $rows = [];
        $total = 0;

        foreach ($scopes as $scope) {
            $count = $dryRun
                ? (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_event WHERE ' . $scope['where'], $scope['params'], $types)
                : $this->deleteInBatches('plan_event', $scope['where'], $scope['params'], $types, $batch);
            $rows[] = [$scope['label'], $count];
            $total += $count;
        }

        $logins = $dryRun
            ? (int) $this->db->fetchOne('SELECT COUNT(*) FROM crm_login WHERE at < :before', ['before' => $accessBefore])
            : $this->deleteInBatches('crm_login', 'at < :before', ['before' => $accessBefore], [], $batch);
        $rows[] = [sprintf('crm_login (старші за %s)', $accessBefore), $logins];

        $io->table(['Що', $dryRun ? 'Буде видалено' : 'Видалено'], $rows);
        $io->success(sprintf('%s%d подій журналу і %d рядків crm_login.', $dryRun ? '[dry-run] Буде видалено ' : 'Видалено ', $total, $logins));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|int|string> $types
     */
    private function deleteInBatches(string $table, string $where, array $params, array $types, int $batch): int
    {
        $deleted = 0;

        do {
            $count = $this->db->executeStatement(
                sprintf('DELETE FROM %1$s WHERE id IN (SELECT id FROM %1$s WHERE %2$s ORDER BY id LIMIT %3$d)', $table, $where, $batch),
                $params,
                $types,
            );
            $deleted += (int) $count;
        } while ($count >= $batch);

        return $deleted;
    }

    private static function positive(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^[1-9]\d{0,5}$/', $value) === 1 ? (int) $value : null;
    }
}
