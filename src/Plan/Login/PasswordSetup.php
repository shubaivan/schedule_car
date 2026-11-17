<?php

namespace App\Plan\Login;

use App\Entity\TelegramUser;
use App\Plan\Event\StaffChanged;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Login\Entity\PasswordToken;
use App\Plan\Subject\SubjectRef;
use App\Plan\Telegram\TelegramLink;
use App\Repository\TelegramUserRepository;
use App\Security\LoginIdentity;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Throwable;

/**
 * Пароль для входу в «План» (Олександр/Іван, 17.11.2026): реєстрація — лише через
 * Telegram, вхід — Telegram або телефон + пароль на вибір.
 *
 * Пароль людина ставить САМА за одноразовим посиланням, яке бот надсилає в її
 * Telegram (уперше й коли забула). Ні email, ні адмін у картці пароль не задають —
 * за кожним паролем стоїть той, чий Telegram підтвердив номер.
 *
 * Посилання просять: у боті (/parol, кнопка), у Профілі (собі ж у Telegram) і зі
 * сторінки входу «Забули пароль?» за номером телефону — відповідь там завжди однакова,
 * щоб не підказувати, чи є такий номер.
 */
final class PasswordSetup
{
    public const int MIN_LENGTH = 10;
    public const int MAX_LENGTH = 128;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelegramUserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PlanEventRecorder $events,
        private readonly Nutgram $bot,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(APP_BASE_URL)%')]
        private readonly string $baseUrl = 'http://localhost',
        #[Autowire('%password_login_enabled%')]
        private readonly bool $enabled = false,
        #[Autowire('%tasks_enabled%')]
        private readonly bool $plan = true,
    ) {
    }

    /**
     * Лише «План» (TASKS_ENABLED) з увімкненим входом за паролем — на стендах заявок
     * паролі, як і раніше, задає адмін, і посилань там немає.
     */
    public function enabled(): bool
    {
        return $this->enabled && $this->plan;
    }

    /** Хто може отримати посилання: жива підтверджена картка з прив'язаним Telegram. */
    public static function eligible(?TelegramUser $user): bool
    {
        return $user !== null && ! $user->isArchived() && $user->isApproved() && TelegramLink::isLinked($user);
    }

    /**
     * Нове посилання (попередні невикористані гаснуть). Повертає абсолютний URL.
     */
    public function issue(TelegramUser $user, ?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable();
        $token = bin2hex(random_bytes(32));

        // Одночасні запити однієї людини — по черзі: чинне лише останнє посилання.
        $this->events->transactional(function () use ($user, $token, $now): void {
            $this->em->getConnection()->executeQuery('SELECT id FROM telegram_user WHERE id = ? FOR UPDATE', [$user->getId()]);
            $this->em->createQuery('DELETE FROM ' . PasswordToken::class . ' t WHERE t.user = :u OR t.expiresAt < :old')
                ->setParameter('u', $user)
                ->setParameter('old', $now->modify('-1 day'))
                ->execute();
            $this->em->persist(new PasswordToken($user, self::hash($token), $now));
            $this->em->flush();
        });

        return rtrim($this->baseUrl, '/') . '/vhid/parol/' . $token;
    }

    /**
     * Надіслати посилання в Telegram людини. false — немає Telegram або бот не доставив
     * (подробиці — у лозі; людині — загальна відповідь).
     */
    public function sendLink(TelegramUser $user, string $why): bool
    {
        if (! $this->enabled() || ! self::eligible($user)) {
            return false;
        }

        $url = $this->issue($user);
        $hasPassword = $user->getPassword() !== null;
        $text = sprintf(
            "🔑 <b>%s</b>\n\n%s\nПосилання діє %d хв і лише один раз. Нікому його не пересилайте.\n\nЯкщо ви цього не просили — просто нічого не робіть, пароль не зміниться.",
            $hasPassword ? 'Змінити пароль для входу в План' : 'Пароль для входу в План',
            htmlspecialchars($why),
            PasswordToken::TTL_MINUTES,
        );
        // Telegram відкриває кнопки лише з https; на локальному стенді — посилання текстом.
        $keyboard = str_starts_with($url, 'https://')
            ? InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make($hasPassword ? '🔑 Змінити пароль' : '🔑 Встановити пароль', url: $url))
            : null;

        if ($keyboard === null) {
            $text .= "\n\n" . htmlspecialchars($url);
        }

        try {
            $this->bot->sendMessage(text: $text, chat_id: $user->getChatId() ?: $user->getTelegramId(), parse_mode: ParseMode::HTML, reply_markup: $keyboard);
        } catch (Throwable $e) {
            $this->logger->warning('plan: посилання на пароль не доставлено', ['user' => $user->getId(), 'error' => $e->getMessage()]);

            return false;
        }

        $this->logger->info('plan: надіслано посилання на пароль', ['user' => $user->getId()]);

        return true;
    }

    /**
     * «Забули пароль?» зі сторінки входу: за телефоном (або email) — у Telegram, якщо
     * така жива людина є й Telegram прив'язаний. Нічого не повертає навмисно.
     */
    public function requestByLogin(string $login): void
    {
        $user = $this->findLive(LoginIdentity::normalize($login));

        if ($user !== null) {
            $this->sendLink($user, 'Ви попросили посилання на сторінці входу в План.');
        }
    }

    /** Жива картка за email або номером (останні 9 цифр); кілька живих збігів — нікого. */
    public function findLive(string $identifier): ?TelegramUser
    {
        if (str_contains($identifier, '@')) {
            $user = $this->users->findOneBy(['email' => $identifier]);

            return $user !== null && ! $user->isArchived() && $user->isApproved() ? $user : null;
        }

        $tail = LoginIdentity::phoneTail($identifier);

        return $tail !== null ? $this->users->findSingleLiveByPhoneTail($tail) : null;
    }

    /** Чинне посилання (для форми) або null. */
    public function find(string $token, ?DateTimeImmutable $now = null): ?PasswordToken
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $row = $this->em->getRepository(PasswordToken::class)->findOneBy(['tokenHash' => self::hash($token)]);

        return $row !== null && $row->isUsable($now ?? new DateTimeImmutable()) && self::eligibleForSet($row->getUser()) ? $row : null;
    }

    /** Чому пароль не годиться; null — годиться. */
    public static function policyError(TelegramUser $user, #[SensitiveParameter] string $password, #[SensitiveParameter] string $repeat): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $password);
        $phone = (string) preg_replace('/\D+/', '', (string) $user->getPhoneNumber());

        return match (true) {
            mb_strlen($password) < self::MIN_LENGTH => sprintf('Пароль — щонайменше %d символів.', self::MIN_LENGTH),
            mb_strlen($password) > self::MAX_LENGTH => sprintf('Пароль — не довше %d символів.', self::MAX_LENGTH),
            preg_match('/\p{L}/u', $password) !== 1 || $digits === '' => 'Пароль має містити і літери, і цифри.',
            $phone !== '' && strlen($phone) >= 9 && str_contains($digits, substr($phone, -9)) => 'Пароль не повинен містити ваш номер телефону.',
            count(array_unique(mb_str_split($password))) < 5 => 'Пароль надто простий — додайте різних символів.',
            $password !== $repeat => 'Паролі не збігаються.',
            default => null,
        };
    }

    /**
     * Зберегти пароль за посиланням. Повертає людину або кидає PasswordSetupException
     * з текстом для форми. Посилання гасне лише після успішного збереження.
     */
    public function complete(string $token, #[SensitiveParameter] string $password, #[SensitiveParameter] string $repeat, ?DateTimeImmutable $now = null): TelegramUser
    {
        $now ??= new DateTimeImmutable();

        [$user, $first] = $this->events->transactional(function () use ($token, $password, $repeat, $now): array {
            $row = $this->find($token, $now);

            if ($row === null) {
                throw new PasswordSetupException('Посилання застаріло або вже використане. Попросіть нове в боті: /parol.');
            }

            // Два одночасні POST тим самим посиланням: другий чекає на рядок і бачить used_at.
            $this->em->lock($row, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($row);

            if (! $row->isUsable($now)) {
                throw new PasswordSetupException('Посилання вже використане. Попросіть нове в боті: /parol.');
            }

            $user = $row->getUser();
            $error = self::policyError($user, $password, $repeat);

            if ($error !== null) {
                throw new PasswordSetupException($error);
            }

            $first = $user->getPassword() === null;
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $user->setPasswordSetAt($now);
            $row->markUsed($now);
            $this->em->flush();
            $this->events->record(new StaffChanged(SubjectRef::user((int) $user->getId()), $user->getId(), action: 'update', fields: ['password']));

            return [$user, $first];
        });

        // Після коміту: повідомлення в Telegram не має тримати транзакцію.
        $this->notifyChanged($user, $first);

        return $user;
    }

    private function notifyChanged(TelegramUser $user, bool $first): void
    {
        try {
            $this->bot->sendMessage(
                text: $first
                    ? '✅ Пароль для входу в План встановлено. Тепер можна входити й за телефоном та паролем — або, як і раніше, через Telegram.'
                    : "✅ Пароль для входу в План змінено.\nЯкщо це були не ви — одразу надішліть /parol і задайте новий, і повідомте адміністратора.",
                chat_id: $user->getChatId() ?: $user->getTelegramId(),
            );
        } catch (Throwable $e) {
            $this->logger->warning('plan: не вдалося повідомити про зміну пароля', ['user' => $user->getId(), 'error' => $e->getMessage()]);
        }
    }

    private static function eligibleForSet(TelegramUser $user): bool
    {
        return ! $user->isArchived() && $user->isApproved();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
