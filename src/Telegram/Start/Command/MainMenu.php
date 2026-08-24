<?php

namespace App\Telegram\Start\Command;

use App\Service\ChatScreen;
use App\Service\FleetSection;
use SergiX44\Nutgram\Nutgram;

/** Кнопка «🏠 На головну». */
class MainMenu
{
    public function __construct(
        private ChatScreen $screen,
        private FleetSection $fleet,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $this->screen->render($bot, 'Головне меню:', StartCommand::mainMenuKeyboard($this->fleet->isEnabled()));
    }
}
