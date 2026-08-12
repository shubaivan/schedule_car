<?php

namespace App\Supply\Telegram;

use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\RequestFormatter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** Картка заявки з хронологією: supply:view:{id}. */
class RequestView
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

        if ($request === null || $user === null) {
            $this->screen->render($bot, '⚠️ Заявку не знайдено.');

            return;
        }

        $isManager = $user->getSupplyRole()->canManage();

        if (! $isManager && $request->getAuthor()->getId() !== $user->getId()) {
            $this->screen->render($bot, '⚠️ Ця заявка не ваша.');

            return;
        }

        $this->show($bot, $request, $isManager);
    }

    /**
     * Показати картку як поточний екран. Викликається і після зміни статусу чи
     * коментаря — щоб менеджер бачив свіжий набір дій, а не застарілі кнопки.
     */
    public function show(Nutgram $bot, SupplyRequest $request, bool $isManager): void
    {
        $this->screen->render(
            $bot,
            $this->formatter->card($request, forManager: $isManager) . "\n" . $this->formatter->timeline($request),
            $this->keyboard($request, $isManager),
        );
    }

    private function keyboard(SupplyRequest $request, bool $isManager): InlineKeyboardMarkup
    {
        $markup = InlineKeyboardMarkup::make();
        $id = (int) $request->getId();

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

            // Записати покупку можна й окремо від зміни статусу: домовились із
            // постачальником сьогодні, а оплата пройде завтра.
            if (! $request->getStatus()->isFinal()) {
                $markup->addRow(
                    InlineKeyboardButton::make(
                        $request->isPurchased() ? '🧾 Ще постачальник' : '🧾 Закупівля',
                        callback_data: SupplyCallback::purchase($id),
                    ),
                );
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
