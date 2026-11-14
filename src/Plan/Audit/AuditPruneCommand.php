<?php

namespace App\Plan\Audit;

use App\Plan\Event\PlanEventTypes;
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
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Строк зберігання журналу аудиту — PLAN_AUDIT_RETENTION_DAYS (за замовчуванням
 * 180 днів, рішення Івана 15.11: пів року на час обкатки).
 *
 * Видаляються ЛИШЕ події «тільки для аудиту» — PlanEventTypes::AUDIT_ONLY: входи,
 * невдалі входи, перегляди й скани, скачування, поширення, службові Диска, зміни
 * карток людей. Доменна історія (створення, статуси, коментарі, файли, учасники,
 * закупівлі, рухи складу) НЕ видаляється ніколи: на ній тримаються Хроніка
 * (plan_subject_activity.last_event_id) і історія карток складу (ItemFeed).
 *
 * Заодно — службова plan_event_consumed (хто з споживачів подію вже взяв) старша
 * за --consumed-days (7): вона потрібна лише проти повторної доставки й для
 * plan:projections:reconcile (вікно ≤ 6 діб), а росла б без меж.
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
 *     php bin/console plan:audit:prune                  # крон щоночі 03:30 (deploy/plan.cron)
 */
#[AsCommand(name: 'plan:audit:prune', description: 'Видалити з журналу аудиту службові події (входи, перегляди, скачування…), старші за PLAN_AUDIT_RETENTION_DAYS; доменна історія лишається')]
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
            ->addOption('consumed-days', null, InputOption::VALUE_REQUIRED, 'Скільки днів тримати plan_event_consumed', '7')
            ->addOption('with-crm-login', null, InputOption::VALUE_NONE, 'Також видалити старі рядки crm_login (за тим самим строком)')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Рядків за одну транзакцію', '5000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $days = $input->getOption('days') === null ? $this->retentionDays : self::positive($input->getOption('days'));
        $batch = self::positive($input->getOption('batch'));
        $consumedDays = self::positive($input->getOption('consumed-days'));

        if ($days === null || $days < 1 || $batch === null || $consumedDays === null) {
            $io->error('--days (або PLAN_AUDIT_RETENTION_DAYS), --consumed-days і --batch мають бути додатними цілими.');

            return Command::INVALID;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
        $before = $now->modify(sprintf('-%d days', $days))->format('Y-m-d H:i:s');
        $types = array_map(static fn (string $class) => $class::type(), PlanEventTypes::AUDIT_ONLY);
        // Лише службові типи: доменна історія (Хроніка, склад) не чіпається ніколи.
        $where = 'occurred_at < :before AND type IN (:types)';
        $params = ['before' => $before, 'types' => $types];
        $paramTypes = ['types' => ArrayParameterType::STRING];

        $events = $dryRun
            ? (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_event WHERE ' . $where, $params, $paramTypes)
            : $this->deleteInBatches('plan_event', 'id', $where, $params, $batch, $paramTypes);
        $rows = [[sprintf('Службові події журналу (входи, перегляди, скачування…; старші за %s, строк %d дн.)', $before, $days), $events]];

        $consumedBefore = $now->modify(sprintf('-%d days', $consumedDays))->format('Y-m-d H:i:s');
        $consumed = $dryRun
            ? (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_event_consumed WHERE consumed_at < :before', ['before' => $consumedBefore])
            : $this->deleteInBatches('plan_event_consumed', 'ctid', 'consumed_at < :before', ['before' => $consumedBefore], $batch * 4);
        $rows[] = [sprintf('plan_event_consumed (старші за %s)', $consumedBefore), $consumed];

        $logins = 0;

        if ($input->getOption('with-crm-login')) {
            $logins = $dryRun
                ? (int) $this->db->fetchOne('SELECT COUNT(*) FROM crm_login WHERE at < :before', ['before' => $before])
                : $this->deleteInBatches('crm_login', 'id', 'at < :before', ['before' => $before], $batch);
            $rows[] = [sprintf('crm_login (старші за %s)', $before), $logins];
        }

        $io->table(['Що', $dryRun ? 'Буде видалено' : 'Видалено'], $rows);
        $io->success(sprintf('%s%d службових подій журналу, %d рядків plan_event_consumed і %d рядків crm_login.', $dryRun ? '[dry-run] Буде видалено ' : 'Видалено ', $events, $consumed, $logins));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType> $types
     */
    private function deleteInBatches(string $table, string $key, string $where, array $params, int $batch, array $types = []): int
    {
        $deleted = 0;

        do {
            $count = (int) $this->db->executeStatement(
                // ctid (у plan_event_consumed немає id) — через ANY(ARRAY(...)): так PostgreSQL 14 бере TID Scan.
                $key === 'ctid'
                    ? sprintf('DELETE FROM %1$s WHERE ctid = ANY(ARRAY(SELECT ctid FROM %1$s WHERE %2$s LIMIT %3$d))', $table, $where, $batch)
                    : sprintf('DELETE FROM %1$s WHERE id IN (SELECT id FROM %1$s WHERE %2$s ORDER BY id LIMIT %3$d)', $table, $where, $batch),
                $params,
                $types,
            );
            $deleted += $count;
        } while ($count >= $batch);

        return $deleted;
    }

    private static function positive(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^[1-9]\d{0,5}$/', $value) === 1 ? (int) $value : null;
    }
}
