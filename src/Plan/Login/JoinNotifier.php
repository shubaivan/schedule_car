<?php

namespace App\Plan\Login;

use App\Entity\TelegramUser;
use App\Plan\Login\Entity\JoinRequest;
use App\Plan\People\PhoneFormat;
use App\Plan\Telegram\PlanBotMode;
use App\Plan\Telegram\TelegramLink;
use App\Repository\TelegramUserRepository;
use App\Tasks\Enum\WorkRole;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Повідомлення бота «Плану» про заявки на приєднання: тим, хто їх розглядає (суперадміни
 * й системні адміністратори, JoinRequests::canDecide), — нова заявка з кнопками
 * «Затвердити» / «Відхилити» і посиланням у План; людині — рішення; іншим, хто розглядає, —
 * хто вже вирішив. Про підключення Telegram за номером — лише суперадмінам.
 * Збій відправки не ламає саму заявку (лише лог).
 */
final class JoinNotifier
{
    public function __construct(
        private readonly Nutgram $bot,
        private readonly TelegramUserRepository $users,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(APP_BASE_URL)%')]
        private readonly string $baseUrl = 'http://localhost',
    ) {
    }

    /** Більше стількох заявок за годину — адміністраторам не пишемо (заявки лишаються в Плані). */
    public const int NOTIFY_PER_HOUR = 20;

    public function requested(JoinRequest $request, int $lastHour = 0): void
    {
        if ($lastHour > self::NOTIFY_PER_HOUR) {
            $this->logger->warning('plan: забагато заявок на приєднання — адміністраторам не пишемо', ['lastHour' => $lastHour]);

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('✅ Затвердити', callback_data: PlanLoginBot::JOIN_APPROVE . $request->getId()),
            InlineKeyboardButton::make('✖ Відхилити', callback_data: PlanLoginBot::JOIN_REJECT . $request->getId()),
        );
        $url = $this->url('/crm/admin/join/' . $request->getId());

        if (str_starts_with($url, 'https://')) {
            $keyboard->addRow(InlineKeyboardButton::make('Відкрити в Плані (роль, відділ)', url: $url));
        }

        foreach ($this->deciders() as $admin) {
            $this->send($admin, self::describe($request) . "\n\n«Затвердити» — роль «Виробництво», без відділу; обрати роль і відділ — у Плані.", $keyboard);
        }
    }

    public function decided(JoinRequest $request, TelegramUser $by): void
    {
        $approved = $request->getStatus() === JoinRequestStatus::Approved;
        $chatId = $request->getChatId() ?? $request->getTelegramId();

        try {
            $this->bot->sendMessage(
                text: $approved
                    ? "✅ Вашу заявку затверджено — вас додано до Плану.\nДля входу натисніть «Увійти через Telegram» на сайті."
                    : "Вашу заявку на приєднання до Плану відхилено.\nЯкщо це помилка — зверніться до адміністратора.",
                chat_id: $chatId,
                parse_mode: ParseMode::HTML,
                reply_markup: $approved ? PlanBotMode::openButton($this->url('/vhid'), 'Відкрити План') : null,
            );
        } catch (Throwable $e) {
            $this->logger->warning('plan: рішення щодо заявки не доставлено заявнику', ['request' => $request->getId(), 'error' => $e->getMessage()]);
        }

        foreach ($this->deciders() as $admin) {
            if ($admin->getId() !== $by->getId()) {
                $this->send($admin, sprintf('ℹ️ Заявку №%d (%s) %s — %s.', (int) $request->getId(), htmlspecialchars($request->name()), $approved ? 'затверджено' : 'відхилено', htmlspecialchars(PhoneFormat::named($by->shortName(), $by->getPhoneNumber()))));
            }
        }
    }

    /** Хтось уперше підключив Telegram до картки за номером — суперадмінам для відома (крім самого себе). */
    public function linked(TelegramUser $user, ?string $username): void
    {
        foreach ($this->superadmins() as $admin) {
            if ($admin->getId() !== $user->getId()) {
                $this->send($admin, sprintf(
                    "🔗 %s підключив(ла) Telegram%s до Плану за своїм номером.\nЯкщо це не та людина — відкрийте картку й зверніться до неї.",
                    htmlspecialchars(PhoneFormat::named($user->shortName(), $user->getPhoneNumber())),
                    $username !== null ? ' (@' . htmlspecialchars($username) . ')' : '',
                ), str_starts_with($this->url('/'), 'https://') ? InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('Картка в Плані', url: $this->url('/crm/staff/' . $user->getId()))) : null);
            }
        }
    }

    /** Текст заявки для суперадміна: ім'я, @username, повний номер, коли. */
    public static function describe(JoinRequest $request): string
    {
        return sprintf(
            "🙋 <b>Заявка на приєднання до Плану №%d</b>\n%s%s\n📱 %s\n🕒 %s",
            (int) $request->getId(),
            htmlspecialchars($request->name()),
            $request->getUsername() !== null ? ' (@' . htmlspecialchars($request->getUsername()) . ')' : '',
            JoinRequests::formatPhone($request->getPhone()),
            $request->getCreatedAt()->format('d.m.Y H:i'),
        );
    }

    /** @return list<TelegramUser> суперадміни, яким бот може написати */
    private function superadmins(): array
    {
        return array_values(array_filter(
            $this->users->findBy(['superadmin' => true]),
            static fn (TelegramUser $u) => TelegramLogin::canLogIn($u) && TelegramLink::isLinked($u),
        ));
    }

    /**
     * @return list<TelegramUser> ті, хто може вирішити заявку (суперадміни + системні адміністратори),
     *                            і кому бот може написати; кожен — один раз
     */
    private function deciders(): array
    {
        $out = [];

        foreach ([...$this->users->findBy(['superadmin' => true]), ...$this->users->findBy(['workRole' => WorkRole::SysAdmin])] as $u) {
            if (JoinRequests::canDecide($u) && TelegramLogin::canLogIn($u) && TelegramLink::isLinked($u)) {
                $out[(int) $u->getId()] = $u;
            }
        }

        return array_values($out);
    }

    private function send(TelegramUser $to, string $text, ?InlineKeyboardMarkup $keyboard = null): void
    {
        try {
            $this->bot->sendMessage(text: $text, chat_id: $to->getChatId() ?: $to->getTelegramId(), parse_mode: ParseMode::HTML, reply_markup: $keyboard);
        } catch (Throwable $e) {
            $this->logger->warning('plan: повідомлення про заявку не доставлено', ['to' => $to->getId(), 'error' => $e->getMessage()]);
        }
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }
}
