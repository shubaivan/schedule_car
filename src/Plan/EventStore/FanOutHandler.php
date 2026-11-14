<?php

namespace App\Plan\EventStore;

use App\Plan\Event\PlanEvent;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Роздає записану подію всім PlanEventConsumer — за двома контрактами:
 *
 * - IdempotentConsumer (пошук, Хроніка, центр сповіщень): позначка в
 *   plan_event_consumed ставиться ПІСЛЯ успіху; збій хоч одного такого споживача —
 *   виняток наприкінці, і Messenger повторює FanOut (retry_strategy транспорту plan).
 *   У повторі ті, хто вже відпрацював, пропускаються за позначкою.
 * - решта (Telegram, черга Диска): позначка ДО виклику, без повторів — «краще одне
 *   втрачене, ніж подвоєне повідомлення». Їх повтор FanOut не зачіпає.
 *
 * Що не догналось повторами (вичерпано, або sync-режим стендів, де повторів немає),
 * вирівнює plan:projections:reconcile (крон, deploy/plan.cron).
 */
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

        $failed = [];

        foreach ($this->consumers as $consumer) {
            if ($consumer instanceof IdempotentConsumer) {
                if (! $this->deliver($event, $consumer)) {
                    $failed[] = $consumer::class;
                }

                continue;
            }

            // Повторна доставка того самого FanOut — споживач із зовнішнім ефектом, який
            // подію вже взяв, її вдруге не отримає: краще одне втрачене, ніж подвоєне повідомлення.
            if (! $this->claim($message->eventId, $consumer::class)) {
                $this->logger->info('plan: подію цей споживач уже обробив', ['event' => $message->eventId, 'consumer' => $consumer::class]);
                continue;
            }

            try {
                $consumer->consume($event);
            } catch (Throwable $e) {
                $this->failure($event, $consumer, $e, false);
            }
        }

        if ($failed !== []) {
            throw new ProjectionFailed($message->eventId, $failed);
        }
    }

    /**
     * Ідемпотентний споживач: пропустити, якщо вже відпрацював; інакше виконати й
     * лише тоді позначити. false — упав (подія піде на повтор).
     */
    public function deliver(PlanEvent $event, IdempotentConsumer $consumer): bool
    {
        $id = (int) $event->id();

        if ($this->consumed($id, $consumer::class)) {
            return true;
        }

        try {
            $consumer->consume($event);
        } catch (Throwable $e) {
            $this->failure($event, $consumer, $e, true);

            return false;
        }

        $this->claim($id, $consumer::class);

        return true;
    }

    /** @return list<IdempotentConsumer> */
    public function idempotentConsumers(): array
    {
        $out = [];

        foreach ($this->consumers as $consumer) {
            if ($consumer instanceof IdempotentConsumer) {
                $out[] = $consumer;
            }
        }

        return $out;
    }

    public static function consumerKey(string $consumer): string
    {
        return self::key($consumer);
    }

    private function failure(PlanEvent $event, PlanEventConsumer $consumer, Throwable $e, bool $retry): void
    {
        $this->logger->error($retry ? 'plan: проєкція події впала — буде повтор' : 'plan: споживач події впав', [
            'event' => $event->id(),
            'type' => $event::type(),
            'consumer' => $consumer::class,
            'error' => $e->getMessage(),
        ]);
    }

    private function consumed(int $eventId, string $consumer): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM plan_event_consumed WHERE event_id = ? AND consumer = ?', [$eventId, self::key($consumer)]) !== false;
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
