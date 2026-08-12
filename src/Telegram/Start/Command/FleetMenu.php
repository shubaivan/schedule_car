<?php

namespace App\Telegram\Start\Command;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** Розділ «Автопарк» — той самий сценарій бронювання, що був раніше. */
class FleetMenu
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $bot->sendMessage(
            text: 'Бронювання:',
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(
                    InlineKeyboardButton::make('Створити', callback_data: 'schedule-car'),
                    InlineKeyboardButton::make('Переглянути свої', callback_data: 'own-schedule'),
                )
                ->addRow(StartCommand::homeButton()),
        );
    }
}
