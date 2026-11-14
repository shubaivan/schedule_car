<?php

namespace App\Plan\EventStore;

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
}
