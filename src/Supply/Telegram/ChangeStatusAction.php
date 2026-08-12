<?php

namespace App\Supply\Telegram;

use App\Service\TelegramUserService;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\ChangeStatus;
use App\Supply\Service\RequestFormatter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/** Кнопки менеджера прямо з повідомлення: supply:status:{id}:{status}. */
class ChangeStatusAction
{
    public function __construct(
        private SupplyRequestRepository $repository,
        private TelegramUserService $telegramUserService,
        private ChangeStatus $changeStatus,
        private RequestFormatter $formatter,
    ) {
    }

    public function __invoke(Nutgram $bot, string $id, string $status): void
    {
        $bot->answerCallbackQuery();

        $user = $this->telegramUserService->getCurrentUser();
        $request = $this->repository->find((int)$id);
        $target = SupplyStatus::tryFrom($status);

        if ($request === null || $user === null || $target === null) {
            $bot->sendMessage(text: '⚠️ Заявку не знайдено.');

            return;
        }

        try {
            ($this->changeStatus)($request, $target, $user);
        } catch (SupplyException $e) {
            $bot->sendMessage(text: '⚠️ ' . $this->formatter->escape($e->getMessage()));

            return;
        }

        $bot->sendMessage(
            text: sprintf(
                "✅ Заявка №%s → <b>%s</b>. Заявника сповіщено.",
                $this->formatter->escape($request->getNumber()),
                $this->formatter->escape($target->label()),
            ),
            parse_mode: ParseMode::HTML,
        );
    }
}
