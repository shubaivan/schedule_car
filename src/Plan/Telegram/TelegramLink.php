<?php

namespace App\Plan\Telegram;

use App\Entity\TelegramUser;
use App\Plan\Telegram\Entity\TelegramLinkToken;
use App\Repository\TelegramUserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Прив'язка Telegram до облікового запису Плану.
 *
 * Профіль → «Підключити Telegram» → одноразовий токен (15 хв, у базі лише sha256)
 * → `https://t.me/<бот>?start=plink_<токен>` → бот отримує `/start plink_<токен>`
 * → людині записуються справжній telegram_id і chat_id. Відтоді нотифаєри
 * (TaskNotifier, SupplyNotifier) пишуть їй у бот Плану.
 *
 * Бот на першому ж повідомленні незнайомця заводить «порожній» рядок
 * telegram_user (RequestSubscriber). Якщо це саме такий порожній запис — його
 * прибираємо; якщо Telegram уже належить іншій живій людині — відмова.
 */
final class TelegramLink
{
    public const string PREFIX = 'plink_';
    /** telegram_id порожнього запису бота після того, як його Telegram забрала справжня людина. */
    public const string RELEASED_PREFIX = 'unlinked-';
    private const string TTL = '+15 minutes';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelegramUserRepository $users,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::TELEGRAM_BOT_USERNAME)%')]
        private readonly ?string $botUsername = null,
        private readonly ?Nutgram $bot = null,
    ) {
    }

    public function botUsername(): ?string
    {
        $name = ltrim(trim((string) $this->botUsername), '@');

        return $name !== '' ? $name : null;
    }

    public static function isLinked(TelegramUser $user): bool
    {
        return ctype_digit(ltrim((string) $user->getTelegramId(), '-'));
    }

    /** @return array{url: string, expiresAt: DateTimeImmutable} */
    public function issue(TelegramUser $user, ?DateTimeImmutable $now = null): array
    {
        $bot = $this->botUsername() ?? throw new LogicException('TELEGRAM_BOT_USERNAME не задано');
        $now ??= new DateTimeImmutable();

        // Попередні невикористані токени цієї людини — у смітник: діє лише останнє посилання.
        $this->em->getConnection()->executeStatement('DELETE FROM telegram_link_token WHERE user_id = ? AND used_at IS NULL', [(int) $user->getId()]);

        $token = bin2hex(random_bytes(16));
        $link = new TelegramLinkToken(hash('sha256', $token), $user, $now->modify(self::TTL));
        $this->em->persist($link);
        $this->em->flush();

        return ['url' => sprintf('https://t.me/%s?start=%s%s', $bot, self::PREFIX, $token), 'expiresAt' => $link->getExpiresAt()];
    }

    /**
     * @return array{LinkResult, ?TelegramUser}
     */
    public function consume(string $token, string $telegramId, string $chatId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $link = preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? $this->em->find(TelegramLinkToken::class, hash('sha256', $token)) : null;

        if ($link === null || ! $link->isUsable($now)) {
            return [LinkResult::Invalid, null];
        }

        $user = $link->getUser();

        if (! $user->isApproved() || $user->isArchived()) {
            return [LinkResult::Invalid, null];
        }

        $holder = $this->users->getByTelegramId($telegramId);

        if ($holder !== null && $holder->getId() === $user->getId()) {
            $link->use($now);
            $user->setChatId($chatId);
            $this->em->flush();

            return [LinkResult::AlreadyLinked, $user];
        }

        if ($holder !== null && ! self::isStub($holder)) {
            return [LinkResult::Taken, $holder];
        }

        // Перепривʼязати вже підключену картку до іншого Telegram посиланням не можна: так
        // викрадена сесія забрала б людині вхід назавжди. Спершу «Відключити» (старий Telegram
        // отримає повідомлення), потім підключити новий.
        if (self::isLinked($user)) {
            return [LinkResult::OtherTelegram, $user];
        }

        // Атомарно «забрати» токен: два одночасні /start з тим самим посиланням — спрацює один.
        if ($this->em->getConnection()->executeStatement('UPDATE telegram_link_token SET used_at = ? WHERE token_hash = ? AND used_at IS NULL', [$now->format('Y-m-d H:i:s'), hash('sha256', $token)]) !== 1) {
            return [LinkResult::Invalid, null];
        }

        $this->em->refresh($link);

        return [$this->attach($user, $telegramId, $chatId), $user];
    }

    /**
     * Записати людині справжній Telegram (telegram_id + chat_id) — спільне для прив'язки
     * з профілю й для входу через Telegram за номером телефону (PlanLoginBot).
     *
     * Порожній запис, який бот завів цьому Telegram, звільняє id; Telegram іншої живої
     * людини — Taken. Чи можна перепривʼязати людину, що вже має ІНШИЙ Telegram, вирішує
     * той, хто кличе: з профілю — так, за номером телефону — ні (PlanLoginBot).
     */
    public function attach(TelegramUser $user, string $telegramId, ?string $chatId): LinkResult
    {
        $holder = $this->users->getByTelegramId($telegramId);

        if ($holder !== null && $holder->getId() === $user->getId()) {
            $user->setChatId($chatId ?? $user->getChatId());
            $this->em->flush();

            return LinkResult::AlreadyLinked;
        }

        if ($holder !== null) {
            if (! self::isStub($holder)) {
                return LinkResult::Taken;
            }

            $this->release($holder);
        }

        $user->setTelegramId($telegramId)->setChatId($chatId);
        $this->em->flush();
        $this->logger->info('plan: Telegram прив\'язано', ['user' => $user->getId()]);

        return LinkResult::Linked;
    }

    /** Відв'язати: бот більше не пише цій людині (до нової прив'язки). */
    public function unlink(TelegramUser $user): void
    {
        if (! self::isLinked($user)) {
            return;
        }

        $chat = $user->getChatId() ?: $user->getTelegramId();
        $user->setTelegramId('staff-' . bin2hex(random_bytes(8)))->setChatId(null);
        $this->em->flush();

        // Старий Telegram має знати, що його відключили (якщо це зробив не він — сигнал).
        try {
            $this->bot?->sendMessage(text: "🔌 Цей Telegram відключено від Плану.\nЯкщо це зробили не ви — негайно повідомте адміністратора.", chat_id: $chat);
        } catch (Throwable $e) {
            $this->logger->info('plan: повідомлення про відключення Telegram не доставлено', ['error' => $e->getMessage()]);
        }
    }

    /**
     * «Порожній» запис, який бот завів незнайомцю: без підтвердження, без
     * пароля й пошти, без ролі в Плані, без номера.
     */
    public static function isStub(TelegramUser $user): bool
    {
        return ! $user->isApproved() && $user->getEmail() === null && $user->getPhoneNumber() === null && $user->getPosition() === null && $user->getPassword() === null;
    }

    /**
     * Звільнений порожній запис бота (див. release()). Це не співробітник — у кошику
     * «Співробітників» його не показуємо; без посилань на нього його прибирає plan:stubs:purge.
     */
    public static function isReleasedStub(TelegramUser $user): bool
    {
        return $user->isArchived() && str_starts_with((string) $user->getTelegramId(), self::RELEASED_PREFIX) && self::isStub($user);
    }

    /**
     * Звільнити telegram_id порожнього запису: перейменувати й архівувати (не видаляти тут же —
     * у цьому ж запиті на нього ще може триматися бот: поточний користувач, спроба входу).
     * У кошику співробітників такі записи не видно (isReleasedStub), а без посилань їх
     * щоночі видаляє plan:stubs:purge (deploy/plan.cron).
     */
    private function release(TelegramUser $stub): void
    {
        $stub->setTelegramId(self::RELEASED_PREFIX . $stub->getId() . '-' . bin2hex(random_bytes(4)))->setChatId(null);
        $stub->archive();
        // Спершу звільняємо унікальний telegram_id, потім віддаємо його людині.
        $this->em->flush();
    }
}
