<?php

namespace App\Tests\Warehouse;

use App\Service\CrmLoginLink;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Service\WarehouseLinks;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Посилання й наклейки. Наклейка (w-) і переслане посилання (wi-) на той
 * самий предмет навмисно різні — журнал відрізняє скан від переходу.
 */
class WarehouseLinksTest extends TestCase
{
    public function testPayloadsAreRecognised(): void
    {
        self::assertSame(['kind' => 'scan', 'id' => 12], WarehouseLinks::parse('w-12'));
        self::assertSame(['kind' => 'item', 'id' => 12], WarehouseLinks::parse('wi-12'), '«wi-» не має впізнатись як «w-»');
        self::assertSame(['kind' => 'client', 'id' => 3], WarehouseLinks::parse('wc-3'));
        self::assertSame(['kind' => 'site', 'id' => 4], WarehouseLinks::parse('ws-4'));
        self::assertSame(['kind' => 'movement', 'id' => 5], WarehouseLinks::parse('wm-5'));
    }

    public function testForeignOrBrokenPayloadsAreNotOurs(): void
    {
        foreach (['', 'w-', 'w-abc', 'w-0', 'x-12', 'w-12345678901', 'wi-1;drop'] as $payload) {
            self::assertNull(WarehouseLinks::parse($payload), $payload);
        }
    }

    public function testStickerAndSharedLinkDiffer(): void
    {
        $links = new WarehouseLinks('@testbot');
        $item = $this->item(7);

        self::assertSame('https://t.me/testbot?start=w-7', $links->scanUrl($item));
        self::assertSame('https://t.me/testbot?start=wi-7', $links->url($item));
    }

    public function testStickerIsScalableSvg(): void
    {
        $svg = (new WarehouseLinks('testbot'))->qrSvg($this->item(7));

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('viewBox', $svg);
        self::assertStringNotContainsString('<?xml', $svg);
    }

    /** Після входу з бота ведемо лише на свої сторінки — інакше це відкритий редирект. */
    public function testLoginRedirectStaysInside(): void
    {
        self::assertSame('/sklad/items/5?via=bot', CrmLoginLink::safeNext('/sklad/items/5?via=bot'));
        self::assertSame('/sklad', CrmLoginLink::safeNext('/sklad'));
        self::assertNull(CrmLoginLink::safeNext('https://evil.example/sklad'));
        self::assertNull(CrmLoginLink::safeNext('//evil.example'));
        self::assertNull(CrmLoginLink::safeNext('/sklad/items/5?next=//evil'));
        self::assertNull(CrmLoginLink::safeNext(null));
    }

    private function item(int $id): WhItem
    {
        $item = (new WhItem())->setName('Щит')->setInventoryNumber('ОП-0007');
        (new ReflectionProperty(WhItem::class, 'id'))->setValue($item, $id);

        return $item;
    }
}
