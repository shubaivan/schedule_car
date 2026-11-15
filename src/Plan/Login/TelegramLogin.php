<?php

namespace App\Plan\Login;

use App\Entity\TelegramUser;
use App\Plan\Login\Entity\LoginNonce;
use App\Plan\People\PhoneFormat;
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

    /** Скільки останніх спроб пам'ятає сесія: друга вкладка чи автооновлення не «вбивають» ту, що вже в боті. */
    public const int SESSION_KEEP = 3;
    /** Підтвердили в останні секунди TTL — браузер ще встигає забрати вхід. */
    public const int CONFIRM_GRACE_SECONDS = 60;

    /** @return array{url: string, code: string, expiresIn: int, id: int} */
    public function start(SessionInterface $session, ?string $ip, ?string $userAgent, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $this->em->getConnection()->executeStatement('DELETE FROM plan_login_nonce WHERE expires_at < ?', [$now->modify('-1 day')->format('Y-m-d H:i:s')]);

        $nonce = bin2hex(random_bytes(16));
        $hash = hash('sha256', $nonce);
        $code = sprintf('%04d', random_int(0, 9999));
        $row = new LoginNonce($hash, $code, $now, $now->modify('+' . self::TTL_SECONDS . ' seconds'), $ip, $userAgent);
        $this->em->persist($row);
        $this->em->flush();

        $attempts = [...self::attemptsOf($session), ['id' => (int) $row->getId(), 'hash' => $hash]];
        $session->set(self::SESSION_PENDING, array_slice($attempts, -self::SESSION_KEEP));

        return [
            'url' => sprintf('https://t.me/%s?start=%s%s', (string) $this->link->botUsername(), self::PREFIX, $nonce),
            'code' => $code,
            'expiresIn' => self::TTL_SECONDS,
            'id' => (int) $row->getId(),
        ];
    }

    /**
     * Стан спроб цієї сесії: none (не починали) | pending | confirmed | denied | join | expired.
     * Кілька спроб (вкладки, автооновлення) — найважливіший стан: confirmed > join > denied > pending > expired;
     * code — найсвіжішої спроби (її показує сторінка).
     *
     * @return array{state: string, code?: string}
     */
    public function status(SessionInterface $session, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $rank = ['confirmed' => 5, 'join' => 4, 'denied' => 3, 'pending' => 2, 'expired' => 1];
        $best = null;
        $code = null;

        foreach ($this->attemptRows($session) as $row) {
            $state = match ($row->getStatus()) {
                LoginNonceStatus::Confirmed => $this->completable($row, $now) ? 'confirmed' : 'expired',
                LoginNonceStatus::Pending => $row->isExpired($now) ? 'expired' : 'pending',
                LoginNonceStatus::Join => 'join',
                LoginNonceStatus::Denied => 'denied',
                default => 'expired',
            };
            $code = $row->getCode();

            if ($best === null || $rank[$state] > $rank[$best]) {
                $best = $state;
            }
        }

        return $best === null ? ['state' => 'none'] : ['state' => $best, 'code' => (string) $code];
    }

    /**
     * Браузер забирає підтверджений вхід: confirmed → used рівно один раз.
     *
     * @return array{TelegramUser, int}|null людина і id спроби
     */
    public function complete(SessionInterface $session, ?DateTimeImmutable $now = null): ?array
    {
        $now ??= new DateTimeImmutable();
        $at = $now->format('Y-m-d H:i:s');
        $grace = $now->modify('-' . self::CONFIRM_GRACE_SECONDS . ' seconds')->format('Y-m-d H:i:s');

        foreach (self::attemptsOf($session) as $attempt) {
            $userId = $this->em->getConnection()->fetchOne(
                "UPDATE plan_login_nonce SET status = 'used', used_at = ? WHERE id = ? AND nonce_hash = ? AND status = 'confirmed' AND (expires_at > ? OR confirmed_at > ?) RETURNING user_id",
                [$at, $attempt['id'], $attempt['hash'], $at, $grace],
            );

            if ($userId === false || $userId === null) {
                continue;
            }

            // Забрали — решту спроб цієї сесії більше не чекаємо.
            $session->remove(self::SESSION_PENDING);
            $user = $this->users->find((int) $userId);

            return $user instanceof TelegramUser && self::canLogIn($user) ? [$user, $attempt['id']] : null;
        }

        return null;
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

    /** Бот: жива спроба з цим id, яку забрав саме цей Telegram. */
    public function pendingById(int $id, string $telegramId, ?DateTimeImmutable $now = null): ?LoginNonce
    {
        $now ??= new DateTimeImmutable();
        $found = $this->em->getConnection()->fetchOne(
            "SELECT id FROM plan_login_nonce WHERE id = ? AND telegram_id = ? AND status = 'pending' AND expires_at > ?",
            [$id, $telegramId, $now->format('Y-m-d H:i:s')],
        );

        return $found !== false && $found !== null ? $this->refreshed((int) $found) : null;
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
        $full = self::e164($phone);

        if ($full === null) {
            return [];
        }

        /** @var list<TelegramUser> $found */
        $found = $this->users->createQueryBuilder('u')
            ->where('u.phone_number LIKE :tail')
            ->andWhere('u.telegram_id NOT LIKE :demo')
            ->setParameter('tail', '%' . substr($full, -9))
            ->setParameter('demo', 'demo-%')
            ->orderBy('u.id')
            ->getQuery()
            ->getResult();

        // Хвіст лише звужує пошук; збіг — за повним номером (+48 і +380 з однаковим хвостом — різні люди).
        $same = array_values(array_filter($found, static fn (TelegramUser $u) => ! TelegramLink::isStub($u) && self::e164((string) $u->getPhoneNumber()) === $full));
        $live = array_values(array_filter($same, self::canLogIn(...)));

        // Стара картка в кошику не заважає живій; «закрито» — лише коли живих немає.
        return $live !== [] ? $live : $same;
    }

    /** Номер до повного вигляду цифрами (PhoneFormat::e164). */
    public static function e164(string $phone): ?string
    {
        return PhoneFormat::e164($phone);
    }

    /** Чи пускати людину: підтверджена, не в кошику. */
    public static function canLogIn(TelegramUser $user): bool
    {
        return $user->isApproved() && ! $user->isArchived();
    }

    /** @return list<array{id: int, hash: string}> */
    private static function attemptsOf(SessionInterface $session): array
    {
        $out = [];

        foreach ((array) $session->get(self::SESSION_PENDING, []) as $attempt) {
            if (is_array($attempt) && isset($attempt['id'], $attempt['hash'])) {
                $out[] = ['id' => (int) $attempt['id'], 'hash' => (string) $attempt['hash']];
            }
        }

        return $out;
    }

    /** @return list<LoginNonce> спроби цієї сесії, що справді її (хеш збігся) */
    private function attemptRows(SessionInterface $session): array
    {
        $rows = [];

        foreach (self::attemptsOf($session) as $attempt) {
            $row = $this->refreshed($attempt['id']);

            if ($row !== null && hash_equals($row->getNonceHash(), $attempt['hash'])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function completable(LoginNonce $row, DateTimeImmutable $now): bool
    {
        $confirmed = $row->getConfirmedAt();

        return ! $row->isExpired($now) || ($confirmed !== null && $confirmed > $now->modify('-' . self::CONFIRM_GRACE_SECONDS . ' seconds'));
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
