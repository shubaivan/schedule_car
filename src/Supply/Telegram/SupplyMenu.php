<?php

namespace App\Supply\Telegram;

use App\Service\ChatScreen;
use App\Service\TelegramUserService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** Розділ «Постачання» головного меню. */
class SupplyMenu
{
    public function __construct(
        private TelegramUserService $telegramUserService,
        private ChatScreen $screen,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $isManager = $this->telegramUserService->getCurrentUser()?->getSupplyRole()->canManage() ?? false;

        $this->screen->render(
            $bot,
            "📦 <b>Постачання</b>\nПодайте заявку на матеріали — арматуру, цемент, пісок тощо.",
            self::keyboard($isManager),
        );

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }
    }

    public static function keyboard(bool $isManager = false): InlineKeyboardMarkup
    {
        $markup = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('➕ Нова заявка', callback_data: SupplyCallback::NEW_REQUEST))
            ->addRow(InlineKeyboardButton::make('📋 Мої заявки', callback_data: SupplyCallback::MY_REQUESTS));

        if ($isManager) {
            $markup->addRow(
                InlineKeyboardButton::make('🔐 Вхід у CRM', callback_data: SupplyCallback::CRM_LOGIN),
            );
        }

        return $markup;
    }
}
