<?php

namespace App\Supply\Telegram;

use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\RequestFormatter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** «📋 Мої заявки» — список заявок робітника зі статусами. */
class MyRequests
{
    private const LIMIT = 15;
    private const BUTTONS_PER_ROW = 3;

    public function __construct(
        private SupplyRequestRepository $repository,
        private TelegramUserService $telegramUserService,
        private RequestFormatter $formatter,
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

        $requests = $this->repository->findByAuthor($user, self::LIMIT);

        if (! $requests) {
            $this->screen->render($bot, 'У вас поки немає заявок.', SupplyMenu::keyboard());

            return;
        }

        $lines = ['📋 <b>Ваші заявки</b>', ''];
        $markup = InlineKeyboardMarkup::make();
        $row = [];

        foreach ($requests as $request) {
            $lines[] = $this->formatter->line($request);

            $row[] = InlineKeyboardButton::make(
                '№' . $request->getNumber(),
                callback_data: SupplyCallback::view((int) $request->getId()),
            );

            if (count($row) === self::BUTTONS_PER_ROW) {
                $markup->addRow(...$row);
                $row = [];
            }
        }

        if ($row) {
            $markup->addRow(...$row);
        }

        $markup->addRow(
            InlineKeyboardButton::make('➕ Нова заявка', callback_data: SupplyCallback::NEW_REQUEST),
        );

        $this->screen->render($bot, implode("\n", $lines), $markup);
    }
}
