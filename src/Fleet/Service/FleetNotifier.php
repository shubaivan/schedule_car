<?php

namespace App\Fleet\Service;

use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Service\TeamChat;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Throwable;

/**
 * Сповіщення автопарку. Водій має дізнатись про рейс, не гортаючи розклад.
 * Кожне бронювання й скасування — ще й у тему «Автопарк» робочої групи.
 */
class FleetNotifier
{
    public function __construct(
        private Nutgram $bot,
        private TripFormatter $formatter,
        private LoggerInterface $logger,
        private TeamChat $team,
    ) {
    }

    public function booked(ScheduledSet $set): void
    {
        $this->toDrivers($set, sprintf(
            "🚚 <b>Новий рейс</b>\n\n%s",
            $this->formatter->driverLine($set),
        ));

        $this->toTeam('🚚 <b>Нова заявка на авто</b>', $set);
    }

    public function cancelled(ScheduledSet $set): void
    {
        $this->toDrivers($set, sprintf(
            "✖️ <b>Бронювання скасовано</b>\n\n%s",
            $this->formatter->driverLine($set),
        ));

        $this->toTeam('✖️ <b>Бронювання авто скасовано</b>', $set);
    }

    /** У групі машина не очевидна, як водієві, — тож день і номер машини в рядку. */
    private function toTeam(string $title, ScheduledSet $set): void
    {
        $this->team->post(TeamChat::FLEET, sprintf(
            "%s\n\n📅 %s\n%s",
            $title,
            $this->formatter->day($set->getScheduledDateTime()),
            $this->formatter->line($set),
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
