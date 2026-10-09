<?php

namespace App\Telegram\Access;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;

/**
 * Бот відповідає лише в особистих чатах.
 *
 * У робочій групі з темами він тільки публікує події (TeamChat). Без цього
 * фільтра RequireApproval відповів би в групі «поділіться номером» першому
 * ж, кого бот ще не знає, а меню розсипалось би по темах.
 */
class PrivateChatsOnly
{
    public function __invoke(Nutgram $bot, callable $next): void
    {
        $chat = $bot->chat();

        if ($chat !== null && $chat->type !== ChatType::PRIVATE) {
            return;
        }

        $next($bot);
    }
}
