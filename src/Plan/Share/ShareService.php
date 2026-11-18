<?php

namespace App\Plan\Share;

use App\Entity\TelegramUser;
use App\Plan\Audit\AuditThrottle;
use App\Plan\Event\FileDownloaded;
use App\Plan\Event\ShareCreated;
use App\Plan\Event\ShareRevoked;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Share\Entity\ShareLink;
use App\Repository\TelegramUserRepository;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Enum\WorkRole;
use App\Warehouse\Entity\WhDocument;
use App\Warehouse\Service\WarehouseSection;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SortDirection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * «Поділитися» файлом:
 * - з людьми «Плану» — лише з тими, хто й так має доступ до файлу (інакше відмова з
 *   іменами); подія ShareCreated → сповіщення кожному (NotificationFanout, група «share»: центр, Mercure, push);
 * - назовні — тимчасове посилання /s/{token} на TTL_DAYS днів, яке можна відкликати.
 *   У базі лише SHA-256 токена; посилання працює, доки його автор сам бачить файл.
 *
 * Створити посилання може кожен, хто бачить файл (той і так може скачати й переслати
 * файл сам — посилання лише зручніше й з журналом); відкликати — автор, адміністратор
 * чи суперадмін. Кожне створення / відкликання / скачування — подія в журналі.
 */
class ShareService
{
    public const int TTL_DAYS = 7;
    public const int NOTE_MAX = 500;
    public const int MAX_RECIPIENTS = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanEventRecorder $events,
        private readonly PlanFiles $files,
        private readonly TelegramUserRepository $users,
        #[Autowire('%env(APP_BASE_URL)%')]
        private readonly string $baseUrl = '',
    ) {
    }

    /**
     * @param list<int> $userIds
     *
     * @return list<TelegramUser> отримувачі
     */
    public function shareWithUsers(PlanFile $file, TelegramUser $actor, array $userIds, ?string $note = null): array
    {
        $this->assertCanView($actor, $file);

        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id) => $id > 0 && $id !== $actor->getId())));

        if ($userIds === []) {
            throw new ShareException('Виберіть, із ким поділитися.');
        }

        if (count($userIds) > self::MAX_RECIPIENTS) {
            throw new ShareException(sprintf('Не більше %d людей за раз.', self::MAX_RECIPIENTS));
        }

        $recipients = $this->users->findBy(['id' => $userIds]);
        $denied = [];

        foreach ($recipients as $user) {
            if (! $this->files->canView($user, $file)) {
                $denied[] = $user->shortName();
            }
        }

        if (count($recipients) !== count($userIds)) {
            throw new ShareException('Когось зі списку не знайдено.');
        }

        if ($denied !== []) {
            throw new ShareException(sprintf('Немає доступу до цього файлу: %s. Додайте їх учасниками або виберіть інших.', implode(', ', $denied)));
        }

        $note = $note !== null ? mb_substr(trim($note), 0, self::NOTE_MAX) : null;

        $this->events->transactional(fn () => $this->events->record(new ShareCreated(
            $file->subject,
            $actor->getId(),
            $file->id,
            $file->type->value,
            array_map(static fn (TelegramUser $u) => (int) $u->getId(), $recipients),
            'users',
            $note !== '' ? $note : null,
        )));

        return $recipients;
    }

    /** @return array{ShareLink, string} посилання і сам токен (показується лише раз) */
    public function createLink(PlanFile $file, TelegramUser $actor, ?DateTimeImmutable $now = null): array
    {
        $this->assertCanView($actor, $file);

        // Склад на читання (продажі, бухгалтерія): документ відкрити можна, а публічне посилання назовні — ні.
        if ($file->entity instanceof WhDocument && ! WarehouseSection::canManage($actor)) {
            throw new ShareException('Публічне посилання на документ складу створює той, хто веде склад.');
        }

        $now ??= ShareLink::now();
        $token = self::newToken();
        $link = new ShareLink(self::hash($token), $file->type, $file->id, $actor, $now, $now->modify(sprintf('+%d days', self::TTL_DAYS)));

        $this->events->transactional(function () use ($link, $file, $actor): void {
            $this->em->persist($link);
            $this->em->flush();
            $this->events->record(new ShareCreated($file->subject, $actor->getId(), $file->id, $file->type->value, [], 'link', linkId: $link->getId()));
        });

        return [$link, $token];
    }

    /** Автор — завжди; адміністратор — лише посилання на файли, які бачить сам. */
    public function canRevoke(TelegramUser $actor, ShareLink $link, PlanFile $file): bool
    {
        return $link->getCreatedBy()->getId() === $actor->getId() || (self::isAdmin($actor) && $this->files->canView($actor, $file));
    }

    public function revoke(ShareLink $link, TelegramUser $actor, PlanFile $file): void
    {
        if (! $this->canRevoke($actor, $link, $file)) {
            throw new ShareException('Відкликати посилання може його автор або адміністратор.');
        }

        if ($link->getRevokedAt() !== null) {
            return;
        }

        $this->events->transactional(function () use ($link, $actor, $file): void {
            $link->revoke($actor);
            $this->em->flush();
            $this->events->record(new ShareRevoked($file->subject, $actor->getId(), $file->id, $file->type->value, (int) $link->getId()));
        });
    }

    /**
     * Посилання за токеном зі /s/{token}: [посилання, файл] або причина відмови.
     *
     * @return array{?ShareLink, ?PlanFile, ?string} reason: unknown | revoked | expired | gone
     */
    public function resolve(string $token, ?DateTimeImmutable $now = null): array
    {
        $link = $this->em->getRepository(ShareLink::class)->findOneBy(['tokenHash' => self::hash($token)]);

        if (! $link instanceof ShareLink) {
            return [null, null, 'unknown'];
        }

        if ($link->getRevokedAt() !== null) {
            return [$link, null, 'revoked'];
        }

        if ($link->isExpired($now)) {
            return [$link, null, 'expired'];
        }

        $file = $this->files->find($link->getFileType(), $link->getFileId());

        // Автор посилання втратив доступ (вийшов із задачі, архів) або файл видалили —
        // посилання мертве: воно ніколи не дає більше, ніж бачить сам автор.
        if ($file === null || ! $this->files->canView($link->getCreatedBy(), $file)) {
            return [$link, null, 'gone'];
        }

        return [$link, $file, null];
    }

    public function recordDownload(ShareLink $link, PlanFile $file, ?string $ip): void
    {
        $this->events->transactional(function () use ($link, $file, $ip): void {
            $this->em->getConnection()->executeStatement(
                'UPDATE share_link SET downloads = downloads + 1, last_download_at = ? WHERE id = ?',
                [ShareLink::now()->format('Y-m-d H:i:s'), $link->getId()],
            );
            // Лічильник — кожне скачування; рядок журналу — один на посилання × IP за 10 хв
            // (анонім за посиланням інакше міг би без кінця роздувати plan_event).
            $recent = $this->em->getConnection()->fetchOne(
                "SELECT 1 FROM plan_event WHERE type = ? AND subject_type = ? AND subject_id = ? AND actor_id IS NULL AND payload->>'shareLinkId' = ? AND ip IS NOT DISTINCT FROM ? AND occurred_at >= ? LIMIT 1",
                [FileDownloaded::TYPE, $file->subject->type->value, $file->subject->id, (string) $link->getId(), $ip, ShareLink::now()->modify('-' . AuditThrottle::WINDOW_MINUTES . ' minutes')->format('Y-m-d H:i:s')],
            );

            if ($recent === false) {
                $this->events->record(new FileDownloaded($file->subject, null, $file->id, $file->type->value, $file->name, $link->getId())->via('web', $ip));
            }
        });
    }

    /**
     * Активні посилання на файл: свої — завжди, чужі — адміністратору.
     *
     * @return list<ShareLink>
     */
    public function activeLinks(PlanFile $file, TelegramUser $viewer): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('l')->from(ShareLink::class, 'l')
            ->where('l.fileType = :type AND l.fileId = :id AND l.revokedAt IS NULL AND l.expiresAt > :now')
            ->setParameter('type', $file->type)
            ->setParameter('id', $file->id)
            ->setParameter('now', ShareLink::now())
            ->orderBy('l.id', SortDirection::Descending);

        if (! self::isAdmin($viewer)) {
            $qb->andWhere('l.createdBy = :me')->setParameter('me', $viewer);
        }

        return $qb->getQuery()->getResult();
    }

    public function linkById(int $id): ?ShareLink
    {
        return $this->em->find(ShareLink::class, $id);
    }

    /** https://plan…/s/<token> — адреса для людей поза «Планом». */
    public function publicUrl(string $token): string
    {
        return rtrim($this->baseUrl, '/') . '/s/' . $token;
    }

    public static function isAdmin(TelegramUser $user): bool
    {
        // Системний адмін — ні: у своїх задачах він як звичайний користувач (чужі посилання не бачить і не відкликає).
        return $user->isSuperadmin() || $user->getWorkRole() === WorkRole::Admin || $user->getSupplyRole() === SupplyRole::Admin;
    }

    /**
     * Сторінка «Диск» показує назви файлів усіх модулів — лише тим, хто й так бачить усе:
     * суперадмін і адміністратор «Плану» (адмін постачання приватних задач не бачить).
     * Системний адміністратор теж (налаштування), але назви файлів задач йому приховано
     * (DriveStatusController) — усіх задач він не бачить.
     */
    public static function isDriveAdmin(TelegramUser $user): bool
    {
        return $user->isSuperadmin() || in_array($user->getWorkRole(), [WorkRole::Admin, WorkRole::SysAdmin], true);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** 32 символи base64url = 192 біти випадковості. */
    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    private function assertCanView(TelegramUser $actor, PlanFile $file): void
    {
        if (! $this->files->canView($actor, $file)) {
            throw new ShareException('Файл недоступний.');
        }
    }
}
