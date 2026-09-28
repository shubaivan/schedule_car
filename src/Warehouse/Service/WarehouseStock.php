<?php

namespace App\Warehouse\Service;

use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Repository\WhItemRepository;
use App\Warehouse\Repository\WhSiteRepository;
use DateTime;
use Doctrine\DBAL\Connection;

/**
 * Де що лежить.
 *
 * Поштучна позиція знає своє місце сама (WhItem::currentSite). Для позиції
 * «кількістю» залишок у кожному місці — це все, що туди приїхало, мінус усе,
 * що звідти поїхало. Рахуємо з рядків рухів щоразу: окрема таблиця залишків
 * рано чи пізно розійшлась би з історією, а рухів тут сотні, не мільйони.
 */
class WarehouseStock
{
    public function __construct(
        private Connection $connection,
        private WhItemRepository $items,
        private WhSiteRepository $sites,
    ) {
    }

    /**
     * Залишки однієї позиції «кількістю» по місцях, лише ненульові.
     *
     * @return array<int, int> site_id => кількість
     */
    public function balances(WhItem $item): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT site_id, SUM(qty) AS qty FROM (
                    SELECT m.to_site_id AS site_id, l.quantity AS qty
                    FROM wh_movement_line l JOIN wh_movement m ON m.id = l.movement_id
                    WHERE l.item_id = :item AND m.to_site_id IS NOT NULL
                    UNION ALL
                    SELECT m.from_site_id, -l.quantity
                    FROM wh_movement_line l JOIN wh_movement m ON m.id = l.movement_id
                    WHERE l.item_id = :item AND m.from_site_id IS NOT NULL
                ) t
                GROUP BY site_id
                HAVING SUM(qty) <> 0
                SQL,
            ['item' => $item->getId()],
        );

        $balances = [];

        foreach ($rows as $row) {
            $balances[(int) $row['site_id']] = (int) $row['qty'];
        }

        return $balances;
    }

    public function balanceAt(WhItem $item, WhSite $site): int
    {
        if ($item->isUnit()) {
            return $item->getCurrentSite()?->getId() === $site->getId() ? 1 : 0;
        }

        return $this->balances($item)[(int) $site->getId()] ?? 0;
    }

    /** Скільки всього є (без списаного) — для позиції «кількістю». */
    public function total(WhItem $item): int
    {
        if ($item->isUnit()) {
            return $item->getCurrentSite() !== null ? 1 : 0;
        }

        return array_sum($this->balances($item));
    }

    /**
     * Залишки по місцях з об'єктами місць — для картки й бота.
     *
     * @return list<array{site: WhSite, quantity: int}>
     */
    public function placesOf(WhItem $item): array
    {
        if ($item->isUnit()) {
            $site = $item->getCurrentSite();

            return $site !== null ? [['site' => $site, 'quantity' => 1]] : [];
        }

        $places = [];

        foreach ($this->balances($item) as $siteId => $quantity) {
            $site = $this->sites->find($siteId);

            if ($site !== null) {
                $places[] = ['site' => $site, 'quantity' => $quantity];
            }
        }

        usort($places, static fn (array $a, array $b) => [! $a['site']->isWarehouse(), $a['site']->getName()]
            <=> [! $b['site']->isWarehouse(), $b['site']->getName()]);

        return $places;
    }

    /**
     * Що зараз стоїть на місці: і поштучні, і залишки «кількістю».
     *
     * @return list<array{item: WhItem, quantity: int, since: ?DateTime, rate: ?string}>
     */
    public function contentsOf(WhSite $site): array
    {
        $contents = [];

        foreach ($this->items->findBy(['currentSite' => $site], ['inventoryNumber' => 'ASC']) as $item) {
            $contents[] = [
                'item' => $item,
                'quantity' => 1,
                'since' => $item->getCurrentSince(),
                'rate' => $this->lastRate($item, $site),
            ];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT item_id, SUM(qty) AS qty FROM (
                    SELECT l.item_id, l.quantity AS qty
                    FROM wh_movement_line l JOIN wh_movement m ON m.id = l.movement_id
                    WHERE m.to_site_id = :site
                    UNION ALL
                    SELECT l.item_id, -l.quantity
                    FROM wh_movement_line l JOIN wh_movement m ON m.id = l.movement_id
                    WHERE m.from_site_id = :site
                ) t
                JOIN wh_item i ON i.id = t.item_id AND i.tracking = 'bulk'
                GROUP BY item_id
                HAVING SUM(qty) <> 0
                SQL,
            ['site' => $site->getId()],
        );

        foreach ($rows as $row) {
            $item = $this->items->find((int) $row['item_id']);

            if ($item !== null) {
                $contents[] = [
                    'item' => $item,
                    'quantity' => (int) $row['qty'],
                    'since' => null,
                    'rate' => $this->lastRate($item, $site),
                ];
            }
        }

        return $contents;
    }

    /** Ставка оренди з останнього руху, яким позиція приїхала на це місце. */
    private function lastRate(WhItem $item, WhSite $site): ?string
    {
        if ($site->isWarehouse()) {
            return null;
        }

        $rate = $this->connection->fetchOne(
            <<<'SQL'
                SELECT l.rental_rate
                FROM wh_movement_line l JOIN wh_movement m ON m.id = l.movement_id
                WHERE l.item_id = :item AND m.to_site_id = :site
                ORDER BY m.occurred_at DESC, m.id DESC
                LIMIT 1
                SQL,
            ['item' => $item->getId(), 'site' => $site->getId()],
        );

        return $rate === false || $rate === null ? null : (string) $rate;
    }
}
