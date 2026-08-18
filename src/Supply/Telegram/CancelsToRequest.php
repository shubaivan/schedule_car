<?php

namespace App\Supply\Telegram;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Вихід із розмови навколо заявки — коментаря, відхилення, накладної.
 *
 * Без нього людина, яка передумала, лишалась замкненою у формі: картку вже
 * перемальовано питанням, кнопок немає, і врятувати могла тільки команда
 * /start. Скасування повертає ту саму картку, з якої розмова почалась.
 */
trait CancelsToRequest
{
    private function cancelKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('✖️ Скасувати', callback_data: SupplyCallback::CANCEL),
        );
    }

    /** true — людина скасувала, крок далі виконувати не треба. */
    private function cancelled(Nutgram $bot): bool
    {
        if (! $bot->isCallbackQuery() || ($bot->callbackQuery()->data ?? '') !== SupplyCallback::CANCEL) {
            return false;
        }

        $bot->answerCallbackQuery();

        $request = $this->requestId !== null ? $this->repository->find($this->requestId) : null;
        $user = $this->telegramUserService->getCurrentUser();

        if ($request !== null && $user !== null) {
            $this->view->show($bot, $request, $user);
        } else {
            $this->screen->close($bot);
        }

        $this->end();

        return true;
    }
}
