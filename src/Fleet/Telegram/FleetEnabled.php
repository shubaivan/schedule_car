<?php

namespace App\Fleet\Telegram;

use App\Service\ChatScreen;
use App\Service\FleetSection;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Пропускає в автопарк, лише поки розділ увімкнено.
 *
 * Кнопки автопарку живуть у старих повідомленнях чату, і після вимкнення
 * розділу вони нікуди не діваються. Мовчазна кнопка виглядає як зламаний бот,
 * тому замість мовчання показуємо екран із поясненням і виходом на головну.
 */
class FleetEnabled
{
    public function __construct(
        private FleetSection $fleet,
        private ChatScreen $screen,
    ) {
    }

    public function __invoke(Nutgram $bot, callable $next): void
    {
        if ($this->fleet->isEnabled()) {
            $next($bot);

            return;
        }

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $this->screen->render(
            $bot,
            "🚗 <b>Автопарк</b>\nРозділ тимчасово вимкнено.",
            InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
        );
    }
}
