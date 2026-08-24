<?php

namespace App\Service;

use App\Entity\Car;
use App\Entity\TelegramUser;
use App\Enum\AccessStatus;
use App\Repository\TelegramUserRepository;
use App\Telegram\Access\AccessCallback;
use App\Telegram\Start\Command\StartCommand;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** Сповіщення про реєстрацію та рішення по доступу. */
class AccessNotifier
{
    public function __construct(
        private Nutgram $bot,
        private TelegramUserRepository $userRepository,
        private LoggerInterface $logger,
        private FleetSection $fleet,
    ) {
    }

    /** Водієві після реєстрації: він і сам має знати, що на ньому машина. */
    public function driverAssigned(TelegramUser $user, ?Car $car): void
    {
        $text = $car !== null
            ? sprintf(
                "🚗 <b>Ви водій</b>\n\nЗа вами закріплено: <b>%s</b>.\nЯк на цю машину з'явиться бронювання — бот напише вам сам.",
                $this->escape($car->label()),
            )
            : "🚗 <b>Ви водій</b>\n\nМашину за вами ще не закріпили — щойно закріплять, бот напише.";

        $this->send($user, $text);
    }

    public function registrationRequested(TelegramUser $user): void
    {
        $this->send($user, '⏳ Дякуємо! Заявку на доступ надіслано менеджеру. Щойно її підтвердять — бот напише вам.');

        $managers = array_filter(
            $this->userRepository->findSupplyManagers(),
            static fn (TelegramUser $manager) => $manager->isApproved(),
        );

        if (! $managers) {
            $this->logger->warning('access: немає жодного менеджера для підтвердження реєстрації', [
                'user' => $user->getId(),
            ]);

            return;
        }

        $text = sprintf(
            "🔔 <b>Нова реєстрація в боті</b>\n\n👤 %s\n📞 %s",
            $this->escape($user->displayName()),
            $this->escape($user->getPhoneNumber() ?? '—'),
        );

        $markup = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('✅ Підтвердити', callback_data: AccessCallback::approve((int) $user->getId())),
            InlineKeyboardButton::make('⛔ Відхилити', callback_data: AccessCallback::reject((int) $user->getId())),
        );

        foreach ($managers as $manager) {
            $this->send($manager, $text, $markup);
        }
    }

    public function approved(TelegramUser $user): void
    {
        $this->send(
            $user,
            "✅ <b>Доступ відкрито</b>\nОберіть розділ:",
            StartCommand::mainMenuKeyboard($this->fleet->isEnabled()),
        );
    }

    public function rejected(TelegramUser $user): void
    {
        $this->send($user, '⛔ На жаль, доступ до бота не надано. Зверніться до керівництва підприємства.');
    }

    /** Підсумок для менеджера, який натиснув кнопку. */
    public function decisionSummary(TelegramUser $user, AccessStatus $status): string
    {
        return sprintf(
            '%s %s — %s',
            $status === AccessStatus::Approved ? '✅' : '⛔',
            $this->escape($user->displayName()),
            $status->label(),
        );
    }

    private function send(TelegramUser $user, string $text, ?InlineKeyboardMarkup $markup = null): void
    {
        $chatId = $user->getChatId() ?: $user->getTelegramId();

        if (! $chatId) {
            $this->logger->warning('access: у користувача немає chat_id', ['user' => $user->getId()]);

            return;
        }

        try {
            $this->bot->sendMessage(
                text: $text,
                chat_id: $chatId,
                parse_mode: ParseMode::HTML,
                reply_markup: $markup,
            );
        } catch (Throwable $e) {
            $this->logger->warning('access: не вдалось надіслати сповіщення', [
                'user' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
