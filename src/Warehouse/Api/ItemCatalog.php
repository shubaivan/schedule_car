<?php

namespace App\Warehouse\Api;

use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Enum\ItemState;
use App\Warehouse\Enum\Tracking;
use App\Warehouse\Repository\WhCategoryRepository;
use App\Warehouse\Repository\WhDocumentRepository;
use App\Warehouse\Repository\WhItemRepository;
use App\Warehouse\Repository\WhSiteRepository;
use App\Warehouse\Service\LowStock;
use Doctrine\DBAL\Connection;

/**
 * Список позицій для «Плану»: пошук і фільтри як на Twig-сторінці /sklad, плюс
 * залишки позицій «кількістю» одним запитом на весь список (Twig рахує їх
 * по одному запиту на позицію — для десятків рядків це ще терпимо, для
 * опитування з телефона вже ні).
 *
 * Арифметика та сама, що в WarehouseStock::balances: усе, що приїхало на місце,
 * мінус усе, що звідти поїхало; нулі відкидаються.
 */
final class ItemCatalog
{
    public function __construct(
        private readonly WhItemRepository $items,
        private readonly WhCategoryRepository $categories,
        private readonly WhSiteRepository $sites,
        private readonly WhDocumentRepository $documents,
        private readonly WarehousePresenter $presenter,
        private readonly Connection $connection,
        private readonly LowStock $lowStock,
    ) {
    }

    /**
     * @param string $place '' — усе; 'none' — ніде (ще не надійшло / усе роздано); 'low' — на складах менше мінімуму; інакше id місця
     *
     * @return array{items: list<array<string, mixed>>, total: int, counts: array{all: int, unit: int, bulk: int, repair: int, low: int}}
     */
    public function search(string $query, ?int $categoryId, string $place, ?string $state, ?string $tracking, bool $withWrittenOff): array
    {
        $category = $categoryId ? $this->categories->find($categoryId) : null;
        $items = $this->items->search($query, $category, '', $withWrittenOff || $state === ItemState::WrittenOff->value);
        $balances = $this->bulkBalances();

        $low = array_flip($this->lowStock->ids());
        $counts = ['all' => count($items), 'unit' => 0, 'bulk' => 0, 'repair' => 0, 'low' => 0];

        foreach ($items as $item) {
            ++$counts[$item->isUnit() ? 'unit' : 'bulk'];
            $counts['repair'] += $item->getState() === ItemState::Repair ? 1 : 0;
            $counts['low'] += isset($low[(int) $item->getId()]) ? 1 : 0;
        }

        $items = array_values(array_filter($items, function (WhItem $item) use ($place, $state, $tracking, $balances, $low): bool {
            if ($state !== null && $state !== '' && $item->getState()->value !== $state) {
                return false;
            }

            if ($tracking !== null && $tracking !== '' && $item->getTracking() !== Tracking::tryFrom($tracking)) {
                return false;
            }

            $here = $balances[(int) $item->getId()] ?? [];

            return match (true) {
                $place === '' => true,
                $place === 'low' => isset($low[(int) $item->getId()]),
                $place === 'none' => $item->isUnit() ? $item->getCurrentSite() === null : array_sum($here) === 0,
                ctype_digit($place) => $item->isUnit() ? $item->getCurrentSite()?->getId() === (int) $place : ($here[(int) $place] ?? 0) > 0,
                default => true,
            };
        }));

        $sites = [];

        foreach ($this->sites->findAll() as $site) {
            $sites[(int) $site->getId()] = $site;
        }

        $covers = $this->documents->coverPhotos($items);
        $rows = [];

        foreach ($items as $item) {
            $id = (int) $item->getId();
            $rows[] = $this->presenter->itemRow($item, $item->isUnit() ? [] : $this->sorted($balances[$id] ?? [], $sites), $sites, $covers[$id] ?? null);
        }

        return ['items' => $rows, 'total' => count($rows), 'counts' => $counts];
    }

    /**
     * Залишки всіх позицій «кількістю» по місцях — один запит.
     *
     * @return array<int, array<int, int>> item_id => [site_id => кількість], лише ненульові
     */
    public function bulkBalances(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT item_id, site_id, SUM(qty) AS qty FROM (
                    SELECT l.item_id, m.to_site_id AS site_id, l.quantity AS qty
                    FROM wh_movement_line l JOIN wh_movement m ON m.id = l.movement_id
                    WHERE m.to_site_id IS NOT NULL
                    UNION ALL
                    SELECT l.item_id, m.from_site_id, -l.quantity
                    FROM wh_movement_line l JOIN wh_movement m ON m.id = l.movement_id
                    WHERE m.from_site_id IS NOT NULL
                ) t
                JOIN wh_item i ON i.id = t.item_id AND i.tracking = 'bulk'
                GROUP BY item_id, site_id
                HAVING SUM(qty) <> 0
                SQL,
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['item_id']][(int) $row['site_id']] = (int) $row['qty'];
        }

        return $out;
    }

    /**
     * Склади першими, далі об'єкти за назвою — як WarehouseStock::placesOf.
     *
     * @param array<int, int> $balances
     * @param array<int, WhSite> $sites
     *
     * @return array<int, int>
     */
    private function sorted(array $balances, array $sites): array
    {
        uksort($balances, static function (int $a, int $b) use ($sites): int {
            $sa = $sites[$a] ?? null;
            $sb = $sites[$b] ?? null;

            return [! $sa?->isWarehouse(), $sa?->getName()] <=> [! $sb?->isWarehouse(), $sb?->getName()];
        });

        return $balances;
    }
}
