<?php

namespace App\Plan\Telegram;

use SergiX44\Nutgram\Nutgram;

/**
 * Порожній обробник для /mute, /help і будь-чого іншого в боті Плану: Nutgram
 * запускає глобальні middleware лише довкола знайденого обробника, тож без
 * нього PlanBot просто не дізнався б про повідомлення. Відповідає сам PlanBot.
 */
final class PlanBotFallback
{
    public function __invoke(Nutgram $bot): void
    {
    }
}
