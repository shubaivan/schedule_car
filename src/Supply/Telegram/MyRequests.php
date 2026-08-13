<?php

namespace App\Supply\Telegram;

use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use App\Supply\Repository\SupplyRequestRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/** «📋 Мої заявки» — список заявок робітника зі статусами. */
class MyRequests
{
    private const LIMIT = 15;

    public function __construct(
        private SupplyRequestRepository $repository,
        private TelegramUserService $telegramUserService,
        private RequestListScreen $list,
        private ChatScreen $screen,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $user = $this->telegramUserService->getCurrentUser();

        if ($user === null) {
            $this->screen->render($bot, '⚠️ Натисніть /start, щоб бот вас упізнав.');

            return;
        }

        $this->list->render(
            $bot,
            '📋 <b>Ваші заявки</b>',
            $this->repository->findByAuthor($user, self::LIMIT),
            'У вас поки немає заявок.',
            [[
                InlineKeyboardButton::make('➕ Нова заявка', callback_data: SupplyCallback::NEW_REQUEST),
                InlineKeyboardButton::make('📋 Усі заявки', callback_data: SupplyCallback::ALL_REQUESTS),
            ]],
        );
    }
}
