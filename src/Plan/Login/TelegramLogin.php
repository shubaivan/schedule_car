<?php

namespace App\Plan\Login;

use App\Entity\TelegramUser;
use App\Plan\Login\Entity\LoginNonce;
use App\Plan\Telegram\PlanBotMode;
use App\Plan\Telegram\TelegramLink;
use App\Repository\TelegramUserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * «Увійти через Telegram» — стан спроб входу (plan_login_nonce) для сайту й для бота.
 *
 * Сайт (TelegramLoginController / TelegramLoginAuthenticator):
 *   start()    — новий nonce на цю сесію браузера: посилання t.me/<бот>?start=login_<nonce> і код;
 *   status()   — що з ним (сторінка входу питає кожні 2 с);
 *   complete() — підтверджений nonce → used (атомарно, один раз) → людина, якій дати сесію.
 * Бот (PlanLoginBot):
 *   claim()    — /start login_<nonce>: «забрати» спробу на цей Telegram (другий Telegram — ні);
 *   confirm() / close() — підтвердити на людину або закрити з причиною;
 *   revoke()   — «Це був не я» після входу: сесію, що її відкрив цей nonce, буде завершено.
 *
 * Сесія браузера тримає id рядка і sha256 nonce, тож статус і вхід видно лише тому браузеру,
 * що почав спробу: nonce у чужих руках (переслане посилання) сам сесії не дає. У базі —
 * лише хеш; nonce живе TTL_SECONDS і спрацьовує один раз.
 */
final class TelegramLogin
{
    public const string PREFIX = 'login_';
    public const int TTL_SECONDS = 300;
    /** Сесія браузера: спроба, що чекає (id + хеш). */
    public const string SESSION_PENDING = '_plan_tg_login';
    /** Сесія, відкрита входом через Telegram: id спроби — для «Це був не я». */
    public const string SESSION_OPENED = '_plan_tg_session';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelegramUserRepository $users,
        private readonly TelegramLink $link,
        private readonly PlanBotMode $mode,
    ) {
    }

    /** Кнопка «Увійти через Telegram» є лише там, де бот — бот «Плану» (він і обробляє login_…). */
    public function enabled(): bool
    {
        return $this->mode->enabled() && $this->link->botUsername() !== null;
    }

    /** @return array{url: string, code: string, expiresIn: int, id: int} */
    public function start(SessionInterface $session, ?string $ip, ?string $userAgent, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $db = $this->em->getConnection();

        // Попередня незавершена спроба цієї ж сесії більше не потрібна; старі рядки — геть.
        $previous = $session->get(self::SESSION_PENDING);
        if (is_array($previous) && isset($previous['id'])) {
            $db->executeStatement("DELETE FROM plan_login_nonce WHERE id = ? AND status = 'pending'", [(int) $previous['id']]);
        }
        $db->executeStatement('DELETE FROM plan_login_nonce WHERE expires_at < ?', [$now->modify('-1 day')->format('Y-m-d H:i:s')]);

        $nonce = bin2hex(random_bytes(16));
        $hash = hash('sha256', $nonce);
        $code = sprintf('%04d', random_int(0, 9999));
        $row = new LoginNonce($hash, $code, $now, $now->modify('+' . self::TTL_SECONDS . ' seconds'), $ip, $userAgent);
        $this->em->persist($row);
        $this->em->flush();

        $session->set(self::SESSION_PENDING, ['id' => (int) $row->getId(), 'hash' => $hash]);

        return [
            'url' => sprintf('https://t.me/%s?start=%s%s', (string) $this->link->botUsername(), self::PREFIX, $nonce),
            'code' => $code,
            'expiresIn' => self::TTL_SECONDS,
            'id' => (int) $row->getId(),
        ];
    }

    /**
     * Стан спроби цієї сесії: none (не починали) | pending | confirmed | denied | join | expired.
     *
     * @return array{state: string, code?: string}
     */
    public function status(SessionInterface $session, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $row = $this->pendingOf($session);

        if ($row === null) {
            return ['state' => 'none'];
        }

        $state = match ($row->getStatus()) {
            LoginNonceStatus::Pending, LoginNonceStatus::Confirmed => $row->isExpired($now) ? 'expired' : $row->getStatus()->value,
            LoginNonceStatus::Join => 'join',
            default => 'denied',
        };

        return ['state' => $state, 'code' => $row->getCode()];
    }

    /**
     * Браузер забирає підтверджений вхід: confirmed → used рівно один раз.
     *
     * @return array{TelegramUser, int}|null людина і id спроби
     */
    public function complete(SessionInterface $session, ?DateTimeImmutable $now = null): ?array
    {
        $now ??= new DateTimeImmutable();
        $pending = $session->get(self::SESSION_PENDING);

        if (! is_array($pending) || ! isset($pending['id'], $pending['hash'])) {
            return null;
        }

        $userId = $this->em->getConnection()->fetchOne(
            "UPDATE plan_login_nonce SET status = 'used', used_at = ? WHERE id = ? AND nonce_hash = ? AND status = 'confirmed' AND expires_at > ? RETURNING user_id",
            [$now->format('Y-m-d H:i:s'), (int) $pending['id'], (string) $pending['hash'], $now->format('Y-m-d H:i:s')],
        );
        $session->remove(self::SESSION_PENDING);

        $user = $userId !== false && $userId !== null ? $this->users->find((int) $userId) : null;

        if (! $user instanceof TelegramUser || ! self::canLogIn($user)) {
            return null;
        }

        return [$user, (int) $pending['id']];
    }

    /** Бот: /start login_<nonce> — живу спробу закріпити за цим Telegram. */
    public function claim(string $nonce, string $telegramId, ?DateTimeImmutable $now = null): ?LoginNonce
    {
        $now ??= new DateTimeImmutable();

        if (preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1) {
            return null;
        }

        $id = $this->em->getConnection()->fetchOne(
            "UPDATE plan_login_nonce SET telegram_id = ? WHERE nonce_hash = ? AND status = 'pending' AND expires_at > ? AND (telegram_id IS NULL OR telegram_id = ?) RETURNING id",
            [$telegramId, hash('sha256', $nonce), $now->format('Y-m-d H:i:s'), $telegramId],
        );

        return $id !== false && $id !== null ? $this->refreshed((int) $id) : null;
    }

    /** Бот: остання жива спроба цього Telegram (чекає, поки людина поділиться номером). */
    public function pendingFor(string $telegramId, ?DateTimeImmutable $now = null): ?LoginNonce
    {
        $now ??= new DateTimeImmutable();
        $id = $this->em->getConnection()->fetchOne(
            "SELECT id FROM plan_login_nonce WHERE telegram_id = ? AND status = 'pending' AND expires_at > ? ORDER BY id DESC LIMIT 1",
            [$telegramId, $now->format('Y-m-d H:i:s')],
        );

        return $id !== false && $id !== null ? $this->refreshed((int) $id) : null;
    }

    public function confirm(LoginNonce $nonce, TelegramUser $user, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        return $this->em->getConnection()->executeStatement(
            "UPDATE plan_login_nonce SET status = 'confirmed', user_id = ?, confirmed_at = ? WHERE id = ? AND status = 'pending' AND expires_at > ?",
            [(int) $user->getId(), $now->format('Y-m-d H:i:s'), (int) $nonce->getId(), $now->format('Y-m-d H:i:s')],
        ) === 1;
    }

    /** Закрити спробу без входу: denied (відмова) або join (надіслано заявку на приєднання). */
    public function close(LoginNonce $nonce, LoginNonceStatus $status): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE plan_login_nonce SET status = ? WHERE id = ? AND status = 'pending'",
            [$status->value, (int) $nonce->getId()],
        );
    }

    /**
     * «Це був не я»: тільки той Telegram, що підтверджував, і лише свою спробу.
     * Ще не використану — закриваємо (браузер не увійде), використану — відкликаємо
     * (TelegramSessionGuard завершить ту сесію на наступному ж запиті).
     */
    public function revoke(int $nonceId, string $telegramId): ?LoginNonce
    {
        $id = $this->em->getConnection()->fetchOne(
            "UPDATE plan_login_nonce SET status = CASE WHEN status = 'used' THEN 'revoked' ELSE 'denied' END WHERE id = ? AND telegram_id = ? AND status IN ('pending', 'confirmed', 'used') RETURNING id",
            [$nonceId, $telegramId],
        );

        return $id !== false && $id !== null ? $this->refreshed((int) $id) : null;
    }

    public function isRevoked(int $nonceId): bool
    {
        return $this->em->getConnection()->fetchOne('SELECT status FROM plan_login_nonce WHERE id = ?', [$nonceId]) === LoginNonceStatus::Revoked->value;
    }

    /**
     * Співробітники, яким належить цей номер (останні 9 цифр): лише живі (підтверджені,
     * не в кошику), без демо-людей і без «порожніх» записів бота.
     *
     * @return list<TelegramUser>
     */
    public function staffByPhone(string $phone): array
    {
        $tail = substr((string) preg_replace('/\D+/', '', $phone), -9);

        if (strlen($tail) !== 9) {
            return [];
        }

        /** @var list<TelegramUser> $found */
        $found = $this->users->createQueryBuilder('u')
            ->where('u.phone_number LIKE :tail')
            ->andWhere('u.telegram_id NOT LIKE :demo')
            ->setParameter('tail', '%' . $tail)
            ->setParameter('demo', 'demo-%')
            ->orderBy('u.id')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($found, static fn (TelegramUser $u) => ! TelegramLink::isStub($u)));
    }

    /** Чи пускати людину: підтверджена, не в кошику. */
    public static function canLogIn(TelegramUser $user): bool
    {
        return $user->isApproved() && ! $user->isArchived();
    }

    private function pendingOf(SessionInterface $session): ?LoginNonce
    {
        $pending = $session->get(self::SESSION_PENDING);

        if (! is_array($pending) || ! isset($pending['id'], $pending['hash'])) {
            return null;
        }

        $row = $this->refreshed((int) $pending['id']);

        return $row !== null && hash_equals($row->getNonceHash(), (string) $pending['hash']) ? $row : null;
    }

    private function refreshed(int $id): ?LoginNonce
    {
        $row = $this->em->find(LoginNonce::class, $id);

        if ($row !== null) {
            // Рядок міг змінити сирий UPDATE (бот, інший запит) — беремо свіжий стан.
            $this->em->refresh($row);
        }

        return $row;
    }
}
