<?php

namespace App\Telegram\Start\Command;

use SergiX44\Nutgram\Nutgram;

/** Кнопка «🏠 На головну». */
class MainMenu
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $bot->sendMessage(
            text: 'Головне меню:',
            reply_markup: StartCommand::mainMenuKeyboard(),
        );
    }
}
