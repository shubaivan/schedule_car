<?php

namespace App\Plan\Presence;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Tasks\Service\TaskPresenter;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * «Хто зараз у Плані» — семантика portal_presence з PMAP Олександра, але без запису
 * на кожен GET: фронт шле heartbeat раз на хвилину, лише поки вкладку видно
 * (plan/presence.ts). Активний = heartbeat за останні ACTIVE_SECONDS.
 *
 * Запис дешевий: один рядок на людину (PK user_id), upsert не пише нічого,
 * якщо розділ той самий і попередній heartbeat свіжіший за MIN_WRITE_SECONDS
 * (дві вкладки, швидкі переходи). Застарілі рядки прибирає той самий heartbeat.
 *
 * Конфіденційність (ТЗ §24): віддаємо лише людей «Плану» (затверджених, не в архіві),
 * розділ — без того, що саме відкрито; телефон — за тим самим правилом, що й довідник
 * людей (/api/tasks/meta → people).
 */
final class PresenceTracker
{
    /** Скільки секунд після останнього heartbeat людина ще «зараз у Плані». */
    public const int ACTIVE_SECONDS = 180;
    /** Як часто фронт шле heartbeat з видимої вкладки. */
    public const int HEARTBEAT_SECONDS = 60;
    /** Той самий розділ і heartbeat свіжіший — рядок не переписуємо. */
    private const int MIN_WRITE_SECONDS = 20;

    public function __construct(
        private Connection $db,
        private TelegramUserRepository $users,
        private TaskPresenter $presenter,
    ) {
    }

    /** Людина зараз у «Плані», у розділі $section. Заодно прибирає застарілі рядки. */
    public function touch(TelegramUser $user, PresenceSection $section): void
    {
        if (! self::counts($user)) {
            return;
        }

        // «Адмін» (журнал аудиту) бачить лише суперадмін — інший клієнт так назватися не може.
        if ($section === PresenceSection::Admin && ! $user->isSuperadmin()) {
            $section = PresenceSection::Other;
        }

        $this->db->executeStatement(
            sprintf(
                'INSERT INTO plan_presence (user_id, section, last_seen_at) VALUES (:user, :section, now())
                 ON CONFLICT (user_id) DO UPDATE SET section = EXCLUDED.section, last_seen_at = EXCLUDED.last_seen_at
                 WHERE plan_presence.section <> EXCLUDED.section OR plan_presence.last_seen_at < now() - interval \'%d seconds\'',
                self::MIN_WRITE_SECONDS,
            ),
            ['user' => $user->getId(), 'section' => $section->value],
        );
        $this->prune();
    }

    /** Вийшов (кнопка «Вийти») — зникає зі списку одразу, а не за 3 хвилини. */
    public function forget(TelegramUser $user): void
    {
        $this->db->executeStatement('DELETE FROM plan_presence WHERE user_id = :user', ['user' => $user->getId()]);
    }

    /** Рядки без heartbeat довше за ACTIVE_SECONDS. По індексу last_seen_at; нема чого — нічого не пише. */
    public function prune(): int
    {
        return (int) $this->db->executeStatement(sprintf("DELETE FROM plan_presence WHERE last_seen_at < now() - interval '%d seconds'", self::ACTIVE_SECONDS));
    }

    /**
     * Хто зараз у «Плані» — для шапки. $viewer — першим і з позначкою me, решта за іменем.
     *
     * @return list<array<string, mixed>>
     */
    public function online(TelegramUser $viewer): array
    {
        /** @var list<array{user_id: int|string, section: string, last_seen_at: string}> $rows */
        $rows = $this->db->fetchAllAssociative(sprintf(
            "SELECT user_id, section, last_seen_at FROM plan_presence WHERE last_seen_at >= now() - interval '%d seconds'",
            self::ACTIVE_SECONDS,
        ));

        if ($rows === []) {
            return [];
        }

        $byId = [];

        foreach ($this->users->findBy(['id' => array_map(static fn (array $r) => (int) $r['user_id'], $rows)]) as $user) {
            $byId[(int) $user->getId()] = $user;
        }

        $phones = self::phonesVisibleTo($viewer);
        $out = [];

        foreach ($rows as $row) {
            $user = $byId[(int) $row['user_id']] ?? null;

            if ($user === null || ! self::counts($user)) {
                continue;
            }

            $person = (array) $this->presenter->person($user);
            $section = PresenceSection::fromClient($row['section']);
            $out[] = [
                'id' => $person['id'],
                'name' => $person['name'],
                'initials' => $person['initials'],
                'color' => $person['color'],
                'position' => $person['position'] ?: $person['roleLabel'],
                'phone' => $phones ? $person['phone'] : null,
                'tel' => $phones ? $person['tel'] : null,
                'section' => $section->value,
                'sectionLabel' => $section->label(),
                'me' => $user->getId() === $viewer->getId(),
                'lastSeenAt' => new DateTimeImmutable($row['last_seen_at'])->format(DATE_ATOM),
            ];
        }

        usort($out, static fn (array $a, array $b) => [! $a['me'], mb_strtolower((string) $a['name'])] <=> [! $b['me'], mb_strtolower((string) $b['name'])]);

        return $out;
    }

    /** Кого показуємо в «зараз у Плані»: людей Плану — затверджених і не в архіві. */
    public static function counts(TelegramUser $user): bool
    {
        return $user->isApproved() && ! $user->isArchived();
    }

    /**
     * Телефони — за правилом довідника людей: його (/api/tasks/meta → people з phone)
     * бачить кожна затверджена людина «Плану» (рішення Івана 15.11: телефон поруч з іменем
     * скрізь). Зміниться правило довідника — міняти тут теж. Зараз це завжди true для того,
     * кого пустив PresenceApiController (він уже вимагає counts()), — метод лишаємо як
     * одне місце правила.
     */
    public static function phonesVisibleTo(TelegramUser $viewer): bool
    {
        return self::counts($viewer);
    }
}
