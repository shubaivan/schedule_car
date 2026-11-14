<?php

namespace App\Plan\Drive;

use App\Plan\Drive\Source\DriveSource;
use App\Plan\Event\DriveAuthBroken;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Share\FileType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Черга копіювання файлів «Плану» на Google Диск (односторонньо: План → Диск).
 *
 * Черга — самі таблиці файлів: drive_file_id IS NULL, drive_state = 'pending',
 * drive_next_at настав. Кожен файл:
 * 1) спершу шукаємо на Диску свій файл із тим самим ключем (appProperties.planKey =
 *    task:12) — якщо попередній прогін встиг покласти файл, але не встиг записати
 *    це в базу, другої копії не буде (ідемпотентність);
 * 2) теки «План/<рік>/<розділ>/№… назва» знаходимо або створюємо;
 * 3) однакове ім'я в теці — додаємо « (id)».
 *
 * Збої: звичайний — спроба +1, наступна через 2^спроб хв; 6-та невдача — failed
 * (видно в адмінці, кнопка «Повторити»). invalid_grant — зупинка всієї синхронізації
 * й одна подія DriveAuthBroken суперадмінам; квота Google — пауза на годину без
 * штрафу файлам. Два прогони одночасно не йдуть (advisory lock PostgreSQL).
 */
class DriveSync
{
    public const int MAX_ATTEMPTS = 6;
    public const int QUOTA_PAUSE_MINUTES = 60;

    /** Довільне, але стале число для pg_try_advisory_lock. */
    private const int LOCK_KEY = 72_0115_1400;

    /** @var array<string, DriveSource> */
    private array $sources = [];

    /**
     * @param iterable<DriveSource> $sources
     */
    public function __construct(
        private readonly Connection $db,
        private readonly PlanEventRecorder $events,
        private readonly LoggerInterface $logger,
        #[AutowireIterator('plan.drive_source')]
        iterable $sources,
    ) {
        foreach ($sources as $source) {
            $this->sources[$source->type()->value] = $source;
        }
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
    }

    /**
     * @param list<int>|null $ids лише ці файли (DriveEnqueue після FileAdded); null — уся черга
     */
    public function run(DriveClient $client, int $limit = 50, ?DateTimeImmutable $now = null, ?FileType $only = null, ?array $ids = null): DriveSyncResult
    {
        $now ??= self::now();
        $result = new DriveSyncResult();

        if (! (bool) $this->db->fetchOne('SELECT pg_try_advisory_lock(?)', [self::LOCK_KEY])) {
            $result->stopped = DriveSyncResult::STOP_LOCKED;
            $result->line('Інший прогін синхронізації ще йде — цей пропускаємо.');

            return $result;
        }

        try {
            $this->work($client, max(1, $limit), $now, $only, $ids, $result);
        } finally {
            $this->db->fetchOne('SELECT pg_advisory_unlock(?)', [self::LOCK_KEY]);
        }

        return $result;
    }

    /** Знову поставити в чергу файл, що впав (або чекає backoff), — кнопка «Повторити». */
    public function retry(FileType $type, int $id): bool
    {
        return $this->db->executeStatement(
            sprintf("UPDATE %s SET drive_state = 'pending', drive_attempts = 0, drive_next_at = NULL, drive_error = NULL
                     WHERE id = ? AND drive_file_id IS NULL AND drive_state IN ('failed', 'pending')", $type->table()),
            [$id],
        ) > 0;
    }

    /** Усі failed — знову в чергу. Повертає, скільки файлів. */
    public function retryAllFailed(): int
    {
        $count = 0;

        foreach (FileType::cases() as $type) {
            $count += (int) $this->db->executeStatement(sprintf(
                "UPDATE %s SET drive_state = 'pending', drive_attempts = 0, drive_next_at = NULL, drive_error = NULL
                 WHERE drive_file_id IS NULL AND drive_state = 'failed'",
                $type->table(),
            ));
        }

        return $count;
    }

    /** Після нового токена / закінчення квоти: зняти зупинку й паузу. */
    public function resume(): void
    {
        $this->ensureStatus();
        $this->db->executeStatement('UPDATE plan_drive_status SET auth_broken_at = NULL, paused_until = NULL WHERE id = 1');
    }

    /** @return array{authBrokenAt: ?string, pausedUntil: ?string, lastRunAt: ?string, lastOkAt: ?string, lastError: ?string} */
    public function status(): array
    {
        $this->ensureStatus();
        $row = (array) $this->db->fetchAssociative('SELECT auth_broken_at, paused_until, last_run_at, last_ok_at, last_error FROM plan_drive_status WHERE id = 1');

        return [
            'authBrokenAt' => $row['auth_broken_at'] ?? null,
            'pausedUntil' => $row['paused_until'] ?? null,
            'lastRunAt' => $row['last_run_at'] ?? null,
            'lastOkAt' => $row['last_ok_at'] ?? null,
            'lastError' => $row['last_error'] ?? null,
        ];
    }

    /**
     * Скільки файлів чекає (з настанням drive_next_at чи без).
     */
    public function waiting(): int
    {
        $total = 0;

        foreach ($this->sources as $source) {
            $total += (int) $this->db->fetchOne(sprintf("SELECT COUNT(*) FROM %s WHERE drive_file_id IS NULL AND drive_state = 'pending'", $source->type()->table()));
        }

        return $total;
    }

    /** @param list<int>|null $ids */
    private function work(DriveClient $client, int $limit, DateTimeImmutable $now, ?FileType $only, ?array $ids, DriveSyncResult $result): void
    {
        $this->ensureStatus();
        $status = (array) $this->db->fetchAssociative('SELECT auth_broken_at, paused_until FROM plan_drive_status WHERE id = 1');

        if ($status['auth_broken_at'] !== null) {
            $result->stopped = DriveSyncResult::STOP_AUTH;
            $result->line('Доступ до Google Drive відкликано — потрібен новий токен і «Відновити» в адмінці.');

            return;
        }

        if ($status['paused_until'] !== null && new DateTimeImmutable((string) $status['paused_until'], $now->getTimezone()) > $now) {
            $result->stopped = DriveSyncResult::STOP_QUOTA;
            $result->line('Пауза через квоту Google до ' . $status['paused_until'] . '.');

            return;
        }

        $this->db->executeStatement('UPDATE plan_drive_status SET last_run_at = ?, paused_until = NULL WHERE id = 1', [self::sql($now)]);

        foreach ($this->sources as $type => $source) {
            if ($only !== null && $only->value !== $type) {
                continue;
            }

            foreach ($this->due($source->type(), $limit, $now, $ids) as $id) {
                if (! $this->one($client, $source, $id, $now, $result)) {
                    return;
                }
            }
        }
    }

    /**
     * @param list<int>|null $ids
     *
     * @return list<int>
     */
    private function due(FileType $type, int $limit, DateTimeImmutable $now, ?array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $sql = sprintf(
            "SELECT id FROM %s WHERE drive_file_id IS NULL AND drive_state = 'pending'
             AND (drive_next_at IS NULL OR drive_next_at <= :now)%s ORDER BY id LIMIT %d",
            $type->table(),
            $ids !== null ? ' AND id IN (:ids)' : '',
            $limit,
        );

        $params = ['now' => self::sql($now)];
        $types = [];

        if ($ids !== null) {
            $params['ids'] = $ids;
            $types['ids'] = ArrayParameterType::INTEGER;
        }

        return array_map('intval', $this->db->fetchFirstColumn($sql, $params, $types));
    }

    /** false — далі не йдемо (зупинка або пауза). */
    private function one(DriveClient $client, DriveSource $source, int $id, DateTimeImmutable $now, DriveSyncResult $result): bool
    {
        $type = $source->type();

        try {
            $item = $source->item($id);
        } catch (Throwable $e) {
            $this->fail($type, $id, $e->getMessage(), $now, $result);

            return true;
        }

        if ($item === null) {
            $this->db->executeStatement(sprintf("UPDATE %s SET drive_state = 'skipped', drive_next_at = NULL WHERE id = ?", $type->table()), [$id]);
            ++$result->skipped;
            $result->line(sprintf('  – %s:%d — файл видалено, на Диск не йде', $type->value, $id));

            return true;
        }

        try {
            $existing = $client->findByKey($item->key());

            if ($existing !== null) {
                $this->done($type, $id, $existing, $now);
                ++$result->alreadyThere;
                $result->line(sprintf('  ✔ %s — уже на Диску', $item->path()));

                return true;
            }

            $parent = $client->rootId();

            foreach ($item->folders as $folder) {
                $parent = $client->folder($folder, $parent);
            }

            $name = $client->nameTaken($item->name, $parent) ? DrivePaths::withId($item->name, $id) : $item->name;
            $upload = $client->upload($name, $item->mime, $source->read($id), $parent, $item->key());

            $this->done($type, $id, $upload, $now);
            ++$result->sent;
            $result->line(sprintf('  ✔ %s', implode('/', [...$item->folders, $name])));

            return true;
        } catch (DriveException $e) {
            if ($e->kind === DriveException::AUTH) {
                $this->authBroken($item, $e, $now);
                $result->stopped = DriveSyncResult::STOP_AUTH;
                $result->line('  ✖ Google відкликав доступ (invalid_grant) — синхронізацію зупинено.');

                return false;
            }

            if ($e->kind === DriveException::QUOTA) {
                $this->db->executeStatement('UPDATE plan_drive_status SET paused_until = ?, last_error = ? WHERE id = 1', [
                    self::sql($now->modify(sprintf('+%d minutes', self::QUOTA_PAUSE_MINUTES))),
                    $e->getMessage(),
                ]);
                $result->stopped = DriveSyncResult::STOP_QUOTA;
                $result->line('  ✖ Квота Google — пауза на годину.');

                return false;
            }

            $this->fail($type, $id, $e->getMessage(), $now, $result);
        } catch (Throwable $e) {
            $this->fail($type, $id, $e->getMessage(), $now, $result);
        }

        return true;
    }

    private function done(FileType $type, int $id, DriveUpload $upload, DateTimeImmutable $now): void
    {
        $this->db->executeStatement(
            sprintf("UPDATE %s SET drive_file_id = ?, drive_url = ?, drive_state = 'done', drive_error = NULL, drive_next_at = NULL WHERE id = ?", $type->table()),
            [$upload->id, $upload->url, $id],
        );
        $this->db->executeStatement('UPDATE plan_drive_status SET last_ok_at = ?, last_error = NULL WHERE id = 1', [self::sql($now)]);
    }

    private function fail(FileType $type, int $id, string $error, DateTimeImmutable $now, DriveSyncResult $result): void
    {
        $error = mb_substr(trim($error), 0, 500);
        $attempts = (int) $this->db->fetchOne(sprintf('SELECT drive_attempts FROM %s WHERE id = ?', $type->table()), [$id]) + 1;
        $failed = $attempts >= self::MAX_ATTEMPTS;

        $this->db->executeStatement(
            sprintf('UPDATE %s SET drive_attempts = ?, drive_error = ?, drive_state = ?, drive_next_at = ? WHERE id = ?', $type->table()),
            [$attempts, $error, $failed ? 'failed' : 'pending', $failed ? null : self::sql($now->modify(sprintf('+%d minutes', 2 ** $attempts))), $id],
        );
        $this->db->executeStatement('UPDATE plan_drive_status SET last_error = ? WHERE id = 1', [$error]);

        ++$result->failed;
        $result->line(sprintf('  ✖ %s:%d — спроба %d/%d: %s', $type->value, $id, $attempts, self::MAX_ATTEMPTS, $error));
        $this->logger->warning('plan drive: не вдалось скопіювати файл', ['type' => $type->value, 'id' => $id, 'attempt' => $attempts, 'error' => $error]);
    }

    /** Одна подія на поломку: повторні прогони стоять на auth_broken_at і подій не пишуть. */
    private function authBroken(DriveItem $item, DriveException $e, DateTimeImmutable $now): void
    {
        $first = $this->db->executeStatement(
            'UPDATE plan_drive_status SET auth_broken_at = ?, last_error = ? WHERE id = 1 AND auth_broken_at IS NULL',
            [self::sql($now), $e->getMessage()],
        ) > 0;

        $this->logger->error('plan drive: Google відкликав доступ, синхронізацію зупинено', ['error' => $e->getMessage()]);

        if ($first) {
            $this->events->record(new DriveAuthBroken($item->subject, null, $item->type->value, $item->id, $e->getMessage()));
        }
    }

    private function ensureStatus(): void
    {
        $this->db->executeStatement('INSERT INTO plan_drive_status (id) VALUES (1) ON CONFLICT (id) DO NOTHING');
    }

    private static function sql(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }
}
