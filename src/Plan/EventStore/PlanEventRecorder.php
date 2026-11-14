<?php

namespace App\Plan\EventStore;

use App\Plan\Event\PlanEvent;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Єдиний спосіб записати доменну подію «Плану».
 *
 * Як користуватись (сервіс модуля):
 *
 *     $this->events->transactional(function () use (...) {
 *         $this->em->flush();                              // сама зміна
 *         $this->events->record(new CommentAdded(...));     // рядок plan_event
 *     });
 *
 * Гарантії:
 * - рядок plan_event пишеться тим самим з'єднанням у тій самій транзакції, що
 *   й зміна: відкат — немає ні зміни, ні події;
 * - async (`PLAN_EVENTS_TRANSPORT=doctrine://default?queue_name=plan`): повідомлення
 *   FanOut лягає в messenger_messages теж у цій транзакції (outbox без брокера) —
 *   втратити подію неможливо, воркер побачить її лише після коміту;
 * - sync (`sync://`, дефолт стендів заявок): FanOut роздається споживачам одразу
 *   ПІСЛЯ того, як transactional() успішно завершився, — тобто там само, де
 *   раніше стояв прямий виклик нотифаєра; відкат — споживачі нічого не отримають.
 *
 * record() поза transactional() теж працює (сирий INSERT у поточній транзакції
 * або автокоміт), але в sync-режимі тоді роздає подію відразу — використовуй
 * лише там, де споживачі не мають зовнішніх ефектів до коміту.
 */
final class PlanEventRecorder
{
    private readonly bool $sync;
    private int $depth = 0;

    /** @var list<array{int, int}> [eventId, depth] — sync-режим: чекають завершення transactional() */
    private array $pending = [];

    public function __construct(
        private readonly Connection $db,
        private readonly MessageBusInterface $bus,
        private readonly RequestStack $requests,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(PLAN_EVENTS_TRANSPORT)%')]
        string $transport = 'sync://',
    ) {
        $this->sync = str_starts_with($transport, 'sync://');
    }

    public function isSync(): bool
    {
        return $this->sync;
    }

    /** Записати подію; повертає id рядка plan_event. */
    public function record(PlanEvent $event): int
    {
        [$channel, $ip] = $event->channel() !== null ? [$event->channel(), $event->ip()] : $this->origin();
        $id = $this->insert($event, $channel ?? 'cli', $ip);

        if (! $this->sync) {
            // Той самий DBAL-з'єднання → та сама транзакція: атомарно з рядком події.
            $this->bus->dispatch(new FanOut($id));
        } elseif ($this->depth > 0) {
            $this->pending[] = [$id, $this->depth];
        } else {
            $this->fanOutNow($id);
        }

        return $id;
    }

    /**
     * Виконати зміну й запис подій однією транзакцією (вкладені — через savepoint).
     * EntityManager при помилці НЕ закривається (на відміну від wrapInTransaction).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        $this->db->beginTransaction();
        $level = ++$this->depth;

        try {
            $result = $work();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->depth = $level - 1;
            // Події цього рівня (і глибших) відкотились разом із транзакцією.
            $this->pending = array_values(array_filter($this->pending, static fn (array $p) => $p[1] < $level));

            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        $this->depth = $level - 1;

        if ($this->depth === 0) {
            $ready = $this->pending;
            $this->pending = [];

            foreach ($ready as [$id]) {
                $this->fanOutNow($id);
            }
        } else {
            // Вкладений рівень закомічено в батьківський — події чекають на нього.
            foreach ($this->pending as $i => $pending) {
                if ($pending[1] > $this->depth) {
                    $this->pending[$i][1] = $this->depth;
                }
            }
        }

        return $result;
    }

    /**
     * Лише рядок журналу, БЕЗ розсилки споживачам — для перенесення історії
     * (plan:events:backfill): старі події не мають знову слати Telegram.
     *
     * @param array<string, scalar|null> $extra додаткові ключі payload (напр. backfill: true)
     */
    public function import(PlanEvent $event, array $extra = []): int
    {
        return $this->insert($event, $event->channel() ?? 'cli', $event->ip(), $extra);
    }

    /** @param array<string, scalar|null> $extra */
    private function insert(PlanEvent $event, string $channel, ?string $ip, array $extra = []): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO plan_event (id, type, subject_type, subject_id, actor_id, occurred_at, payload, ip, channel)
             VALUES (nextval('plan_event_id_seq'), ?, ?, ?, ?, ?, CAST(? AS jsonb), ?, ?) RETURNING id",
            [
                $event::type(),
                $event->subject->type->value,
                $event->subject->id,
                $event->actorId,
                $event->at->format('Y-m-d H:i:s'),
                json_encode([...$event->payload(), ...$extra], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $ip !== null ? mb_substr($ip, 0, 45) : null,
                mb_substr($channel, 0, 8),
            ],
        );
    }

    /** sync: зміна вже збережена — помилка розсилки не має зламати відповідь. */
    private function fanOutNow(int $id): void
    {
        try {
            $this->bus->dispatch(new FanOut($id));
        } catch (Throwable $e) {
            $this->logger->error('plan: розсилка події не вдалась', ['event' => $id, 'error' => $e->getMessage()]);
        }
    }

    /** @return array{0: string, 1: ?string} канал і IP за поточним запитом */
    private function origin(): array
    {
        $request = $this->requests->getMainRequest();

        if ($request === null) {
            return ['cli', null];
        }

        $path = $request->getPathInfo();

        return match (true) {
            $path === '/hook' || str_starts_with($path, '/hook/') => ['bot', null],
            str_starts_with($path, '/api/plan/leads') => ['api', $request->getClientIp()],
            default => ['web', $request->getClientIp()],
        };
    }
}
