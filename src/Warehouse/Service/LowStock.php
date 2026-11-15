<?php

namespace App\Warehouse\Service;

use Doctrine\DBAL\Connection;

/**
 * Позиції «кількістю», яких на складах менше за мінімум (WhItem::minQuantity).
 *
 * Рахуємо лише власні склади (SiteKind::Warehouse): те, що поїхало на об'єкт,
 * взяти в роботу вже не можна. Один запит по рядках рухів — як WarehouseStock.
 */
class LowStock
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{id: int, name: string, unit: string, quantity: int, min: int}> від найбільшої нестачі
     */
    public function items(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT i.id, i.name, i.unit, i.min_quantity, COALESCE(SUM(b.qty), 0) AS qty
                  FROM wh_item i
                  LEFT JOIN (
                      SELECT l.item_id,
                             CASE WHEN ts.kind = 'warehouse' THEN l.quantity ELSE 0 END
                           - CASE WHEN fs.kind = 'warehouse' THEN l.quantity ELSE 0 END AS qty
                        FROM wh_movement_line l
                        JOIN wh_movement m ON m.id = l.movement_id
                        LEFT JOIN wh_site ts ON ts.id = m.to_site_id
                        LEFT JOIN wh_site fs ON fs.id = m.from_site_id
                  ) b ON b.item_id = i.id
                 WHERE i.tracking = 'bulk' AND i.min_quantity IS NOT NULL AND i.state <> 'written_off'
                 GROUP BY i.id, i.name, i.unit, i.min_quantity
                HAVING COALESCE(SUM(b.qty), 0) < i.min_quantity
                 ORDER BY (i.min_quantity - COALESCE(SUM(b.qty), 0)) DESC, i.name
                SQL,
        );

        return array_map(static fn (array $row) => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'unit' => (string) $row['unit'],
            'quantity' => (int) $row['qty'],
            'min' => (int) $row['min_quantity'],
        ], $rows);
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_map(static fn (array $row) => $row['id'], $this->items());
    }
}
