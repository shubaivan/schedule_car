<?php

namespace App\Supply\Telegram;

use App\Service\TelegramUserService;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Exception\SupplyException;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\ChangeStatus;
use SergiX44\Nutgram\Nutgram;

/** Кнопки менеджера прямо з повідомлення: supply:status:{id}:{status}. */
class ChangeStatusAction
{
    public function __construct(
        private SupplyRequestRepository $repository,
        private TelegramUserService $telegramUserService,
        private ChangeStatus $changeStatus,
        private RequestView $view,
    ) {
    }

    public function __invoke(Nutgram $bot, string $id, string $status): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $request = $this->repository->find((int) $id);
        $target = SupplyStatus::tryFrom($status);

        if ($request === null || $user === null || $target === null) {
            $bot->answerCallbackQuery(text: '⚠️ Заявку не знайдено.', show_alert: true);

            return;
        }

        // Статус вимагає закупівлі, а її ще немає: замість відмови одразу
        // питаємо постачальника й суму — після відповіді статус зміниться сам.
        if ($target->requiresPurchase() && ! $request->isPurchased()) {
            $bot->answerCallbackQuery();
            PurchaseConversation::begin($bot, data: [(int) $request->getId(), $target->value]);

            return;
        }

        try {
            ($this->changeStatus)($request, $target, $user);
        } catch (SupplyException $e) {
            // Найчастіше це повторне натискання вже застосованої кнопки:
            // спливашка пояснює і не засмічує чат.
            $bot->answerCallbackQuery(text: '⚠️ ' . $e->getMessage(), show_alert: true);
            $this->view->show($bot, $request, $user->getSupplyRole()->canManage());

            return;
        }

        $bot->answerCallbackQuery(
            text: sprintf('✅ %s. Заявника сповіщено.', $target->label()),
        );

        // Перемальовуємо картку: набір доступних дій змінився разом зі статусом.
        $this->view->show($bot, $request, $user->getSupplyRole()->canManage());
    }
}
