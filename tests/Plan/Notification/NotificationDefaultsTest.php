<?php

namespace App\Tests\Plan\Notification;

use App\Plan\Notification\Channel;
use App\Plan\Notification\Entity\NotificationSetting;
use App\Plan\Notification\EventGroup;
use App\Plan\Notification\UserPrefs;
use PHPUnit\Framework\TestCase;

/**
 * Одна зрозуміла модель лічильників (UX#3): дзвіночок — лише те, що потребує мене;
 * усе інше — у Хроніці з «+N»; склад типово мовчить скрізь.
 */
class NotificationDefaultsTest extends TestCase
{
    public function testBellByDefaultOnlyForWhatNeedsMe(): void
    {
        $prefs = new UserPrefs();

        foreach ([EventGroup::Mention, EventGroup::Assigned, EventGroup::Approval, EventGroup::Share, EventGroup::Lead, EventGroup::System] as $group) {
            self::assertTrue($prefs->allows(Channel::App, $group), $group->value . ' — у дзвіночку');
            self::assertTrue($prefs->allows(Channel::Push, $group), $group->value . ' — push');
        }

        foreach ([EventGroup::Comment, EventGroup::Status, EventGroup::Purchase, EventGroup::Warehouse] as $group) {
            self::assertFalse($prefs->allows(Channel::App, $group), $group->value . ' — не в дзвіночку (Хроніка)');
            self::assertFalse($prefs->allows(Channel::Push, $group), $group->value . ' — без push');
        }
    }

    public function testTelegramKeepsEverythingExceptWarehouse(): void
    {
        $prefs = new UserPrefs();

        self::assertTrue($prefs->allows(Channel::Telegram, EventGroup::Comment));
        self::assertTrue($prefs->allows(Channel::Telegram, EventGroup::Status));
        self::assertFalse($prefs->allows(Channel::Telegram, EventGroup::Warehouse), 'Рухи складу типово вимкнено й у Telegram');
    }

    public function testScopeAllBringsCommentsAndStatusesButNotWarehouse(): void
    {
        $prefs = new UserPrefs(scope: NotificationSetting::SCOPE_ALL);

        self::assertTrue($prefs->allows(Channel::App, EventGroup::Comment));
        self::assertTrue($prefs->allows(Channel::App, EventGroup::Status));
        self::assertFalse($prefs->allows(Channel::App, EventGroup::Warehouse));
    }

    public function testExplicitSwitchWinsAndMatrixShowsDefaults(): void
    {
        $prefs = new UserPrefs(switches: ['app:warehouse' => true, 'app:mention' => false]);

        self::assertTrue($prefs->allows(Channel::App, EventGroup::Warehouse));
        self::assertFalse($prefs->allows(Channel::App, EventGroup::Mention));
        $matrix = $prefs->toArray()['groups'];
        self::assertFalse($matrix['comment']['app']);
        self::assertTrue($matrix['comment']['telegram']);
        self::assertTrue($matrix['warehouse']['app']);
        self::assertFalse($matrix['warehouse']['telegram']);
    }

    public function testMasterSwitchStillOverrides(): void
    {
        $prefs = new UserPrefs(telegram: false);

        self::assertFalse($prefs->allows(Channel::Telegram, EventGroup::Mention));
    }
}
