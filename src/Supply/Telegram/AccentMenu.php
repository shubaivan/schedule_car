<?php

namespace App\Supply\Telegram;

use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use App\Supply\Enum\SupplyAccent;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\RequestFormatter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** Вибір мітки для керівника: supply:accent:{id}. */
class AccentMenu
{
    public function __construct(
        private SupplyRequestRepository $repository,
        private TelegramUserService $telegramUserService,
        private RequestFormatter $formatter,
        private ChatScreen $screen,
    ) {
    }

    public function __invoke(Nutgram $bot, string $id): void
    {
        $bot->answerCallbackQuery();

        $user = $this->telegramUserService->getCurrentUser();
        $request = $this->repository->find((int) $id);

        if ($request === null || $user === null || ! $user->getSupplyRole()->canManage()) {
            $this->screen->render($bot, '⚠️ Заявку не знайдено.');

            return;
        }

        $requestId = (int) $request->getId();
        $markup = InlineKeyboardMarkup::make();

        foreach (SupplyAccent::marks() as $accent) {
            $markup->addRow(InlineKeyboardButton::make(
                sprintf(
                    '%s %s%s',
                    $accent->emoji(),
                    $accent->label(),
                    $request->getAccent() === $accent ? ' ✓' : '',
                ),
                callback_data: SupplyCallback::mark($requestId, $accent->value),
            ));
        }

        if ($request->getAccent()->isSet()) {
            $markup->addRow(InlineKeyboardButton::make(
                '✖️ Прибрати мітку',
                callback_data: SupplyCallback::mark($requestId, SupplyAccent::None->value),
            ));
        }

        $markup->addRow(InlineKeyboardButton::make(
            '⬅️ До заявки',
            callback_data: SupplyCallback::view($requestId),
        ));

        $this->screen->render(
            $bot,
            sprintf(
                "🏷 <b>Мітка заявки №%s</b>\nЇї бачить керівник у своїй картці — так видно, що дивитись першим.",
                $this->formatter->escape($request->getNumber()),
            ),
            $markup,
        );
    }
}
