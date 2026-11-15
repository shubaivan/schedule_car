<?php

namespace App\Plan\Login;

use App\Entity\TelegramUser;
use App\Plan\Event\LoginFailed;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Login\Entity\LoginNonce;
use App\Plan\Subject\SubjectRef;
use App\Plan\Telegram\LinkResult;
use App\Plan\Telegram\PlanBotMode;
use App\Plan\Telegram\TelegramLink;
use App\Service\CrmLoginLink;
use App\Tasks\Exception\TaskException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

/**
 * Бот «Плану»: вхід через Telegram і заявки на приєднання (PlanBot передає сюди).
 *
 * - `/start login_<nonce>` — людину вже знаємо (Telegram прив'язаний) → вхід одразу
 *   підтверджено; не знаємо → просимо поділитися номером (лише раз).
 * - контакт — тільки власний (contact.user_id = from.id: номер підтвердив сам Telegram);
 *   номер є серед співробітників → прив'язуємо Telegram і підтверджуємо вхід; немає →
 *   заявка на приєднання суперадмінам.
 * - «🚫 Це був не я» під підтвердженням — скасувати цей вхід (і завершити ту сесію).
 * - «Затвердити» / «Відхилити» у суперадміна під заявкою.
 *
 * Кожна відмова — подія LoginFailed у журналі аудиту (група «Входи»).
 */
final class PlanLoginBot
{
    public const string NOT_ME = 'pl:x:';
    /** Кнопка з кодом: pl:c:<id спроби>:<код>. */
    public const string CODE = 'pl:c:';
    public const string JOIN_APPROVE = 'pj:a:';
    public const string JOIN_REJECT = 'pj:r:';

    public function __construct(
        private readonly TelegramLogin $login,
        private readonly JoinRequests $joins,
        private readonly JoinNotifier $notifier,
        private readonly TelegramLink $link,
        private readonly PlanEventRecorder $events,
        private readonly LoggerInterface $logger,
        private readonly CrmLoginLink $loginLinks,
        private readonly TelegramLinkAudit $audit,
        #[Autowire('%env(APP_BASE_URL)%')]
        private readonly string $baseUrl = 'http://localhost',
    ) {
    }

    /** /start login_<nonce>. $me — запис бота для цього Telegram (може бути «порожнім»). */
    public function start(Nutgram $bot, ?TelegramUser $me, string $nonce): void
    {
        $from = $bot->user();
        if ($from === null) {
            return;
        }

        $attempt = $this->login->claim($nonce, (string) $from->id);

        if ($attempt === null) {
            $this->fail($me, 'посилання для входу застаріле або вже використане');
            $this->say($bot, "⌛ Це посилання для входу вже не діє.\nПоверніться на сайт і натисніть «Увійти через Telegram» ще раз.", PlanBotMode::openButton($this->url('/vhid'), 'Відкрити План'));

            return;
        }

        if ($me !== null && TelegramLink::isLinked($me) && ! TelegramLink::isStub($me)) {
            $this->askCode($bot, $attempt, $me);

            return;
        }

        $this->say(
            $bot,
            "🔐 Вхід у План.\n\nЩоб ми вас упізнали, поділіться номером телефону — кнопка нижче. Це потрібно лише один раз.",
            self::contactKeyboard(),
        );
    }

    /** Повідомлення з контактом (кнопка «Поділитися номером»). */
    public function contact(Nutgram $bot, ?TelegramUser $me): void
    {
        $from = $bot->user();
        $contact = $bot->message()?->contact;

        if ($from === null || $contact === null) {
            return;
        }

        $telegramId = (string) $from->id;

        if ((string) $contact->user_id !== $telegramId) {
            $this->fail($me, 'надіслано чужий контакт');
            $this->say($bot, '⚠️ Надішліть, будь ласка, власний номер — кнопкою «📱 Поділитися номером».', self::contactKeyboard());

            return;
        }

        $attempt = $this->login->pendingFor($telegramId);
        $this->say($bot, 'Дякуємо, номер отримано.', ReplyKeyboardRemove::make(true));

        // Telegram уже прив'язаний до живої картки — номер не перевіряємо (міг змінитися).
        if ($me !== null && TelegramLink::isLinked($me) && ! TelegramLink::isStub($me)) {
            $attempt !== null
                ? $this->askCode($bot, $attempt, $me)
                : $this->say($bot, '✅ Ваш Telegram уже підключено до Плану.', $this->loginButton($me));

            return;
        }

        $staff = $this->login->staffByPhone((string) $contact->phone_number);

        if (count($staff) > 1) {
            $this->refuse($bot, $attempt, $me, 'номер є в кількох картках співробітників', "⛔ Цей номер записано в кількох картках співробітників.\nЗверніться, будь ласка, до адміністратора Плану.");

            return;
        }

        if (count($staff) === 1) {
            $this->linkByPhone($bot, $attempt, $me, $staff[0], $telegramId, (string) $bot->chatId());

            return;
        }

        [$result, $request] = $this->joins->submit([
            'id' => $telegramId,
            'chatId' => $bot->chatId() !== null ? (string) $bot->chatId() : null,
            'firstName' => $from->first_name,
            'lastName' => $from->last_name,
            'username' => $from->username,
        ], (string) $contact->phone_number);

        if ($attempt !== null) {
            $this->login->close($attempt, $result === JoinSubmit::Limited ? LoginNonceStatus::Denied : LoginNonceStatus::Join);
        }

        match ($result) {
            JoinSubmit::Created => $this->say($bot, "📨 Вашого номера ще немає в Плані.\nЗаявку на приєднання надіслано адміністраторам — щойно її розглянуть, ми напишемо вам тут."),
            JoinSubmit::AlreadyPending => $this->say($bot, '⏳ Ваша заявка на приєднання вже на розгляді. Ми напишемо вам тут, щойно її розглянуть.'),
            JoinSubmit::Limited => $this->say($bot, '⏳ Забагато заявок за добу. Спробуйте завтра або зверніться до адміністратора Плану.'),
        };

        if ($result === JoinSubmit::Created && $request !== null) {
            $this->notifier->requested($request, $this->joins->createdSince(new DateTimeImmutable('-1 hour')));
        }
    }

    /** Голий /start від людини, якої бот ще не знає: як увійти + кнопка «Поділитися номером». */
    public function welcome(Nutgram $bot): void
    {
        $this->say(
            $bot,
            sprintf("👋 Це бот «Плану» заводу.\n\nЩоб увійти, відкрийте %s і натисніть «Увійти через Telegram».\n\nАбо поділіться номером кнопкою нижче: якщо вас уже додали в План — Telegram підключиться; якщо ні — надішлемо заявку адміністраторам.", htmlspecialchars($this->url('/vhid'))),
            self::contactKeyboard(),
        );
    }

    /** Кнопки під повідомленнями цього класу; false — не наша кнопка. */
    public function callback(Nutgram $bot, ?TelegramUser $me): bool
    {
        $data = (string) $bot->callbackQuery()?->data;

        if (preg_match('/^' . preg_quote(self::CODE, '/') . '(\d+):(\d{4})$/', $data, $m) === 1) {
            $this->checkCode($bot, $me, (int) $m[1], $m[2]);

            return true;
        }

        if (str_starts_with($data, self::NOT_ME)) {
            $this->notMe($bot, $me, (int) substr($data, strlen(self::NOT_ME)));

            return true;
        }

        foreach ([self::JOIN_APPROVE => true, self::JOIN_REJECT => false] as $prefix => $approve) {
            if (str_starts_with($data, $prefix)) {
                $this->decide($bot, $me, (int) substr($data, strlen($prefix)), $approve);

                return true;
            }
        }

        return false;
    }

    private function notMe(Nutgram $bot, ?TelegramUser $me, int $attemptId): void
    {
        $from = $bot->user();
        $attempt = $from !== null ? $this->login->revoke($attemptId, (string) $from->id) : null;
        $bot->answerCallbackQuery(text: $attempt !== null ? 'Вхід скасовано' : 'Цей вхід уже не активний');

        if ($attempt === null) {
            return;
        }

        $this->fail($me, $attempt->getStatus() === LoginNonceStatus::Revoked ? 'скасовано в Telegram («Це був не я») — сесію завершено' : 'скасовано в Telegram («Це був не я»)');
        $this->edit($bot, "🚫 Вхід скасовано: той браузер у План не увійде (або вже вийшов).\nЯкщо ви не натискали «Увійти через Telegram» — повідомте адміністратора.");
    }

    private function decide(Nutgram $bot, ?TelegramUser $me, int $requestId, bool $approve): void
    {
        $request = $this->joins->find($requestId);

        if ($me === null || $request === null) {
            $bot->answerCallbackQuery(text: 'Заявку не знайдено.', show_alert: true);

            return;
        }

        try {
            if ($approve) {
                $user = $this->joins->approve($request, $me);
                $bot->answerCallbackQuery(text: 'Затверджено');
                $this->edit($bot, sprintf(
                    "%s\n\n✅ Затверджено як «%s» — %s.\nРоль і відділ можна змінити в картці.",
                    JoinNotifier::describe($request),
                    $user->getWorkRole()->label(),
                    htmlspecialchars($me->shortName()),
                ), PlanBotMode::openButton($this->url('/crm/staff/' . $user->getId()), 'Картка в Плані'));
            } else {
                $this->joins->reject($request, $me);
                $bot->answerCallbackQuery(text: 'Відхилено');
                $this->edit($bot, sprintf("%s\n\n✖ Відхилено — %s.", JoinNotifier::describe($request), htmlspecialchars($me->shortName())));
            }
        } catch (TaskException|AccessDeniedException $e) {
            $bot->answerCallbackQuery(text: $e->getMessage(), show_alert: true);

            return;
        }

        $this->notifier->decided($request, $me);
    }

    private function linkByPhone(Nutgram $bot, ?LoginNonce $attempt, ?TelegramUser $me, TelegramUser $user, string $telegramId, string $chatId): void
    {
        if (! TelegramLogin::canLogIn($user)) {
            $this->refuse($bot, $attempt, $me, 'доступ закрито (картка в кошику або не підтверджена)', "⛔ Доступ до Плану для вас закрито.\nЗверніться, будь ласка, до адміністратора.", $user);

            return;
        }

        // Номер не переносить людину з одного Telegram на інший: це робить лише вона сама з профілю.
        if (TelegramLink::isLinked($user) && $user->getTelegramId() !== $telegramId) {
            $this->refuse($bot, $attempt, $me, 'номер уже підключено до іншого Telegram', "⛔ Цей номер у Плані вже підключено до іншого Telegram.\nЗверніться, будь ласка, до адміністратора.", $user);

            return;
        }

        if ($this->link->attach($user, $telegramId, $chatId) === LinkResult::Taken) {
            $this->refuse($bot, $attempt, $me, 'цей Telegram уже має інша картка', "⛔ Цей Telegram уже підключено до іншої картки Плану.\nЗверніться, будь ласка, до адміністратора.", $user);

            return;
        }

        $this->audit->linkedByPhone($user, $bot->user());

        if ($attempt !== null) {
            $this->askCode($bot, $attempt, $user);

            return;
        }

        $this->say($bot, sprintf("✅ Telegram підключено до Плану, %s.\nНатисніть «Увійти в План» — або на сайті «Увійти через Telegram». Паролів не треба.", htmlspecialchars($user->shortName())), $this->loginButton($user));
    }

    /**
     * Підтвердження входу з браузера: звідки (браузер, IP, час) і три кнопки з кодами —
     * людина тисне той, що бачить на екрані. Посилання, яке хтось надіслав (зі своїм
     * браузером на іншому кінці), так не підтвердити: коду з чужого екрана людина не знає.
     */
    private function askCode(Nutgram $bot, LoginNonce $attempt, TelegramUser $user): void
    {
        if (! TelegramLogin::canLogIn($user)) {
            $this->refuse($bot, $attempt, $user, 'доступ закрито (картка в кошику або не підтверджена)', "⛔ Доступ до Плану для вас закрито.\nЗверніться, будь ласка, до адміністратора.", $user);

            return;
        }

        $codes = [$attempt->getCode()];
        while (count($codes) < 3) {
            $fake = sprintf('%04d', random_int(0, 9999));
            if (! in_array($fake, $codes, true)) {
                $codes[] = $fake;
            }
        }
        shuffle($codes);

        $keyboard = InlineKeyboardMarkup::make()->addRow(...array_map(
            fn (string $code) => InlineKeyboardButton::make($code, callback_data: self::CODE . $attempt->getId() . ':' . $code),
            $codes,
        ))->addRow(InlineKeyboardButton::make('🚫 Це не я', callback_data: self::NOT_ME . $attempt->getId()));

        $this->say($bot, sprintf(
            "🔐 %s, вхід у План з браузера%s.\n\nНатисніть код, який бачите на екрані входу.\n⚠️ Якщо вам це посилання <b>надіслали</b> або ви не входите зараз — натисніть «Це не я».",
            htmlspecialchars($user->shortName()),
            self::origin($attempt),
        ), $keyboard);
    }

    private function checkCode(Nutgram $bot, ?TelegramUser $me, int $attemptId, string $code): void
    {
        $from = $bot->user();
        $attempt = $from !== null ? $this->login->pendingById($attemptId, (string) $from->id) : null;

        if ($attempt === null || $me === null || ! TelegramLink::isLinked($me) || TelegramLink::isStub($me)) {
            $bot->answerCallbackQuery(text: 'Ця спроба входу вже не діє.', show_alert: true);
            $this->edit($bot, "⌛ Ця спроба входу вже не діє.\nНатисніть «Увійти через Telegram» на сайті ще раз.");

            return;
        }

        if (! hash_equals($attempt->getCode(), $code)) {
            $this->login->close($attempt, LoginNonceStatus::Denied);
            $this->fail($me, 'невірний код у боті (можливо, чуже посилання)' . self::origin($attempt));
            $bot->answerCallbackQuery(text: 'Код не збігся — вхід скасовано');
            $this->edit($bot, "⛔ Код не збігся — цей вхід скасовано.\nЯкщо вам надіслали посилання для входу — нікому його не підтверджуйте й повідомте адміністратора.");

            return;
        }

        if (! TelegramLogin::canLogIn($me) || ! $this->login->confirm($attempt, $me)) {
            $bot->answerCallbackQuery(text: 'Не вдалося підтвердити вхід.', show_alert: true);
            $this->edit($bot, "⌛ Час на вхід минув.\nНатисніть «Увійти через Telegram» на сайті ще раз.");

            return;
        }

        $bot->answerCallbackQuery(text: 'Вхід підтверджено');
        $this->edit($bot, sprintf(
            "✅ Вхід підтверджено, %s.\nПоверніться у браузер — План відкриється сам.%s\n\nЯкщо це були не ви — натисніть «Це був не я».",
            htmlspecialchars($me->shortName()),
            self::origin($attempt),
        ), InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('🚫 Це був не я', callback_data: self::NOT_ME . $attempt->getId())));
    }

    /** Кнопка «Увійти в План» — одноразове посилання (15 хв) просто з бота, без сайту. */
    public function loginButton(TelegramUser $user): ?InlineKeyboardMarkup
    {
        $issued = $this->loginLinks->issue($user);
        $path = (string) parse_url($issued, PHP_URL_PATH);

        return PlanBotMode::openButton($this->url($path), '🔐 Увійти в План');
    }

    private function refuse(Nutgram $bot, ?LoginNonce $attempt, ?TelegramUser $me, string $reason, string $text, ?TelegramUser $subject = null): void
    {
        if ($attempt !== null) {
            $this->login->close($attempt, LoginNonceStatus::Denied);
        }

        $this->fail($subject ?? $me, $reason);
        $this->say($bot, $text);
    }

    /** LoginFailed у журнал: на картку, якщо її впізнали, інакше — на «невідому людину». */
    private function fail(?TelegramUser $who, string $reason): void
    {
        $known = $who !== null && ! TelegramLink::isStub($who);

        try {
            $this->events->record(new LoginFailed(
                $known ? SubjectRef::user((int) $who->getId()) : SubjectRef::unknownUser(),
                null,
                name: $who !== null ? mb_substr('Telegram: ' . $who->shortName(), 0, 120) : 'Telegram',
                reason: $reason,
            ));
        } catch (Throwable $e) {
            $this->logger->warning('plan: невдалий вхід через Telegram не записано в журнал', ['error' => $e->getMessage()]);
        }
    }

    /** « · Chrome, Android · IP 1.2.3.4 · 13:05» — щоб людина впізнала свій браузер. */
    private static function origin(LoginNonce $attempt): string
    {
        $agent = (string) $attempt->getUserAgent();
        $name = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
        $os = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };
        $parts = array_filter([$name !== null || $os !== null ? implode(', ', array_filter([$name, $os])) : null, $attempt->getIp() !== null ? 'IP ' . $attempt->getIp() : null, $attempt->getCreatedAt()->format('H:i')]);

        return ' · ' . implode(' · ', $parts);
    }

    public static function contactKeyboard(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
            ->addRow(KeyboardButton::make('📱 Поділитися номером', request_contact: true));
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    private function say(Nutgram $bot, string $text, InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|null $markup = null): void
    {
        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }

    private function edit(Nutgram $bot, string $text, ?InlineKeyboardMarkup $markup = null): void
    {
        try {
            $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
        } catch (Throwable $e) {
            // Повідомлення застаріло (48 год) чи вже змінене — не причина падати вебхуку.
            $this->logger->info('plan: не вдалося оновити повідомлення бота', ['error' => $e->getMessage()]);
            $this->say($bot, $text, $markup);
        }
    }
}
