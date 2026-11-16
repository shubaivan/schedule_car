<?php

namespace App\Tests\Plan;

use App\Plan\Presence\PresenceSection;
use PHPUnit\Framework\TestCase;

/** Розділ у «зараз у Плані»: лише відомі ключі, решта — «План»; назви — українською. */
final class PresenceSectionTest extends TestCase
{
    public function testKnownKeysMapToThemselvesAndAnythingElseIsOther(): void
    {
        self::assertSame(PresenceSection::Warehouse, PresenceSection::fromClient('warehouse'));
        self::assertSame(PresenceSection::Counterparties, PresenceSection::fromClient('counterparties'));
        self::assertSame('Постачання', PresenceSection::Supply->label());
        self::assertSame(PresenceSection::Other, PresenceSection::fromClient('task-42'));
        self::assertSame(PresenceSection::Other, PresenceSection::fromClient(''));
        self::assertSame(PresenceSection::Other, PresenceSection::fromClient(null));
        self::assertSame(PresenceSection::Other, PresenceSection::fromClient(['tasks']));
    }

    public function testEveryKeyFitsTheColumnAndHasAUkrainianLabel(): void
    {
        foreach (PresenceSection::cases() as $section) {
            self::assertLessThanOrEqual(24, strlen($section->value), $section->name);
            self::assertMatchesRegularExpression('/^[А-ЯІЇЄҐ][а-яіїєґʼ\' ]+$/u', $section->label(), $section->name);
        }
    }
}
