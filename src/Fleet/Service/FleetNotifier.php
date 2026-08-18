<?php

namespace App\Fleet\Service;

use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Throwable;

/**
 * Сповіщення автопарку. Водій має дізнатись про рейс, не гортаючи розклад.
 */
class FleetNotifier
{
    public function __construct(
        private Nutgram $bot,
        private TripFormatter $formatter,
        private LoggerInterface $logger,
    ) {
    }

    public function booked(ScheduledSet $set): void
    {
        $this->toDrivers($set, sprintf(
            "🚚 <b>Новий рейс</b>\n\n%s",
            $this->formatter->driverLine($set),
        ));
    }

    public function cancelled(ScheduledSet $set): void
    {
        $this->toDrivers($set, sprintf(
            "✖️ <b>Бронювання скасовано</b>\n\n%s",
            $this->formatter->driverLine($set),
        ));
    }

    private function toDrivers(ScheduledSet $set, string $text): void
    {
        foreach ($set->getCar()->getCarDriver() as $carDriver) {
            $driver = $carDriver->getDriver();

            // Сам себе не сповіщаємо: водій міг забронювати машину й для себе.
            if ($driver->getId() === $set->getTelegramUserId()->getId()) {
                continue;
            }

            $this->send($driver, $text);
        }
    }

    private function send(TelegramUser $user, string $text): void
    {
        $chatId = $user->getChatId();

        if ($chatId === null) {
            return;
        }

        try {
            $this->bot->sendMessage(text: $text, chat_id: $chatId, parse_mode: ParseMode::HTML);
        } catch (Throwable $e) {
            // Людина могла заблокувати бота — це не привід ламати бронювання.
            $this->logger->warning('fleet: не вдалось надіслати сповіщення', [
                'user' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
