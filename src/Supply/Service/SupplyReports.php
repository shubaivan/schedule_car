<?php

namespace App\Supply\Service;

use App\Supply\Enum\SupplyStatus;
use DateTimeInterface;
use Doctrine\DBAL\Connection;

/**
 * Звіти по постачанню за період.
 *
 * Рахуємо в базі, а не в PHP: вибирати тисячі заявок у пам'ять заради однієї
 * суми — марно. Запити нативні, бо GROUP BY з обчисленням строків у DQL
 * виходить довшим і менш зрозумілим, ніж той самий SQL.
 *
 * Період беремо по даті закупівлі (purchased_at), а не по даті створення
 * заявки: бухгалтерію цікавить, коли витратили гроші, а заявку могли подати
 * ще минулого місяця.
 */
class SupplyReports
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $period = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ];

        return [
            'period' => $period,
            'totals' => $this->totals($period),
            'suppliers' => $this->bySupplier($period),
            'departments' => $this->byDepartment($period),
            'items' => $this->byItem($period),
        ];
    }

    /** Загальні цифри: скільки заявок, скільки грошей, як довго закривали. */
    private function totals(array $period): array
    {
        $requests = $this->connection->fetchAssociative(
            'SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = :in_stock) AS closed,
                COUNT(*) FILTER (WHERE status = :rejected) AS rejected,
                COUNT(*) FILTER (WHERE status NOT IN (:in_stock, :rejected)) AS open,
                COUNT(*) FILTER (
                    WHERE need_by IS NOT NULL
                      AND need_by < CURRENT_DATE
                      AND status NOT IN (:in_stock, :rejected)
                ) AS overdue,
                -- Середній строк «подали → на складі», у днях.
                AVG(EXTRACT(EPOCH FROM (closed_at - created_at)) / 86400)
                    FILTER (WHERE status = :in_stock AND closed_at IS NOT NULL) AS lead_time
             FROM supply_request
             WHERE created_at::date BETWEEN :from AND :to',
            [
                'in_stock' => SupplyStatus::InStock->value,
                'rejected' => SupplyStatus::Rejected->value,
                'from' => $period['from'],
                'to' => $period['to'],
            ],
        ) ?: [];

        $money = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(total_amount), 0) AS spent, COUNT(*) AS purchases
             FROM supply_purchase
             WHERE purchased_at BETWEEN :from AND :to',
            ['from' => $period['from'], 'to' => $period['to']],
        ) ?: [];

        return [
            'requests' => (int) ($requests['total'] ?? 0),
            'closed' => (int) ($requests['closed'] ?? 0),
            'rejected' => (int) ($requests['rejected'] ?? 0),
            'open' => (int) ($requests['open'] ?? 0),
            'overdue' => (int) ($requests['overdue'] ?? 0),
            'purchases' => (int) ($money['purchases'] ?? 0),
            'spent' => round((float) ($money['spent'] ?? 0), 2),
            // null — жодної закритої заявки за період, а не «нуль днів».
            'leadTimeDays' => $requests['lead_time'] !== null ? round((float) $requests['lead_time'], 1) : null,
        ];
    }

    /** Оборот по постачальниках — головна таблиця звіту. */
    private function bySupplier(array $period): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.name, COUNT(p.id) AS purchases, SUM(p.total_amount) AS total
             FROM supply_purchase p
             JOIN supply_supplier s ON s.id = p.supplier_id
             WHERE p.purchased_at BETWEEN :from AND :to
             GROUP BY s.id, s.name
             ORDER BY total DESC',
            ['from' => $period['from'], 'to' => $period['to']],
        );

        $spent = array_sum(array_map(static fn (array $row) => (float) $row['total'], $rows));

        return array_map(static fn (array $row) => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'purchases' => (int) $row['purchases'],
            'total' => round((float) $row['total'], 2),
            // Частка постачальника в закупівлях — видно, на кому зав'язані.
            'share' => $spent > 0 ? round((float) $row['total'] / $spent * 100, 1) : 0.0,
        ], $rows);
    }

    /** Скільки витратили підрозділи. Заявки без підрозділу йдуть окремим рядком. */
    private function byDepartment(array $period): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT d.id, d.name, COUNT(DISTINCT r.id) AS requests, COALESCE(SUM(p.total_amount), 0) AS total
             FROM supply_purchase p
             JOIN supply_request r ON r.id = p.request_id
             LEFT JOIN supply_department d ON d.id = r.department_id
             WHERE p.purchased_at BETWEEN :from AND :to
             GROUP BY d.id, d.name
             ORDER BY total DESC',
            ['from' => $period['from'], 'to' => $period['to']],
        );

        return array_map(static fn (array $row) => [
            'id' => $row['id'] !== null ? (int) $row['id'] : null,
            'name' => $row['name'] ?? 'Без підрозділу',
            'requests' => (int) $row['requests'],
            'total' => round((float) $row['total'], 2),
        ], $rows);
    }

    /**
     * Топ матеріалів. Групуємо по тексту заявки, бо довідника номенклатури
     * ще немає: «Цемент М400» і «цемент м-400» тут будуть двома рядками.
     */
    private function byItem(array $period): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.item, COUNT(DISTINCT r.id) AS requests, COALESCE(SUM(p.total_amount), 0) AS total
             FROM supply_purchase p
             JOIN supply_request r ON r.id = p.request_id
             WHERE p.purchased_at BETWEEN :from AND :to
             GROUP BY r.item
             ORDER BY total DESC
             LIMIT 20',
            ['from' => $period['from'], 'to' => $period['to']],
        );

        return array_map(static fn (array $row) => [
            'item' => $row['item'],
            'requests' => (int) $row['requests'],
            'total' => round((float) $row['total'], 2),
        ], $rows);
    }
}
