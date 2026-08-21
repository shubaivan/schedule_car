<?php

namespace App\Telegram\Start\Command;

use App\Fleet\Telegram\FleetCallback;
use App\Repository\CarDriverRepository;
use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Розділ «Автопарк».
 *
 * Три ролі дивляться сюди по-різному: заявник бронює й дивиться свої поїздки,
 * водій — свої рейси, керівник — завантаження всього парку. Тому розклад стоїть першим:
 * він потрібен усім трьом, а кнопка «Мої рейси» з'являється лише в того, за ким
 * справді закріплена машина, щоб не бути мертвою для решти.
 */
class FleetMenu
{
    public function __construct(
        private ChatScreen $screen,
        private CarDriverRepository $carDrivers,
        private TelegramUserService $telegramUserService,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $user = $this->telegramUserService->getCurrentUser();
        $isDriver = $user !== null && $this->carDrivers->findOneByDriver($user) !== null;

        $markup = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('📅 Розклад машин', callback_data: FleetCallback::SCHEDULE))
            ->addRow(InlineKeyboardButton::make('➕ Забронювати', callback_data: FleetCallback::BOOK))
            ->addRow(InlineKeyboardButton::make('🚗 Мої поїздки', callback_data: FleetCallback::MY_TRIPS));

        if ($isDriver) {
            $markup->addRow(InlineKeyboardButton::make('🚚 Мої рейси', callback_data: FleetCallback::DRIVER_TRIPS));
        }

        $markup->addRow(StartCommand::homeButton());

        $this->screen->render(
            $bot,
            "🚗 <b>Автопарк</b>\nУ «Розкладі» — календар завантаження: яка машина й водій зайняті, "
            . 'на коли, ким і куди їдуть. Видно вільні машини на кожен день.',
            $markup,
        );
    }
}
