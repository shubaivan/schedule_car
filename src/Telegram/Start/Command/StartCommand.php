<?php

namespace App\Telegram\Start\Command;

use App\Service\ChatScreen;
use App\Service\FleetSection;
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
     * ChatScreen і FleetSection приходять параметрами, а не через конструктор:
     * команди Nutgram створює через new під час реєстрації маршрутів, повз контейнер.
     */
    public function handle(Nutgram $bot, ChatScreen $screen, FleetSection $fleet): void
    {
        $screen->render($bot, 'Вітаю! Оберіть розділ:', self::mainMenuKeyboard($fleet->isEnabled()));
    }

    /** Вимкнений автопарк не просто нікуди не веде — його кнопки тут немає взагалі. */
    public static function mainMenuKeyboard(bool $withFleet = true): InlineKeyboardMarkup
    {
        $markup = InlineKeyboardMarkup::make();
        $row = [InlineKeyboardButton::make('📦 Постачання', callback_data: SupplyCallback::MENU)];

        if ($withFleet) {
            $row[] = InlineKeyboardButton::make('🚗 Автопарк', callback_data: self::FLEET_MENU);
        }

        return $markup->addRow(...$row);
    }

    public static function homeButton(): InlineKeyboardButton
    {
        return InlineKeyboardButton::make('🏠 На головну', callback_data: self::MAIN_MENU);
    }

    /**
     * Останній ряд будь-якого екрана: крок назад і вихід на головну.
     *
     * Тримаємо його однаковим і завжди на одному місці — людина не має шукати
     * вихід очима, а екранів без виходу не буває взагалі.
     *
     * @return InlineKeyboardButton[]
     */
    public static function navRow(string $backCallback, string $backLabel = '⬅️ Назад'): array
    {
        return [
            InlineKeyboardButton::make($backLabel, callback_data: $backCallback),
            self::homeButton(),
        ];
    }
}
