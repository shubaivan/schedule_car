<?php

namespace App\Plan\EventStore;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/** Роздає записану подію всім PlanEventConsumer. */
#[AsMessageHandler]
final class FanOutHandler
{
    /** @param iterable<PlanEventConsumer> $consumers */
    public function __construct(
        private readonly PlanEventStore $store,
        #[AutowireIterator('plan.event_consumer')]
        private readonly iterable $consumers,
        private readonly LoggerInterface $logger,
        private readonly Connection $db,
    ) {
    }

    public function __invoke(FanOut $message): void
    {
        // Помилка читання (база недоступна) — нехай Messenger повторить: споживачі
        // ще нічого не зробили, тож повтор безпечний.
        $event = $this->store->load($message->eventId);

        if ($event === null) {
            $this->logger->warning('plan: подію не знайдено', ['event' => $message->eventId]);

            return;
        }

        foreach ($this->consumers as $consumer) {
            // Повторна доставка того самого FanOut (воркер упав до ack) — споживач, який
            // подію вже взяв, її вдруге не отримає: краще одне втрачене, ніж подвоєне повідомлення.
            if (! $this->claim($message->eventId, $consumer::class)) {
                $this->logger->info('plan: подію цей споживач уже обробив', ['event' => $message->eventId, 'consumer' => $consumer::class]);
                continue;
            }

            try {
                $consumer->consume($event);
            } catch (Throwable $e) {
                // Без повтору: частина споживачів уже відпрацювала (Telegram пішов),
                // повтор усього FanOut задублював би їх.
                $this->logger->error('plan: споживач події впав', [
                    'event' => $message->eventId,
                    'type' => $event::type(),
                    'consumer' => $consumer::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** Ім'я класу споживача як ключ (анонімні класи містять \0 і шлях — їх хешуємо). */
    private static function key(string $consumer): string
    {
        if (str_contains($consumer, "\0") || strlen($consumer) > 120) {
            return substr(strstr($consumer, "\0", true) ?: $consumer, 0, 87) . '#' . md5($consumer);
        }

        return $consumer;
    }

    private function claim(int $eventId, string $consumer): bool
    {
        return $this->db->executeStatement(
            'INSERT INTO plan_event_consumed (event_id, consumer, consumed_at) VALUES (?, ?, LOCALTIMESTAMP(0)) ON CONFLICT DO NOTHING',
            [$eventId, self::key($consumer)],
        ) === 1;
    }
}
