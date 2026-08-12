<?php

namespace App\Telegram\Start\Command;

use App\Service\ChatScreen;
use App\Supply\Telegram\SupplyCallback;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Головне меню: два розділи — постачання і автопарк.
 * Один бот на обидва напрямки, щоб працівник тримав один контакт і одну реєстрацію.
 */
class StartCommand extends Command
{
    public const MAIN_MENU = 'main-menu';
    public const FLEET_MENU = 'fleet-menu';

    protected string $command = 'start';
    protected ?string $description = 'Початок спілкування';

    /**
     * ChatScreen приходить параметром, а не через конструктор: команди Nutgram
     * створює через new під час реєстрації маршрутів, повз контейнер.
     */
    public function handle(Nutgram $bot, ChatScreen $screen): void
    {
        $screen->render($bot, 'Вітаю! Оберіть розділ:', self::mainMenuKeyboard());
    }

    public static function mainMenuKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('📦 Постачання', callback_data: SupplyCallback::MENU),
            InlineKeyboardButton::make('🚗 Автопарк', callback_data: self::FLEET_MENU),
        );
    }

    public static function homeButton(): InlineKeyboardButton
    {
        return InlineKeyboardButton::make('🏠 На головну', callback_data: self::MAIN_MENU);
    }
}
