<?php

namespace App\Telegram\Access;

use App\Service\AccessService;
use App\Service\TelegramUserService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;

/** Користувач надіслав свій контакт — це і є реєстрація. */
class ShareContact
{
    public function __construct(
        private TelegramUserService $telegramUserService,
        private AccessService $accessService,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $contact = $bot->message()?->contact;

        if ($user === null || $contact === null) {
            return;
        }

        // Чужий контакт не приймаємо: реєструємо лише власника акаунта.
        if ((string) $contact->user_id !== (string) $user->getTelegramId()) {
            $bot->sendMessage(text: '⚠️ Надішліть, будь ласка, власний номер кнопкою нижче.');

            return;
        }

        $bot->sendMessage(
            text: 'Дякуємо, номер отримано.',
            reply_markup: ReplyKeyboardRemove::make(true),
        );

        $this->accessService->registerPhone($user, (string) $contact->phone_number);
    }

    /** Клавіатура-прохання поділитись номером. */
    public static function keyboard(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
            ->addRow(KeyboardButton::make('📱 Поділитись номером', request_contact: true));
    }
}
