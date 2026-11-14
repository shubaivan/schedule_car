<?php

namespace App\Plan\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Строк зберігання журналу аудиту — PLAN_AUDIT_RETENTION_DAYS (за замовчуванням
 * 180 днів, рішення Івана 15.11: пів року на час обкатки). Один строк для всіх
 * подій plan_event: дій (вони ж Хроніка), входів, скачувань і переглядів.
 *
 * --with-crm-login — ще й рядки crm_login того ж віку (там IP і браузер). За
 * замовчуванням вимкнено: на них тримається «востаннє заходив» у журналі входів
 * складу. Без цього прапорця повторний plan:events:backfill переніс би видалені
 * входи назад — його запускають один раз, одразу після migrate.
 *
 * Це єдине місце в коді, що видаляє рядки plan_event. Видаляє порціями
 * (--batch), кожна — окрема коротка транзакція: журнал не блокується надовго.
 * Залежні рядки (plan_event_consumed, notification) ідуть каскадом.
 *
 *     php bin/console plan:audit:prune --dry-run        # лише порахувати
 *     php bin/console plan:audit:prune                  # крон щоночі 03:30
 */
#[AsCommand(name: 'plan:audit:prune', description: 'Видалити з журналу аудиту події, старші за строк зберігання (PLAN_AUDIT_RETENTION_DAYS, 180 днів)')]
final class AuditPruneCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        #[Autowire('%plan.audit_retention_days%')]
        private readonly int $retentionDays = AuditCatalog::DEFAULT_RETENTION_DAYS,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати, скільки буде видалено')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Строк зберігання, днів (за замовчуванням — PLAN_AUDIT_RETENTION_DAYS)')
            ->addOption('with-crm-login', null, InputOption::VALUE_NONE, 'Також видалити старі рядки crm_login (за тим самим строком)')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Рядків за одну транзакцію', '5000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $days = $input->getOption('days') === null ? $this->retentionDays : self::positive($input->getOption('days'));
        $batch = self::positive($input->getOption('batch'));

        if ($days === null || $days < 1 || $batch === null) {
            $io->error('--days (або PLAN_AUDIT_RETENTION_DAYS) і --batch мають бути додатними цілими.');

            return Command::INVALID;
        }

        $before = (new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv')))->modify(sprintf('-%d days', $days))->format('Y-m-d H:i:s');

        $events = $dryRun
            ? (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_event WHERE occurred_at < :before', ['before' => $before])
            : $this->deleteInBatches('plan_event', 'occurred_at < :before', ['before' => $before], $batch);
        $rows = [[sprintf('Події журналу (старші за %s, строк %d дн.)', $before, $days), $events]];

        $logins = 0;

        if ($input->getOption('with-crm-login')) {
            $logins = $dryRun
                ? (int) $this->db->fetchOne('SELECT COUNT(*) FROM crm_login WHERE at < :before', ['before' => $before])
                : $this->deleteInBatches('crm_login', 'at < :before', ['before' => $before], $batch);
            $rows[] = [sprintf('crm_login (старші за %s)', $before), $logins];
        }

        $io->table(['Що', $dryRun ? 'Буде видалено' : 'Видалено'], $rows);
        $io->success(sprintf('%s%d подій журналу і %d рядків crm_login.', $dryRun ? '[dry-run] Буде видалено ' : 'Видалено ', $events, $logins));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function deleteInBatches(string $table, string $where, array $params, int $batch): int
    {
        $deleted = 0;

        do {
            $count = $this->db->executeStatement(
                sprintf('DELETE FROM %1$s WHERE id IN (SELECT id FROM %1$s WHERE %2$s ORDER BY id LIMIT %3$d)', $table, $where, $batch),
                $params,
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
