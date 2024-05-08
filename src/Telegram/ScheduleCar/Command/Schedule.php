<?php

namespace App\Telegram\ScheduleCar\Command;

use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class Schedule extends Command
{
    protected string $command = 'schedule';
    protected ?string $description = 'Бронювання';

    public function handle(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: 'Бронювання:',
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('Бронювання машини', callback_data: 'schedule-car'),
                InlineKeyboardButton::make('Переглянути свої', callback_data: 'own-schedule'),
            )
        );
    }
}