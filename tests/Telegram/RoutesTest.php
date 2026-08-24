<?php

namespace App\Tests\Telegram;

use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Fake;

/**
 * Сторож маршрутів бота.
 *
 * 24.08 бот замовк на кожному апдейті: у замиканні групи автопарку стояв тип
 * `Nutgram` без імпорту, і він резолвився в глобальний клас. Помилка живе не в
 * реєстрації маршрутів, а в моменті, коли Nutgram розкриває групи під час
 * розбору апдейта, — тож жоден із наявних тестів її не бачив.
 */
class RoutesTest extends TestCase
{
    public function testRealRouteFileSurvivesAnUpdate(): void
    {
        $bot = Nutgram::fake();

        (static function (Nutgram $bot): void {
            require dirname(__DIR__, 2) . '/config/telegram.php';
        })($bot);

        // Файл маршрутів вмикає webhook — тестовому боту потрібен свій режим.
        $bot->setRunningMode(new Fake());

        // Команда, під яку немає хендлера: групи все одно розкриваються, а
        // жоден сервіс застосунку не створюється.
        $bot->hearText('/nemaje-takoji-komandy')
            ->reply()
            ->assertNoReply();
    }
}
