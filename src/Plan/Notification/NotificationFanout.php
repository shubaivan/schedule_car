<?php

namespace App\Plan\Notification;

use App\Plan\Event\PlanEvent;
use App\Plan\EventStore\PlanEventConsumer;
use App\Plan\Push\PushSender;
use App\Plan\Realtime\MercurePublisher;
use App\Plan\Subject\SubjectRegistry;
use App\Repository\TelegramUserRepository;
use App\Tasks\Service\TasksSection;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Throwable;

/**
 * Центр сповіщень: подія → рядки notification (AudienceResolver + налаштування
 * «У Плані») → живий сигнал у Mercure кожному (лише id і лічильник) → Web Push
 * тим, хто його ввімкнув і не в тихих годинах.
 *
 * Telegram тут НЕ шлеться — його й далі шлють слухачі журналу через нотифаєри
 * (страж NoDoubleNotificationTest); ті самі налаштування застосовує TelegramGate.
 * Пріоритет вищий за слухачів: рядок notification уже є, коли Telegram позначає
 * delivered_tg_at.
 *
 * Стенд заявок (розділ «Задачі» вимкнено) — нічого не робить: ні рядків, ні хаба.
 */
#[AsTaggedItem(priority: 100)]
final class NotificationFanout implements PlanEventConsumer
{
    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly NotificationPreferences $prefs,
        private readonly MercurePublisher $mercure,
        private readonly PushSender $push,
        private readonly SubjectRegistry $subjects,
        private readonly TelegramUserRepository $users,
        private readonly TasksSection $plan,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function consume(PlanEvent $event): void
    {
        if (! $this->plan->enabled() || $event->id() === null) {
            return;
        }

        $recipients = $this->audience->resolve($event);

        if ($recipients === []) {
            return;
        }

        $prefs = $this->prefs->ofMany(array_keys($recipients));
        $recipients = array_filter($recipients, static fn (Recipient $r) => $prefs[$r->userId]->allows(Channel::App, $r->group));
        $inserted = $this->insert((int) $event->id(), $event->at, $recipients);

        if ($inserted === []) {
            return;
        }

        $unread = $this->unread($inserted);

        foreach ($inserted as $userId) {
            $recipient = $recipients[$userId];
            $this->mercure->publish($userId, MercurePublisher::payload((int) $event->id(), $event::type(), $event->subject, $unread[$userId] ?? 0, $recipient->important()));
        }

        $this->push($event, array_map(static fn (int $id) => $recipients[$id], $inserted), $prefs);
    }

    /**
     * @param array<int, Recipient> $recipients
     *
     * @return list<int> кому рядок справді додано (повтор тієї самої події — нікому)
     */
    private function insert(int $eventId, DateTimeImmutable $at, array $recipients): array
    {
        if ($recipients === []) {
            return [];
        }

        $values = [];
        $params = [];

        foreach ($recipients as $recipient) {
            $values[] = "(nextval('notification_id_seq'), ?, ?, ?, ?)";
            array_push($params, $recipient->userId, $eventId, $recipient->group->value, $at->format('Y-m-d H:i:s'));
        }

        return array_map('intval', $this->db->fetchFirstColumn(
            'INSERT INTO notification (id, user_id, event_id, event_group, created_at) VALUES ' . implode(', ', $values)
            . ' ON CONFLICT (user_id, event_id) DO NOTHING RETURNING user_id',
            $params,
        ));
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, int>
     */
    private function unread(array $userIds): array
    {
        $out = [];

        foreach ($this->db->fetchAllAssociative('SELECT user_id, COUNT(*) AS n FROM notification WHERE read_at IS NULL AND user_id IN (?) GROUP BY user_id', [$userIds], [ArrayParameterType::INTEGER]) as $row) {
            $out[(int) $row['user_id']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Push — лише «своє» (не налаштування «Усе»), не в тихі години. Якщо План
     * відкритий і видимий, service worker сам не показує системне сповіщення (там уже тост).
     *
     * @param list<Recipient> $recipients
     * @param array<int, UserPrefs> $prefs
     */
    private function push(PlanEvent $event, array $recipients, array $prefs): void
    {
        if (! $this->push->enabled()) {
            return;
        }

        $now = new DateTimeImmutable();
        $targets = array_filter($recipients, static fn (Recipient $r) => $r->involved && $prefs[$r->userId]->allows(Channel::Push, $r->group) && ! $prefs[$r->userId]->quietAt($now));

        if ($targets === []) {
            return;
        }

        try {
            ['title' => $title, 'url' => $url] = NotificationLink::of($event, $this->subjects);
            $actor = $event->actorId !== null ? $this->users->find($event->actorId)?->shortName() : null;
        } catch (Throwable $e) {
            $this->logger->warning('plan: push — суб\'єкт не прочитано', ['event' => $event->id(), 'error' => $e->getMessage()]);

            return;
        }

        foreach ($targets as $recipient) {
            $text = NotificationText::of($event, $recipient->group, $actor);
            $sent = $this->push->send($recipient->userId, [
                'title' => $text['headline'] . ' · ' . $title,
                'body' => $text['detail'],
                'url' => $url,
                'tag' => $event->subject->key(),
                'important' => $recipient->important(),
            ]);

            if ($sent > 0) {
                $this->db->executeStatement('UPDATE notification SET delivered_push_at = LOCALTIMESTAMP(0) WHERE user_id = ? AND event_id = ?', [$recipient->userId, (int) $event->id()]);
            }
        }
    }
}
