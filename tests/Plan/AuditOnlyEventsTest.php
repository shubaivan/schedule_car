<?php

namespace App\Tests\Plan;

use App\Plan\Audit\AuditCatalog;
use App\Plan\Chronicle\PlanChronicle;
use App\Plan\Event\PlanEventTypes;
use App\Plan\Event\ShareCreated;
use App\Plan\Notification\EventGroup;
use App\Plan\Subject\SubjectRef;
use PHPUnit\Framework\TestCase;

/**
 * Інтеграція потоків: події «лише аудит» (входи, скачування, перегляди, поширення,
 * службові Диска) не стають ні Хронікою, ні сповіщенням без явної групи.
 */
final class AuditOnlyEventsTest extends TestCase
{
    public function testAuditOnlyTypesAreNeverChronicleNews(): void
    {
        foreach (PlanEventTypes::AUDIT_ONLY as $class) {
            self::assertContains($class, PlanEventTypes::CLASSES, $class);
            self::assertFalse(PlanChronicle::isNews($class::type()), $class::type() . ' у Хроніці');
            self::assertArrayHasKey($class::type(), AuditCatalog::TYPES);
        }
    }

    public function testShareNotifiesOnlyPeopleNotLinks(): void
    {
        $ref = SubjectRef::task(1);

        self::assertSame(EventGroup::Share, EventGroup::forEvent(new ShareCreated($ref, 1, 5, 'task', [2, 3], 'users')));
        self::assertNull(EventGroup::forEvent(new ShareCreated($ref, 1, 5, 'task', [], 'link', linkId: 9)));
    }
}
