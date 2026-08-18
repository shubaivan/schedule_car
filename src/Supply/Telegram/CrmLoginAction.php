<?php

namespace App\Supply\Telegram;

use App\Entity\LoginToken;
use App\Service\CrmLoginLink;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\LinkPreviewOptions;

/**
 * «🔐 Вхід у CRM»: бот видає одноразове посилання.
 *
 * Доступ має кожен підтверджений користувач: робітник заходить подивитись
 * спільну картину заявок, а керує ними менеджер — це вирішує вже сама CRM.
 *
 * Посилання йде КНОПКОЮ, а не текстом: текстове посилання Telegram відкриває сам,
 * щоб побудувати превʼю, і одноразовий токен згорає ще до кліку користувача.
 */
class CrmLoginAction
{
    public function __construct(
        private TelegramUserService $telegramUserService,
        private CrmLoginLink $loginLink,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $user = $this->telegramUserService->getCurrentUser();

        if ($user === null) {
            $bot->sendMessage(text: '⚠️ Натисніть /start, щоб бот вас упізнав.');

            return;
        }

        $url = $this->loginLink->issue($user);

        $bot->sendMessage(
            text: sprintf(
                "🔐 <b>Вхід у CRM</b>\n\nКнопка нижче діє %d хвилин і лише один раз. Нікому її не пересилайте.",
                LoginToken::TTL_MINUTES,
            ),
            parse_mode: ParseMode::HTML,
            link_preview_options: new LinkPreviewOptions(is_disabled: true),
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🔐 Відкрити CRM', url: $url))
                ->addRow(...StartCommand::navRow(SupplyCallback::MENU, '⬅️ Постачання')),
        );
    }
}
