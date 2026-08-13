<?php

namespace App\Supply\Telegram;

use App\Service\ChatScreen;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** Розділ «Постачання» головного меню. */
class SupplyMenu
{
    public function __construct(
        private ChatScreen $screen,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $this->screen->render(
            $bot,
            "📦 <b>Постачання</b>\nПодайте заявку на матеріали — арматуру, цемент, пісок тощо."
            . "\nУ «Всіх заявках» видно, що вже замовили інші підрозділи.",
            self::keyboard(),
        );

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }
    }

    /**
     * Набір однаковий для всіх: спільну картину заявок і перегляд у CRM
     * має кожен, а що саме там можна робити — вирішує роль.
     */
    public static function keyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('➕ Нова заявка', callback_data: SupplyCallback::NEW_REQUEST))
            ->addRow(
                InlineKeyboardButton::make('📋 Мої заявки', callback_data: SupplyCallback::MY_REQUESTS),
                InlineKeyboardButton::make('📋 Усі заявки', callback_data: SupplyCallback::ALL_REQUESTS),
            )
            ->addRow(InlineKeyboardButton::make('🔐 Вхід у CRM', callback_data: SupplyCallback::CRM_LOGIN));
    }
}
