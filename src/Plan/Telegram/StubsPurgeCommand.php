<?php

namespace App\Plan\Telegram;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Прибрати звільнені порожні записи бота (TelegramLink::release): telegram_id «unlinked-…»,
 * в архіві, без підтвердження, пошти, телефону, посади й пароля — і на які НІЩО не посилається.
 *
 * «Посилання» — будь-який рядок у таблицях із зовнішнім ключем на telegram_user (список
 * береться з pg_constraint, тож нові таблиці враховуються самі), а також події журналу про
 * цю людину (plan_event subject user). Не рахуються лише службові таблиці, що й так
 * видаляються каскадом і не несуть історії (EPHEMERAL). Є посилання — запис лишається
 * в архіві (у кошику його все одно не видно).
 *
 * Не в момент прив'язки, а окремо: у тому ж запиті на запис ще може триматися бот.
 * --min-hours (24) — запас, щоб не чіпати щойно звільнені.
 *
 *     php bin/console plan:stubs:purge --dry-run    # що буде видалено і що тримає решту
 *     php bin/console plan:stubs:purge              # крон щоночі 03:40 (deploy/plan.cron)
 */
#[AsCommand(name: 'plan:stubs:purge', description: 'Видалити звільнені порожні записи бота (unlinked-…), на які ніщо не посилається')]
final class StubsPurgeCommand extends Command
{
    /** Службові таблиці з ON DELETE CASCADE без історії: не заважають видаленню. */
    private const array EPHEMERAL = [
        'login_token',
        'telegram_link_token',
        'plan_login_nonce',
        'plan_password_token',
        'plan_presence',
        'push_subscription',
        'notification_pref',
        'notification_setting',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати, що буде видалено і що тримає решту')
            ->addOption('min-hours', null, InputOption::VALUE_REQUIRED, 'Скільки годин запис має пролежати в архіві', '24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $hours = $input->getOption('min-hours');

        if (! is_string($hours) || preg_match('/^\d{1,5}$/', $hours) !== 1) {
            $io->error('--min-hours має бути цілим невідʼємним числом.');

            return Command::INVALID;
        }

        $before = new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'))->modify(sprintf('-%d hours', (int) $hours));
        $rows = [];
        $purged = 0;

        foreach ($this->candidates($before) as $stub) {
            $id = (int) $stub['id'];
            $refs = $dryRun ? $this->references($id) : $this->purgeIfUnreferenced($id);

            if ($refs === [] && ! $dryRun) {
                $this->logger->info('plan: порожній запис бота видалено', ['user' => $id]);
            }
            $purged += $refs === [] ? 1 : 0;
            $rows[] = [$id, $stub['telegram_id'], trim($stub['first_name'] . ' ' . $stub['last_name']), $stub['archived_at'], $refs === [] ? ($dryRun ? 'буде видалено' : 'видалено') : 'лишається: ' . implode(', ', $refs)];
        }

        if ($rows !== []) {
            $io->table(['id', 'telegram_id', 'імʼя', 'в архіві з', 'результат'], $rows);
        }
        $io->success(sprintf('%s%d з %d звільнених порожніх записів бота.', $dryRun ? '[dry-run] Буде видалено ' : 'Видалено ', $purged, count($rows)));

        return Command::SUCCESS;
    }

    /** @return list<array{id: int|string, telegram_id: string, first_name: ?string, last_name: ?string, archived_at: string}> */
    private function candidates(DateTimeImmutable $before): array
    {
        /** @var list<array{id: int|string, telegram_id: string, first_name: ?string, last_name: ?string, archived_at: string}> */
        return $this->db->fetchAllAssociative(
            // Ті самі умови, що TelegramLink::isStub() + isReleasedStub().
            "SELECT id, telegram_id, first_name, last_name, archived_at FROM telegram_user
              WHERE telegram_id LIKE :prefix AND archived_at IS NOT NULL AND archived_at < :before
                AND access_status <> 'approved' AND email IS NULL AND phone_number IS NULL AND position IS NULL AND password IS NULL
              ORDER BY id",
            ['prefix' => TelegramLink::RELEASED_PREFIX . '%', 'before' => $before->format('Y-m-d H:i:s')],
        );
    }

    /** @return list<string> «таблиця.колонка ×N» для кожного посилання на людину */
    private function references(int $id): array
    {
        $found = [];

        foreach ($this->foreignKeys() as [$table, $column]) {
            $n = (int) $this->db->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $this->db->quoteSingleIdentifier($table), $this->db->quoteSingleIdentifier($column)), [$id]);
            if ($n > 0) {
                $found[] = sprintf('%s.%s ×%d', $table, $column, $n);
            }
        }

        $events = (int) $this->db->fetchOne("SELECT COUNT(*) FROM plan_event WHERE subject_type = 'user' AND subject_id = ?", [$id]);
        if ($events > 0) {
            $found[] = sprintf('plan_event (про людину) ×%d', $events);
        }

        return $found;
    }

    /** @return list<array{string, string}> [таблиця, колонка] усіх FK на telegram_user, крім службових */
    private function foreignKeys(): array
    {
        $rows = $this->db->fetchAllNumeric(
            "SELECT c.conrelid::regclass::text, a.attname
               FROM pg_constraint c
               JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
              WHERE c.contype = 'f' AND c.confrelid = 'telegram_user'::regclass
              ORDER BY 1, 2",
        );

        return array_values(array_map(
            static fn (array $r): array => [(string) $r[0], (string) $r[1]],
            array_filter($rows, static fn (array $r): bool => ! in_array($r[0], self::EPHEMERAL, true)),
        ));
    }

    /**
     * Перевірка й видалення в одній транзакції під FOR UPDATE: вставка рядка з FK на цю людину
     * бере FOR KEY SHARE на її рядок і чекає — тож між перевіркою й DELETE посилання не з'явиться
     * (важливо для FK з SET NULL, які інакше мовчки обнулились би).
     *
     * @return list<string> посилання, що тримають запис ([] — видалено)
     */
    private function purgeIfUnreferenced(int $id): array
    {
        return $this->db->transactional(function (Connection $db) use ($id): array {
            $locked = $db->fetchOne(
                sprintf("SELECT id FROM telegram_user WHERE id = ? AND telegram_id LIKE '%s%%' AND archived_at IS NOT NULL FOR UPDATE", TelegramLink::RELEASED_PREFIX),
                [$id],
            );
            if ($locked === false) {
                return ['запис уже змінено'];
            }

            $refs = $this->references($id);
            if ($refs !== []) {
                return $refs;
            }

            // Проєкції (пошук, Хроніка, позначки прочитаного) по людині — без FK, прибираємо явно.
            foreach (['search_document', 'plan_subject_activity', 'plan_read'] as $table) {
                $db->executeStatement(sprintf("DELETE FROM %s WHERE subject_type = 'user' AND subject_id = ?", $table), [$id]);
            }
            $db->executeStatement('DELETE FROM telegram_user WHERE id = ?', [$id]);

            return [];
        });
    }
}
