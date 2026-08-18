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
 *
 * І ситуацію 18.08, зворотну: екран правився на місці навіть тоді, коли вже
 * поїхав угору над повідомленнями людини, — і бот виглядав мертвим.
 */
class ChatScreenTest extends TestCase
{
    private const CHAT_ID = 471925876;
    private const MENU_MESSAGE = 100;

    /** id повідомлення, у якому живе екран після останнього рендера. */
    private ?int $screenId = null;

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

        // Натискаємо кнопку самого екрана — його й перемальовуємо на місці.
        $this->click($bot, $this->screenId)
            ->assertCalled('editMessageText')
            ->assertCalled('sendMessage', 0);
    }

    public function testStaleButtonDoesNotCreateSecondScreen(): void
    {
        $bot = $this->bot();

        // Меню перетворилось на екран.
        $this->click($bot, self::MENU_MESSAGE);
        $this->click($bot, $this->screenId);

        // А тепер натискаємо кнопку старого повідомлення №100 — саме так
        // 12.08 з'явилась друга форма. Екран приїжджає вниз чату новим
        // повідомленням, але живим лишається рівно один:
        $this->click($bot, self::MENU_MESSAGE)
            ->assertCalled('sendMessage')
            // у застарілого повідомлення знімається клавіатура,
            ->assertCalled('editMessageReplyMarkup')
            // а попередній екран прибирається.
            ->assertCalled('deleteMessage')
            ->assertCalled('editMessageText', 0);
    }

    /**
     * 18.08: бот «мовчав» на /start. Команда приходить текстом, екран до того
     * моменту вже над повідомленням людини — правку на місці ніхто не бачить.
     * Тому на текст екран переїжджає вниз чату.
     */
    public function testTextCommandMovesScreenToTheBottom(): void
    {
        $bot = $this->bot();

        $this->click($bot, self::MENU_MESSAGE);

        $bot->hearText('/screen')
            ->reply()
            ->assertCalled('sendMessage')
            ->assertCalled('deleteMessage')
            ->assertCalled('editMessageText', 0);
    }

    /** Натискання кнопки в повідомленні $messageId. */
    private function click(FakeNutgram $bot, ?int $messageId): FakeNutgram
    {
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

        $render = function (Nutgram $bot) use ($screen): void {
            $this->screenId = $screen->render(
                $bot,
                'Екран',
                InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make('Кнопка', callback_data: 'screen'),
                ),
            );
        };

        $bot->onCallbackQueryData('screen', $render);
        $bot->onCommand('screen', $render);

        return $bot;
    }
}
