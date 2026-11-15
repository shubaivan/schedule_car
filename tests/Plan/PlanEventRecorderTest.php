<?php

namespace App\Tests\Plan;

use App\Plan\Chronicle\ChronicleProjection;
use App\Plan\Event\CommentAdded;
use App\Plan\Event\PlanEvent;
use App\Plan\EventStore\FanOut;
use App\Plan\EventStore\FanOutHandler;
use App\Plan\EventStore\IdempotentConsumer;
use App\Plan\EventStore\PlanEventConsumer;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\EventStore\PlanEventStore;
use App\Plan\EventStore\ProjectionFailed;
use App\Plan\Subject\SubjectRef;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineQueue;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

/**
 * Гарантії журналу: подія й зміна — одна транзакція; sync роздає подію лише
 * після успішного коміту, async кладе FanOut у ту саму транзакцію (outbox).
 */
class PlanEventRecorderTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        // Тест сам по собі — у транзакції, яку tearDown відкочує (як у решті тестів).
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        parent::tearDown();
    }

    public function testDefaultTransportIsSyncAsOnZayavkyStands(): void
    {
        $recorder = self::getContainer()->get(PlanEventRecorder::class);
        self::assertTrue($recorder->isSync(), 'PLAN_EVENTS_TRANSPORT за замовчуванням — sync://');
        self::assertInstanceOf(SyncTransport::class, self::getContainer()->get('messenger.transport.plan'));
    }

    public function testRollbackLeavesNeitherChangeNorEventAndNothingIsSent(): void
    {
        $bus = new SpyBus();
        $recorder = $this->recorder($bus, 'sync://');
        $before = $this->rows();
        $department = 'Відділ-відкат-' . uniqid();

        $error = null;

        try {
            $recorder->transactional(function () use ($recorder, $department): void {
                $this->db->executeStatement("INSERT INTO supply_department (id, name, created_at, updated_at) VALUES (nextval('supply_department_id_seq'), ?, NOW(), NOW())", [$department]);
                $recorder->record($this->event());
                throw new RuntimeException('збій посеред дії');
            });
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        self::assertSame('збій посеред дії', $error, 'Виняток пройшов назовні');

        self::assertSame($before, $this->rows(), 'Рядка події немає');
        self::assertFalse($this->db->fetchOne('SELECT 1 FROM supply_department WHERE name = ?', [$department]), 'Зміни немає');
        self::assertSame([], $bus->messages, 'Споживачі нічого не отримали');
        self::assertSame(1, $this->db->getTransactionNestingLevel(), 'Зовнішня транзакція тесту ціла');
    }

    public function testSyncFansOutOnlyAfterTheOuterTransactionalCommits(): void
    {
        $levels = [];
        $bus = new SpyBus(function () use (&$levels): void {
            $levels[] = $this->db->getTransactionNestingLevel();
        });
        $recorder = $this->recorder($bus, 'sync://');

        $recorder->transactional(function () use ($recorder, $bus): void {
            $recorder->record($this->event());
            $recorder->transactional(fn () => $recorder->record($this->event()));
            self::assertSame([], $bus->messages, 'Усередині транзакції — ще нічого');
        });

        self::assertCount(2, $bus->messages);
        self::assertSame([1, 1], $levels, 'Роздано вже ПІСЛЯ коміту (лишилась тільки транзакція тесту)');
    }

    public function testInnerRollbackDropsOnlyItsOwnEvents(): void
    {
        $bus = new SpyBus();
        $recorder = $this->recorder($bus, 'sync://');

        $recorder->transactional(function () use ($recorder): void {
            $recorder->record($this->event());

            try {
                $recorder->transactional(function () use ($recorder): void {
                    $recorder->record($this->event());
                    throw new RuntimeException('внутрішній збій');
                });
            } catch (RuntimeException) {
            }
        });

        self::assertCount(1, $bus->messages);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_event WHERE id = ?', [$bus->messages[0]->eventId]));
    }

    public function testRecordOutsideTransactionalFansOutImmediately(): void
    {
        $bus = new SpyBus();
        $id = $this->recorder($bus, 'sync://')->record($this->event());

        self::assertEquals([new FanOut($id)], $bus->messages);
    }

    /** Outbox: FanOut у messenger_messages комітиться й відкочується разом із подією. */
    public function testAsyncOutboxIsAtomicWithTheEvent(): void
    {
        $queue = 'plan_atomic_' . uniqid();
        $transport = new DoctrineTransport(new DoctrineQueue(['queue_name' => $queue, 'auto_setup' => false], $this->db), new PhpSerializer());
        $bus = new SpyBus(static fn (object $m) => $transport->send(Envelope::wrap($m)));
        $recorder = $this->recorder($bus, 'doctrine://default?queue_name=' . $queue);
        $queued = fn (): int => (int) $this->db->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?', [$queue]);

        try {
            $recorder->transactional(function () use ($recorder, $queued): void {
                $recorder->record($this->event());
                self::assertSame(1, $queued(), 'Повідомлення лягло в чергу в тій самій транзакції');
                throw new RuntimeException('відкат');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(0, $queued(), 'Відкат забрав і повідомлення');

        $id = $recorder->transactional(fn () => $recorder->record($this->event()));
        self::assertSame(1, $queued());
        $envelope = iterator_to_array($transport->get())[0] ?? null;
        self::assertNotNull($envelope);
        self::assertEquals(new FanOut($id), $envelope->getMessage());
    }

    public function testImportWritesHistoryWithoutSendingAnything(): void
    {
        $bus = new SpyBus();
        $id = $this->recorder($bus, 'sync://')->import($this->event()->via('web', '10.0.0.1'), ['backfill' => true]);

        self::assertSame([], $bus->messages, 'Історія не шле Telegram');
        $row = $this->db->fetchAssociative("SELECT channel, ip, payload->>'backfill' AS backfill FROM plan_event WHERE id = ?", [$id]);
        self::assertSame(['channel' => 'web', 'ip' => '10.0.0.1', 'backfill' => 'true'], $row);
    }

    public function testFailingConsumerDoesNotStopOthers(): void
    {
        $broken = new class implements PlanEventConsumer {
            public function consume(PlanEvent $event): void
            {
                throw new RuntimeException('Telegram недоступний');
            }
        };
        $healthy = new class implements PlanEventConsumer {
            /** @var list<int|null> */
            public array $seen = [];

            public function consume(PlanEvent $event): void
            {
                $this->seen[] = $event->id();
            }
        };

        $id = $this->recorder(new SpyBus(), 'sync://')->record($this->event());
        $handler = new FanOutHandler(self::getContainer()->get(PlanEventStore::class), [$broken, $healthy], new NullLogger(), $this->db);
        $handler(new FanOut($id));
        $handler(new FanOut(PHP_INT_MAX));

        self::assertSame([$id], $healthy->seen);

        // Повторна доставка того ж повідомлення черги (воркер упав до ack) — нікому вдруге.
        $handler(new FanOut($id));
        self::assertCount(1, $healthy->seen, 'Повтор не дійшов до споживача');
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_event_consumed WHERE event_id = ?', [$id]));
    }

    public function testIdempotentProjectionIsRetriedButSideEffectsAreNot(): void
    {
        $projection = new class implements IdempotentConsumer {
            public int $calls = 0;
            public bool $fail = true;

            public function consume(PlanEvent $event): void
            {
                ++$this->calls;

                if ($this->fail) {
                    throw new RuntimeException('lock timeout');
                }
            }
        };
        $telegram = new class implements PlanEventConsumer {
            public int $calls = 0;

            public function consume(PlanEvent $event): void
            {
                ++$this->calls;
            }
        };
        $search = new class implements IdempotentConsumer {
            public int $calls = 0;

            public function consume(PlanEvent $event): void
            {
                ++$this->calls;
            }
        };

        $id = $this->recorder(new SpyBus(), 'sync://')->record($this->event());
        $handler = new FanOutHandler(self::getContainer()->get(PlanEventStore::class), [$projection, $telegram, $search], new NullLogger(), $this->db);

        try {
            $handler(new FanOut($id));
            self::fail('збій проєкції має віддати FanOut на повтор');
        } catch (ProjectionFailed $e) {
            self::assertSame($id, $e->eventId);
            self::assertSame([$projection::class], $e->consumers);
        }

        // Ті, хто впорався, позначені; проєкція, що впала, — ні.
        $claimed = fn () => $this->db->fetchOne('SELECT COUNT(*) FROM plan_event_consumed WHERE event_id = ?', [$id]);
        self::assertSame(2, (int) $claimed());

        // Повтор Messenger: проєкція — ще раз (тепер успішно), Telegram і пошук — ні.
        $projection->fail = false;
        $handler(new FanOut($id));
        self::assertSame([2, 1, 1], [$projection->calls, $telegram->calls, $search->calls]);
        self::assertSame(3, (int) $claimed());

        // Третя доставка — нікому нічого.
        $handler(new FanOut($id));
        self::assertSame([2, 1, 1], [$projection->calls, $telegram->calls, $search->calls]);
    }

    public function testReconcileReplaysMissedProjectionsOnly(): void
    {
        $id = self::getContainer()->get(PlanEventRecorder::class)->record(new CommentAdded(SubjectRef::task(900000020), null, refId: 1, excerpt: 'reconcile'));
        $chronicle = FanOutHandler::consumerKey(ChronicleProjection::class);
        $activity = fn () => $this->db->fetchOne("SELECT last_event_id FROM plan_subject_activity WHERE subject_type = 'task' AND subject_id = 900000020");
        self::assertSame($id, (int) $activity(), 'Хроніка отримала подію одразу (sync)');

        // Імітуємо втрачену проєкцію: ні рядка Хроніки, ні позначки.
        $this->db->executeStatement("DELETE FROM plan_subject_activity WHERE subject_type = 'task' AND subject_id = 900000020");
        $this->db->executeStatement('DELETE FROM plan_event_consumed WHERE event_id = ? AND consumer = ?', [$id, $chronicle]);
        $others = (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_event_consumed WHERE event_id = ?', [$id]);

        $tester = new CommandTester(new Application(self::$kernel)->find('plan:projections:reconcile'));
        self::assertSame(0, $tester->execute(['--min-age' => '0', '--dry-run' => true]));
        self::assertFalse($activity(), 'dry-run нічого не пише');

        self::assertSame(0, $tester->execute(['--min-age' => '0']));
        self::assertSame($id, (int) $activity());
        self::assertSame($others + 1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_event_consumed WHERE event_id = ?', [$id]));

        // Повторний запуск — цю подію вже не чіпає (позначка є).
        self::assertSame(0, $tester->execute(['--min-age' => '0']));
        self::assertSame($others + 1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_event_consumed WHERE event_id = ?', [$id]));
        self::assertSame(2, $tester->execute(['--hours' => '200']), 'вікно ≤ 144 год (plan_event_consumed живе 7 днів)');
    }

    public function testChannelAndIpComeFromTheRequest(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/hook', 'POST', server: ['REMOTE_ADDR' => '149.154.167.1']));
        $recorder = new PlanEventRecorder($this->db, new SpyBus(), $requests, new NullLogger(), 'sync://');
        $bot = $recorder->record($this->event());

        $requests->pop();
        $requests->push(Request::create('/api/tasks/1/messages', 'POST', server: ['REMOTE_ADDR' => '10.1.2.3']));
        $web = $recorder->record($this->event());

        self::assertSame(['bot', null], array_values($this->db->fetchAssociative('SELECT channel, ip FROM plan_event WHERE id = ?', [$bot]) ?: []));
        self::assertSame(['web', '10.1.2.3'], array_values($this->db->fetchAssociative('SELECT channel, ip FROM plan_event WHERE id = ?', [$web]) ?: []));
    }

    private function recorder(SpyBus $bus, string $transport): PlanEventRecorder
    {
        return new PlanEventRecorder($this->db, $bus, new RequestStack(), new NullLogger(), $transport);
    }

    private function event(): CommentAdded
    {
        return new CommentAdded(SubjectRef::task(1), null, refId: 1, excerpt: 'тест');
    }

    private function rows(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_event');
    }
}
