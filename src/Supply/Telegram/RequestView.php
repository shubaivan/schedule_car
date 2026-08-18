<?php

namespace App\Supply\Telegram;

use App\Entity\TelegramUser;
use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\RequestFormatter;
use App\Telegram\Start\Command\StartCommand;
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

        // Картку відкриває будь-хто зі своїх: заявки підрозділів навмисно
        // спільні. Кнопки дій отримає лише той, кому справді можна.
        $this->show($bot, $request, $user);
    }

    /**
     * Показати картку як поточний екран. Викликається і після зміни статусу чи
     * коментаря — щоб менеджер бачив свіжий набір дій, а не застарілі кнопки.
     */
    public function show(Nutgram $bot, SupplyRequest $request, TelegramUser $viewer): void
    {
        $isManager = $viewer->getSupplyRole()->canManage();

        $this->screen->render(
            $bot,
            $this->formatter->card($request, forManager: $isManager) . "\n" . $this->formatter->timeline($request),
            $this->keyboard($request, $viewer),
        );
    }

    private function keyboard(SupplyRequest $request, TelegramUser $viewer): InlineKeyboardMarkup
    {
        $isManager = $viewer->getSupplyRole()->canManage();
        // Автор і менеджер ведуть діалог по заявці й носять до неї документи;
        // решта підрозділів дивиться.
        $isOwn = $isManager || $request->getAuthor()->getId() === $viewer->getId();

        $markup = InlineKeyboardMarkup::make();
        $id = (int) $request->getId();
        $status = $request->getStatus();
        $role = $viewer->getSupplyRole();

        // Кнопку показуємо тільки тому, хто справді може так зрушити заявку:
        // директор бачить «Оплачено» на затвердженні, менеджер — усе інше.
        $row = [];
        foreach ($status->allowedTransitions() as $next) {
            if ($next === SupplyStatus::Rejected || ! $role->canMoveRequest($status, $next)) {
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
        if ($isManager && ! $status->isFinal()) {
            $markup->addRow(
                InlineKeyboardButton::make(
                    $request->isPurchased() ? '🧾 Ще постачальник' : '🧾 Закупівля',
                    callback_data: SupplyCallback::purchase($id),
                ),
            );
        }

        if ($status->canTransitionTo(SupplyStatus::Rejected) &&
            $role->canMoveRequest($status, SupplyStatus::Rejected)
        ) {
            $markup->addRow(
                InlineKeyboardButton::make('⛔ Відхилити', callback_data: SupplyCallback::reject($id)),
            );
        }

        if ($isOwn) {
            $markup->addRow(
                InlineKeyboardButton::make('💬 Коментар', callback_data: SupplyCallback::comment($id)),
                InlineKeyboardButton::make('📎 Накладна', callback_data: SupplyCallback::attach($id)),
            );
        }

        $markup->addRow(
            InlineKeyboardButton::make('📋 Мої заявки', callback_data: SupplyCallback::MY_REQUESTS),
            InlineKeyboardButton::make('📋 Усі заявки', callback_data: SupplyCallback::ALL_REQUESTS),
        );

        $markup->addRow(...StartCommand::navRow(SupplyCallback::MENU, '⬅️ Постачання'));

        return $markup;
    }
}
