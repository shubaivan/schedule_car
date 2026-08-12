<?php

namespace App\Tests\Telegram;

use App\Service\ChatScreen;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;

/**
 * Один живий екран на чат.
 *
 * Тест відтворює ситуацію 12.08, коли в чаті з'явилися дві однакові форми:
 * людина натиснула кнопку меню, а потім прогорнула чат угору й натиснула те
 * саме меню ще раз. Кожне натискання давало нове повідомлення.
 */
class ChatScreenTest extends TestCase
{
    private const CHAT_ID = 471925876;
    private const MENU_MESSAGE = 100;
    private const SCREEN_MESSAGE = 200;

    public function testFirstRenderSendsMessage(): void
    {
        $bot = $this->bot();

        $this->click($bot, self::MENU_MESSAGE)
            ->assertCalled('sendMessage')
            ->assertCalled('editMessageText', 0);
    }

    public function testButtonOfTheScreenRedrawsItInPlace(): void
    {
        $bot = $this->bot();

        $this->click($bot, self::MENU_MESSAGE);

        $this->click($bot, self::SCREEN_MESSAGE)
            ->assertCalled('editMessageText')
            ->assertCalled('sendMessage', 0);
    }

    public function testStaleButtonDoesNotCreateSecondScreen(): void
    {
        $bot = $this->bot();

        // Меню перетворилось на екран №200.
        $this->click($bot, self::MENU_MESSAGE);
        $this->click($bot, self::SCREEN_MESSAGE);

        // А тепер натискаємо кнопку старого повідомлення №100 — саме так
        // 12.08 з'явилась друга форма.
        $this->click($bot, self::MENU_MESSAGE)
            ->assertCalled('sendMessage', 0)
            ->assertCalled('editMessageText')
            // У застарілого повідомлення знімається клавіатура, щоб воно
            // більше нікого не покликало.
            ->assertCalled('editMessageReplyMarkup');
    }

    /** Натискання кнопки в повідомленні $messageId. */
    private function click(FakeNutgram $bot, int $messageId): FakeNutgram
    {
        // Кожне нове повідомлення бота отримує id екрана: так ми перевіряємо,
        // що другого sendMessage не сталося.
        $bot->willReceivePartial(['message_id' => self::SCREEN_MESSAGE]);

        return $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'data' => 'screen',
            'message' => [
                'message_id' => $messageId,
                'date' => 1786533286,
                'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
                'from' => [],
            ],
        ])->reply();
    }

    private function bot(): FakeNutgram
    {
        $bot = Nutgram::fake();
        $bot->setCommonUser(User::make(id: self::CHAT_ID, is_bot: false, first_name: 'Ivan'));
        $bot->setCommonChat(Chat::make(id: self::CHAT_ID, type: 'private'));

        $screen = new ChatScreen();

        $bot->onCallbackQueryData('screen', fn (Nutgram $bot) => $screen->render(
            $bot,
            'Екран',
            InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('Кнопка', callback_data: 'screen'),
            ),
        ));

        return $bot;
    }
}
