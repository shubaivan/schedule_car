<?php

namespace App\Service;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/**
 * Один живий «екран» на чат: меню, список заявок, картка й форма живуть в
 * ОДНОМУ повідомленні, яке перемальовується.
 *
 * Раніше кожен хендлер робив sendMessage, і кнопки старих повідомлень лишались
 * робочими. 12.08 це дало дублі: людина натиснула «Мої заявки» на меню, потім
 * прогорнула чат угору й натиснула те саме меню ще раз — бот надіслав другий
 * список, з нього стартувала друга форма нової заявки, і в чаті висіли дві
 * однакові картки, з яких стан бота пам'ятав лише останню.
 *
 * Тепер натискання на застаріле повідомлення нікого не плодить: екран
 * перемальовується на місці, а в старого повідомлення знімається клавіатура.
 */
class ChatScreen
{
    private const KEY = 'screen_message_id';

    /**
     * Показати екран. Повертає id повідомлення, у якому він тепер живе.
     */
    public function render(Nutgram $bot, string $text, ?InlineKeyboardMarkup $markup = null): ?int
    {
        $chatId = $bot->chatId();

        if ($chatId === null) {
            return null;
        }

        $current = $this->currentId($bot);
        $clicked = $bot->isCallbackQuery() ? $bot->callbackQuery()?->message?->message_id : null;

        // Натиснули кнопку не поточного екрана — застарілого або картки-сповіщення.
        // Знімаємо з нього клавіатуру: текст лишається в історії, а кнопки більше
        // нікого не покличуть.
        if ($clicked !== null && $clicked !== $current) {
            $this->disarm($bot, $chatId, $clicked);
        }

        // Цільове повідомлення — саме поточний екран, навіть якщо прийшли не з
        // нього: форма мусить перемальовуватись і у відповідь на текст користувача.
        $target = $clicked !== null && $clicked === $current ? $clicked : $current;

        if ($target !== null) {
            try {
                $bot->editMessageText(
                    text: $text,
                    chat_id: $chatId,
                    message_id: $target,
                    parse_mode: ParseMode::HTML,
                    reply_markup: $markup,
                );

                $this->remember($bot, $target);

                return $target;
            } catch (Throwable $e) {
                // Той самий екран із тим самим текстом: Telegram вважає це помилкою,
                // а для нас це успіх — повторне натискання нічого не має міняти.
                if (str_contains($e->getMessage(), 'message is not modified')) {
                    $this->remember($bot, $target);

                    return $target;
                }

                // Повідомлення видалили або воно застаріле — надішлемо новий екран.
            }
        }

        $this->close($bot);

        $message = $bot->sendMessage(
            text: $text,
            chat_id: $chatId,
            parse_mode: ParseMode::HTML,
            reply_markup: $markup,
        );

        $this->remember($bot, $message?->message_id);

        return $message?->message_id;
    }

    /**
     * Прибрати екран разом із повідомленням: доречно, коли замість нього
     * користувач отримує окрему картку — наприклад, після подачі заявки.
     */
    public function close(Nutgram $bot): void
    {
        $chatId = $bot->chatId();
        $current = $this->currentId($bot);

        if ($chatId !== null && $current !== null) {
            try {
                $bot->deleteMessage($chatId, $current);
            } catch (Throwable) {
                // Не критично: повідомлення просто лишиться в чаті.
            }
        }

        $this->remember($bot, null);
    }

    public function currentId(Nutgram $bot): ?int
    {
        $id = $bot->getUserData(self::KEY);

        return is_int($id) ? $id : null;
    }

    private function remember(Nutgram $bot, ?int $messageId): void
    {
        if ($messageId === null) {
            $bot->deleteUserData(self::KEY);

            return;
        }

        $bot->setUserData(self::KEY, $messageId);
    }

    /** Зняти клавіатуру з повідомлення, щоб його кнопки перестали спрацьовувати. */
    private function disarm(Nutgram $bot, int|string $chatId, int $messageId): void
    {
        try {
            $bot->editMessageReplyMarkup(chat_id: $chatId, message_id: $messageId, reply_markup: null);
        } catch (Throwable) {
            // Повідомлення могли видалити — тоді натиснути на нього вже нікому.
        }
    }
}
