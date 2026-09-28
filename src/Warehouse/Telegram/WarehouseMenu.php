<?php

namespace App\Warehouse\Telegram;

use App\Service\ChatScreen;
use App\Service\CrmLoginLink;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use App\Warehouse\Service\WarehouseSection;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «🏗 Склад» у головному меню — для тих, хто веде склад.
 *
 * Сам облік живе в адмінці (картки, рухи, документи, друк наклейок), тож
 * тут лише вхід туди й підказка, як сканувати. Працівникам цієї кнопки не
 * видно: їм склад відкривається сканом наклейки.
 */
class WarehouseMenu
{
    public function __construct(
        private ChatScreen $screen,
        private TelegramUserService $telegramUserService,
        private CrmLoginLink $loginLink,
        private WarehouseSection $section,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $user = $this->telegramUserService->getCurrentUser();
        $markup = InlineKeyboardMarkup::make();

        if (! $this->section->isEnabled() || ! WarehouseSection::canManage($user)) {
            $this->screen->render(
                $bot,
                "🏗 <b>Склад</b>\nРозділ ведуть менеджер, адміністратор і директор.",
                $markup->addRow(StartCommand::homeButton()),
            );

            return;
        }

        $this->screen->render(
            $bot,
            "🏗 <b>Склад</b>\n\n"
            . "Картки опалубки й техніки, клієнти, об'єкти, рухи й документи — в адмінці.\n\n"
            . '📷 Щоб дізнатись, що це за річ і де вона має бути, наведіть камеру телефона на QR-наклейку — бот покаже картку.',
            $markup
                ->addRow(InlineKeyboardButton::make('🔐 Відкрити склад в адмінці', url: $this->loginLink->issue($user, '/sklad?via=bot')))
                ->addRow(StartCommand::homeButton()),
        );
    }
}
