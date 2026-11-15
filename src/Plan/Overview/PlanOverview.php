<?php

namespace App\Plan\Overview;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanAccess;
use App\Plan\Access\PlanCapability;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\RequestPresenter;
use App\Warehouse\Service\WarehouseSection;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Заявки й склад для «Огляду» — щоб ранковий екран директора показував увесь
 * завод, а не лише трекер задач. Лише лічильники одним запитом на розділ і
 * короткий список заявок, що чекають рішення саме цієї людини.
 */
final class PlanOverview
{
    private const int DECISIONS_LIMIT = 8;

    public function __construct(
        private readonly Connection $db,
        private readonly PlanAccess $access,
        private readonly SupplyRequestRepository $requests,
        private readonly RequestPresenter $presenter,
        private readonly WarehouseSection $warehouse,
    ) {
    }

    /**
     * @return array{counts: array{open: int, new: int, overdue: int, decide: int, mine: int}, decisions: list<array<string, mixed>>, approve: bool, manage: bool}
     */
    public function supply(TelegramUser $me): array
    {
        $open = array_map(static fn (SupplyStatus $s) => $s->value, SupplyStatus::openCases());
        $decide = [SupplyStatus::Approval->value, SupplyStatus::Waiting->value];
        $row = $this->db->fetchAssociative(
            'SELECT COUNT(*) FILTER (WHERE status IN (:open)) AS open,
                    COUNT(*) FILTER (WHERE status = :new) AS new,
                    COUNT(*) FILTER (WHERE status IN (:open) AND need_by IS NOT NULL AND need_by < CAST(:today AS date)) AS overdue,
                    COUNT(*) FILTER (WHERE status IN (:decide)) AS decide,
                    COUNT(*) FILTER (WHERE status IN (:open) AND author_id = :me) AS mine
               FROM supply_request',
            ['open' => $open, 'new' => SupplyStatus::New->value, 'decide' => $decide, 'me' => (int) $me->getId(), 'today' => self::today()],
            ['open' => ArrayParameterType::STRING, 'decide' => ArrayParameterType::STRING],
        ) ?: [];

        $approves = $this->access->can($me, PlanCapability::SupplyApprove);
        $decisions = [];

        if ($approves) {
            $result = $this->requests->search(['status' => [SupplyStatus::Approval, SupplyStatus::Waiting]], 1, self::DECISIONS_LIMIT);

            foreach ($result['items'] as $request) {
                $decisions[] = $this->presenter->listItem($request);
            }
        }

        return [
            'counts' => [
                'open' => (int) ($row['open'] ?? 0),
                'new' => (int) ($row['new'] ?? 0),
                'overdue' => (int) ($row['overdue'] ?? 0),
                'decide' => (int) ($row['decide'] ?? 0),
                'mine' => (int) ($row['mine'] ?? 0),
            ],
            'decisions' => $decisions,
            'approve' => $approves,
            'manage' => $approves || $this->access->can($me, PlanCapability::SupplyManage),
        ];
    }

    /**
     * Склад — лише тим, хто його бачить: рухи сьогодні, у ремонті, рухів за 7 днів.
     *
     * @return array{shipmentsToday: int, receiptsToday: int, movesWeek: int, repair: int, items: int}|null
     */
    public function warehouse(TelegramUser $me): ?array
    {
        if (! $this->warehouse->isEnabled() || ! $this->access->can($me, PlanCapability::WarehouseView)) {
            return null;
        }

        $today = self::today();
        $moves = $this->db->fetchAssociative(
            "SELECT COUNT(*) FILTER (WHERE type = 'shipment' AND occurred_at = CAST(:today AS date)) AS shipments,
                    COUNT(*) FILTER (WHERE type = 'receipt' AND occurred_at = CAST(:today AS date)) AS receipts,
                    COUNT(*) FILTER (WHERE occurred_at > CAST(:today AS date) - 7) AS week
               FROM wh_movement",
            ['today' => $today],
        ) ?: [];
        $items = $this->db->fetchAssociative(
            "SELECT COUNT(*) FILTER (WHERE state = 'repair') AS repair, COUNT(*) FILTER (WHERE state <> 'written_off') AS items FROM wh_item",
        ) ?: [];

        return [
            'shipmentsToday' => (int) ($moves['shipments'] ?? 0),
            'receiptsToday' => (int) ($moves['receipts'] ?? 0),
            'movesWeek' => (int) ($moves['week'] ?? 0),
            'repair' => (int) ($items['repair'] ?? 0),
            'items' => (int) ($items['items'] ?? 0),
        ];
    }

    private static function today(): string
    {
        return new DateTimeImmutable('today', new DateTimeZone('Europe/Kyiv'))->format('Y-m-d');
    }
}
