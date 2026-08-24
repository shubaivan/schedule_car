<?php

namespace App\Supply\Telegram;

use App\Service\TelegramUserService;
use App\Supply\Enum\SupplyAccent;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\MarkRequest;
use SergiX44\Nutgram\Nutgram;

/** Поставити чи прибрати мітку: supply:mark:{id}:{accent}. */
class SetAccentAction
{
    public function __construct(
        private SupplyRequestRepository $repository,
        private TelegramUserService $telegramUserService,
        private MarkRequest $markRequest,
        private RequestView $view,
    ) {
    }

    public function __invoke(Nutgram $bot, string $id, string $accent): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $request = $this->repository->find((int) $id);
        $value = SupplyAccent::tryFrom($accent);

        if ($request === null || $user === null || $value === null) {
            $bot->answerCallbackQuery(text: '⚠️ Заявку не знайдено.', show_alert: true);

            return;
        }

        try {
            ($this->markRequest)($request, $value, $user);
        } catch (SupplyException $e) {
            $bot->answerCallbackQuery(text: $e->getMessage(), show_alert: true);

            return;
        }

        $bot->answerCallbackQuery(text: $value->isSet() ? $value->label() : 'Мітку прибрано');

        // Повертаємось у картку: менеджер має одразу бачити заявку так само,
        // як її побачить керівник.
        $this->view->show($bot, $request, $user);
    }
}
