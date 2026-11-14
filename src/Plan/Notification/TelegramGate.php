<?php

namespace App\Plan\Notification;

use App\Entity\TelegramUser;
use App\Plan\Event\PlanEvent;
use App\Tasks\Service\TasksSection;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Останнє слово перед кожним Telegram-повідомленням нотифаєрів (TaskNotifier,
 * SupplyNotifier): чи хоче людина це в Telegram і чи зараз її тихі години.
 *
 * Тексти й адресати лишаються в нотифаєрах — тут лише фільтр налаштувань.
 * Стенд заявок (розділ «Задачі» вимкнено) — завжди «слати як раніше», без
 * жодного запиту до бази: поведінка стендів не змінюється.
 *
 * Слухачі журналу обгортають виклик нотифаєра в during($event) — тоді успішна
 * доставка позначається в notification.delivered_tg_at цієї людини й події.
 */
final class TelegramGate
{
    private ?int $eventId = null;

    public function __construct(
        private readonly NotificationPreferences $prefs,
        private readonly TasksSection $plan,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $send
     *
     * @return T
     */
    public function during(PlanEvent $event, callable $send): mixed
    {
        $previous = $this->eventId;
        $this->eventId = $event->id();

        try {
            return $send();
        } finally {
            $this->eventId = $previous;
        }
    }

    /**
     * null — не надсилати; false — звичайне повідомлення; true — тихе
     * (disable_notification: тихі години — повідомлення прийде, але без звуку).
     */
    public function decide(TelegramUser $user, EventGroup $group, ?DateTimeImmutable $now = null): ?bool
    {
        if (! $this->plan->enabled() || $user->getId() === null) {
            return false;
        }

        try {
            $prefs = $this->prefs->of((int) $user->getId());
        } catch (Throwable $e) {
            // Налаштування не прочитались — краще надіслати, ніж мовчки загубити.
            $this->logger->warning('plan: налаштування сповіщень не прочитано', ['user' => $user->getId(), 'error' => $e->getMessage()]);

            return false;
        }

        if (! $prefs->allows(Channel::Telegram, $group)) {
            return null;
        }

        return $prefs->quietAt($now ?? new DateTimeImmutable());
    }

    public function delivered(TelegramUser $user): void
    {
        if ($this->eventId === null || $user->getId() === null || ! $this->plan->enabled()) {
            return;
        }

        $this->db->executeStatement(
            'UPDATE notification SET delivered_tg_at = LOCALTIMESTAMP(0) WHERE user_id = ? AND event_id = ? AND delivered_tg_at IS NULL',
            [(int) $user->getId(), $this->eventId],
        );
    }
}
