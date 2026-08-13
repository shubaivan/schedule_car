<?php

namespace App\Supply\Telegram;

use App\Supply\Repository\SupplyRequestRepository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/**
 * «📋 Усі заявки» — спільна картина для всіх підрозділів.
 *
 * Бачить кожен підтверджений користувач, а не лише менеджер: так у цеху знають,
 * що вже замовлено й на якому воно етапі, і не подають те саме вдруге.
 * Керують заявкою й далі тільки менеджер та автор — див. RequestView.
 */
class AllRequests
{
    /** Стільки заявок читається одним екраном; глибше — у CRM. */
    private const LIMIT = 15;

    public function __construct(
        private SupplyRequestRepository $repository,
        private RequestListScreen $list,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        // Порядок той самий, що й у CRM: термінові вгору, далі найновіші.
        $requests = $this->repository->search([], 1, self::LIMIT)['items'];

        $this->list->render(
            $bot,
            sprintf('📋 <b>Усі заявки</b> — останні %d', self::LIMIT),
            $requests,
            'Заявок поки немає.',
            [[
                InlineKeyboardButton::make('➕ Нова заявка', callback_data: SupplyCallback::NEW_REQUEST),
                InlineKeyboardButton::make('📋 Мої заявки', callback_data: SupplyCallback::MY_REQUESTS),
            ]],
        );
    }
}
