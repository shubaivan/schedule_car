<?php

namespace App\Plan\Login;

use App\Entity\TelegramUser;
use App\Plan\Login\Entity\JoinRequest;
use App\Plan\Telegram\PlanBotMode;
use App\Plan\Telegram\TelegramLink;
use App\Repository\TelegramUserRepository;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Повідомлення бота «Плану» про заявки на приєднання: суперадмінам — нова заявка з
 * кнопками «Затвердити» / «Відхилити» і посиланням у План; людині — рішення; іншим
 * суперадмінам — хто вже вирішив. Збій відправки не ламає саму заявку (лише лог).
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

    public function requested(JoinRequest $request): void
    {
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('✅ Затвердити', callback_data: PlanLoginBot::JOIN_APPROVE . $request->getId()),
            InlineKeyboardButton::make('✖ Відхилити', callback_data: PlanLoginBot::JOIN_REJECT . $request->getId()),
        );
        $url = $this->url('/crm/admin/join/' . $request->getId());

        if (str_starts_with($url, 'https://')) {
            $keyboard->addRow(InlineKeyboardButton::make('Відкрити в Плані (роль, відділ)', url: $url));
        }

        foreach ($this->superadmins() as $admin) {
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

        foreach ($this->superadmins() as $admin) {
            if ($admin->getId() !== $by->getId()) {
                $this->send($admin, sprintf('ℹ️ Заявку №%d (%s) %s — %s.', (int) $request->getId(), htmlspecialchars($request->name()), $approved ? 'затверджено' : 'відхилено', htmlspecialchars($by->shortName())));
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
