<?php

namespace App\Tests\Plan;

use App\Plan\Event\CommentAdded;
use App\Plan\Event\PlanEvent;
use App\Plan\Event\PlanEventTypes;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\EventStore\PlanEventStore;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Кожен тип події: зареєстрований, з унікальним коротким TYPE, і без втрат
 * проходить шлях «подія → рядок plan_event → та сама подія» (так її бачить воркер).
 */
class PlanEventTypesTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        parent::tearDown();
    }

    public function testEveryEventClassIsRegisteredWithUniqueType(): void
    {
        $found = [];

        foreach (glob(dirname(__DIR__, 2) . '/src/Plan/Event/*.php') ?: [] as $file) {
            $class = 'App\\Plan\\Event\\' . basename($file, '.php');
            $reflection = new ReflectionClass($class);

            if ($reflection->isSubclassOf(PlanEvent::class) && ! $reflection->isAbstract()) {
                $found[] = $class;
            }
        }

        self::assertEqualsCanonicalizing($found, PlanEventTypes::CLASSES, 'Новий клас події — додай у PlanEventTypes::CLASSES');

        $types = PlanEventTypes::types();
        self::assertSame($types, array_unique($types), 'TYPE подій не повторюються');

        foreach ($types as $type) {
            self::assertMatchesRegularExpression('/^[a-z_]{3,40}$/', $type, 'plan_event.type — varchar(40)');
            self::assertSame($type, PlanEventTypes::classOf($type)::type());
        }
    }

    /** @return iterable<string, array{class-string<PlanEvent>}> */
    public static function eventClasses(): iterable
    {
        foreach (PlanEventTypes::CLASSES as $class) {
            yield $class::type() => [$class];
        }
    }

    /** @param class-string<PlanEvent> $class */
    #[DataProvider('eventClasses')]
    public function testEventSurvivesJournalRoundTrip(string $class): void
    {
        $at = new DateTimeImmutable('2026-11-15 09:30:15', new DateTimeZone('Europe/Kyiv'));
        $event = $this->sample($class, $at);
        $recorder = new PlanEventRecorder(
            $this->db,
            new SpyBus(),
            new RequestStack(),
            new NullLogger(),
            'sync://',
        );

        $id = $recorder->record($event->via('bot', '10.0.0.7'));
        $loaded = self::getContainer()->get(PlanEventStore::class)->load($id);

        self::assertInstanceOf($class, $loaded);
        self::assertSame($id, $loaded->id());
        self::assertTrue($event->subject->equals($loaded->subject));
        self::assertNull($loaded->actorId, 'actor — FK, у тесті без людини');
        self::assertEquals($at, $loaded->at);
        self::assertSame($event->payload(), $loaded->payload());
        self::assertSame('bot', $loaded->channel());
        self::assertSame('10.0.0.7', $loaded->ip());

        $row = $this->db->fetchAssociative('SELECT type, subject_type, subject_id FROM plan_event WHERE id = ?', [$id]);
        self::assertSame(['type' => $class::type(), 'subject_type' => $event->subject->type->value, 'subject_id' => $event->subject->id], $row);
    }

    public function testOldRowsWithoutNewFieldsStillLoad(): void
    {
        $this->db->executeStatement(
            "INSERT INTO plan_event (id, type, subject_type, subject_id, actor_id, occurred_at, payload, channel)
             VALUES (nextval('plan_event_id_seq'), 'comment_added', 'task', 5, NULL, '2026-11-15 10:00:00', '{\"refId\": 9, \"unknown\": 1}', 'web')",
        );
        $id = (int) $this->db->lastInsertId();
        $event = self::getContainer()->get(PlanEventStore::class)->load($id);

        self::assertInstanceOf(CommentAdded::class, $event);
        self::assertSame(9, $event->refId);
        self::assertSame([], $event->mentions, 'відсутнє поле → значення за замовчуванням');
    }

    public function testSubjectKeys(): void
    {
        $ref = SubjectRef::fromKey('wh_item-7');
        self::assertSame(SubjectType::WhItem, $ref->type);
        self::assertSame('wh_item-7', $ref->key());
        self::assertSame(['type' => 'task', 'id' => 12], SubjectRef::task(12)->toArray());
        self::assertSame('user-0', SubjectRef::unknownUser()->key());

        $this->expectException(InvalidArgumentException::class);
        SubjectRef::fromKey('task-0');
    }

    /** @param class-string<PlanEvent> $class */
    private function sample(string $class, DateTimeImmutable $at): PlanEvent
    {
        $args = ['subject' => SubjectRef::task(41), 'actorId' => null, 'at' => $at];

        foreach (new ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();

            if (isset($args[$name]) || $name === 'actorId') {
                continue;
            }

            $type = $parameter->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type);

            // Не значення за замовчуванням — щоб перевірити, що поле справді доїжджає.
            $args[$name] = match ($type->getName()) {
                'int' => 77,
                'bool' => ! ($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue()),
                'float' => 1.5,
                'array' => str_ends_with($name, 'Ids') || $name === 'mentions' ? [3, 5] : ['Накладна №1.pdf', 'ʼапостроф'],
                default => 'Значення «' . $name . '»',
            };
        }

        return new $class(...$args);
    }
}
