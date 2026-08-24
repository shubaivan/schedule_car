<?php

namespace App\Tests\Fleet;

use App\Fleet\Telegram\FleetEnabled;
use App\Service\ChatScreen;
use App\Service\FleetSection;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;

/**
 * Вимикач розділу «Автопарк» (вимога клієнта від 21.08.2026).
 *
 * Кнопки автопарку лишаються в старих повідомленнях чату назавжди, тож
 * вимкнений розділ має не мовчати, а пояснювати — мовчазна кнопка виглядає
 * як зламаний бот.
 */
class FleetSwitchTest extends TestCase
{
    private const CHAT_ID = 471925876;

    public function testSectionWorksWhenEnabled(): void
    {
        $reached = false;

        $this->press($this->bot(true, $reached))
            ->assertCalled('sendMessage');

        self::assertTrue($reached, 'Увімкнений автопарк не пустив до свого екрана.');
    }

    public function testDisabledSectionExplainsInsteadOfSilence(): void
    {
        $reached = false;

        $this->press($this->bot(false, $reached))
            // Екран-пояснення все одно надсилається — кнопка не мертва.
            ->assertCalled('sendMessage');

        self::assertFalse($reached, 'Вимкнений автопарк усе одно відкрив свій екран.');
    }

    private function press(FakeNutgram $bot): FakeNutgram
    {
        return $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'data' => 'fleet:menu',
            'message' => [
                'message_id' => 100,
                'date' => 1786533286,
                'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                'from' => [],
            ],
        ])->reply();
    }

    private function bot(bool $enabled, bool &$reached): FakeNutgram
    {
        $bot = Nutgram::fake();
        $bot->setCommonUser(User::make(id: self::CHAT_ID, is_bot: false, first_name: 'Ivan'));
        $bot->setCommonChat(Chat::make(id: self::CHAT_ID, type: 'private'));

        $guard = new FleetEnabled(new FleetSection($enabled), new ChatScreen());

        $bot->group(static function (Nutgram $bot) use (&$reached): void {
            $bot->onCallbackQueryData('fleet:menu', static function (Nutgram $bot) use (&$reached): void {
                $reached = true;
                $bot->sendMessage('Розклад машин');
            });
        })->middleware($guard);

        return $bot;
    }
}
