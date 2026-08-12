<?php

namespace App\Supply\Telegram;

use App\Service\TelegramUserService;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\RequestFormatter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** Картка заявки з хронологією: supply:view:{id}. */
class RequestView
{
    public function __construct(
        private SupplyRequestRepository $repository,
        private TelegramUserService $telegramUserService,
        private RequestFormatter $formatter,
    ) {
    }

    public function __invoke(Nutgram $bot, string $id): void
    {
        $bot->answerCallbackQuery();

        $user = $this->telegramUserService->getCurrentUser();
        $request = $this->repository->find((int)$id);

        if ($request === null || $user === null) {
            $bot->sendMessage(text: '⚠️ Заявку не знайдено.');

            return;
        }

        $isManager = $user->getSupplyRole()->canManage();

        if (!$isManager && $request->getAuthor()->getId() !== $user->getId()) {
            $bot->sendMessage(text: '⚠️ Ця заявка не ваша.');

            return;
        }

        $bot->sendMessage(
            text: $this->formatter->card($request, forManager: $isManager) . "\n" . $this->formatter->timeline($request),
            parse_mode: ParseMode::HTML,
            reply_markup: $this->keyboard($request, $isManager),
        );
    }

    private function keyboard(SupplyRequest $request, bool $isManager): InlineKeyboardMarkup
    {
        $markup = InlineKeyboardMarkup::make();
        $id = (int)$request->getId();

        if ($isManager) {
            $row = [];
            foreach ($request->getStatus()->allowedTransitions() as $next) {
                if ($next === SupplyStatus::Rejected) {
                    continue;
                }
                $row[] = InlineKeyboardButton::make(
                    $next->labelWithEmoji(),
                    callback_data: SupplyCallback::status($id, $next->value),
                );
                if (count($row) === 2) {
                    $markup->addRow(...$row);
                    $row = [];
                }
            }
            if ($row) {
                $markup->addRow(...$row);
            }

            if ($request->getStatus()->canTransitionTo(SupplyStatus::Rejected)) {
                $markup->addRow(
                    InlineKeyboardButton::make('⛔ Відхилити', callback_data: SupplyCallback::reject($id)),
                );
            }
        }

        $markup->addRow(
            InlineKeyboardButton::make('💬 Коментар', callback_data: SupplyCallback::comment($id)),
            InlineKeyboardButton::make('📋 Мої заявки', callback_data: SupplyCallback::MY_REQUESTS),
        );

        return $markup;
    }
}
