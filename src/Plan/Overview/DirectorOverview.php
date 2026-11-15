<?php

namespace App\Plan\Overview;

use App\Entity\TelegramUser;
use App\Plan\Access\PlanAccess;
use App\Plan\Access\PlanCapability;
use App\Service\FleetSection;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Repository\DepartmentRepository;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\RequestPresenter;
use App\Supply\Service\SupplyReports;
use App\Tasks\Entity\Task;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Repository\TaskAttachmentRepository;
use App\Tasks\Repository\TaskMessageRepository;
use App\Tasks\Repository\TaskRepository;
use App\Tasks\Service\TaskPolicy;
use App\Tasks\Service\TaskPresenter;
use App\Warehouse\Service\LowStock;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * «Огляд» v2 — екран директора за зразком ПланФікса: рядок великих чисел, під ним
 * «Чекають мого рішення» з кнопками просто в рядку, «Контроль по відділах»,
 * постачання за місяць, автопарк по бронях і склад. Одна відповідь на весь екран.
 *
 * Кожне число рахується тим самим правилом, що й список, куди воно веде
 * (/tasks/list, дошка заявок, склад), і в межах того, що людина бачить:
 * директор і адмін — увесь завод, решта — свої задачі; гроші постачання —
 * лише тим, хто веде або погоджує закупівлі; склад — тим, хто його веде.
 */
final class DirectorOverview
{
    public const int STALE_DAYS = 3;
    private const int DECISIONS_LIMIT = 10;
    private const int MINE_LIMIT = 6;
    private const int LOW_LIMIT = 6;

    public function __construct(
        private readonly Connection $db,
        private readonly PlanAccess $access,
        private readonly PlanOverview $overview,
        private readonly TaskRepository $tasks,
        private readonly TaskPresenter $taskPresenter,
        private readonly TaskPolicy $policy,
        private readonly TaskMessageRepository $messages,
        private readonly TaskAttachmentRepository $attachments,
        private readonly SupplyRequestRepository $requests,
        private readonly RequestPresenter $requestPresenter,
        private readonly SupplyReports $reports,
        private readonly DepartmentRepository $departments,
        private readonly FleetSection $fleet,
        private readonly LowStock $lowStock,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(TelegramUser $me): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv'));
        $stats = $this->tasks->dashboardStats($me);
        $decisions = $this->decisions($me);
        $supply = $this->supply($me, $now);
        $fleet = $this->fleet($now);
        $warehouse = $this->warehouse($me);
        $control = $this->control($me);

        return [
            'generatedAt' => $now->format(DATE_ATOM),
            'scope' => [
                'all' => $me->seesAllTasks(),
                'department' => $me->getDepartment()?->getName(),
                'decideTasks' => $me->getWorkRole()->decides(),
                'decideSupply' => $supply['approve'],
            ],
            'kpi' => [
                'overdueTasks' => $stats['overdue'],
                'openTasks' => $stats['open'],
                'mineTasks' => $stats['mine'],
                'staleTasks' => $control['total']['stale'],
                'decisions' => $decisions['total'],
                'supplyOverdue' => $supply['counts']['overdue'],
                'fleet' => ! $fleet['enabled'] ? null : ['busy' => $fleet['busyToday'], 'free' => $fleet['freeToday']],
                'shipmentsToday' => $warehouse['shipmentsToday'] ?? null,
            ],
            'decisions' => $decisions,
            'control' => $control,
            'supply' => $supply,
            'fleet' => $fleet,
            'warehouse' => $warehouse,
            'mine' => $this->mine($me),
        ];
    }

    /**
     * Задачі (директору й адміну) і заявки (тим, хто погоджує оплату) однією чергою;
     * у кожному рядку — лише ті рішення, які ця людина справді може ухвалити.
     *
     * @return array{items: list<array<string, mixed>>, total: int, tasks: int, supply: int}
     */
    private function decisions(TelegramUser $me): array
    {
        $items = [];
        $taskTotal = 0;
        $supplyTotal = 0;
        $awaiting = [TaskStatus::Approval, TaskStatus::Waiting];

        if ($me->getWorkRole()->decides()) {
            $filters = ['status' => $awaiting];
            $taskTotal = $this->tasks->countMatching($me, $filters);

            foreach ($this->tasks->search($me, $filters, self::DECISIONS_LIMIT, 'due') as $task) {
                $items[] = $this->taskDecision($me, $task);
            }
        }

        if ($this->access->can($me, PlanCapability::SupplyApprove)) {
            $result = $this->requests->search(['status' => [SupplyStatus::Approval, SupplyStatus::Waiting]], 1, self::DECISIONS_LIMIT);
            $supplyTotal = $result['total'];

            foreach ($result['items'] as $request) {
                $items[] = $this->supplyDecision($me, $request);
            }
        }

        // Спершу прострочені й з найближчим терміном, далі — що довше чекає.
        usort($items, static fn (array $a, array $b) => [! $a['overdue'], $a['due'] ?? '9999', $a['since']] <=> [! $b['overdue'], $b['due'] ?? '9999', $b['since']]);

        return [
            'items' => array_slice($items, 0, self::DECISIONS_LIMIT),
            'total' => $taskTotal + $supplyTotal,
            'tasks' => $taskTotal,
            'supply' => $supplyTotal,
        ];
    }

    /** @return array<string, mixed> */
    private function taskDecision(TelegramUser $me, Task $task): array
    {
        $actions = [];

        foreach ([TaskStatus::Approved, TaskStatus::Rejected] as $to) {
            if ($task->getStatus()->canMoveTo($to) && $this->policy->canMove($me, $task, $to)) {
                $actions[] = ['to' => $to->value, 'label' => $to->action(), 'reject' => $to === TaskStatus::Rejected, 'reasonRequired' => false];
            }
        }

        return [
            'kind' => 'task',
            'id' => $task->getId(),
            'number' => $task->number(),
            'title' => $task->getTitle(),
            'detail' => $task->getClient(),
            'status' => $task->getStatus()->value,
            'statusLabel' => $task->getStatus()->label(),
            'due' => $task->getDueAt()?->format('Y-m-d'),
            'overdue' => $task->isOverdue(),
            'since' => $task->getLastActivityAt()->format(DATE_ATOM),
            'person' => $this->taskPresenter->person($task->getCreator()),
            'amount' => null,
            'actions' => $actions,
        ];
    }

    /** @return array<string, mixed> */
    private function supplyDecision(TelegramUser $me, SupplyRequest $request): array
    {
        $from = $request->getStatus();
        $actions = [];
        $labels = [SupplyStatus::Approved->value => 'Погодити', SupplyStatus::Rejected->value => 'Відхилити'];

        foreach ([SupplyStatus::Approved, SupplyStatus::Rejected] as $to) {
            if ($from->canTransitionTo($to) && $me->getSupplyRole()->canMoveRequest($from, $to)) {
                // Відхилення заявки — лише з причиною (ChangeStatus): заявник має знати, що далі.
                $actions[] = ['to' => $to->value, 'label' => $labels[$to->value], 'reject' => $to === SupplyStatus::Rejected, 'reasonRequired' => $to === SupplyStatus::Rejected];
            }
        }

        $item = $this->requestPresenter->listItem($request);
        $total = (float) $request->getPurchaseTotal();

        return [
            'kind' => 'supply',
            'id' => $request->getId(),
            'number' => $request->getNumber(),
            'title' => $request->getItem() . ' — ' . $request->getQuantityLabel(),
            'detail' => $item['department'],
            'status' => $from->value,
            'statusLabel' => $from->label(),
            'due' => $item['needBy'],
            'overdue' => $item['overdue'],
            'since' => $item['createdAt'],
            'person' => $item['author'],
            'amount' => $total > 0 ? $total : null,
            'actions' => $actions,
        ];
    }

    /**
     * Матриця «відділ × (відкриті, прострочені, без руху)». Директор бачить усі діючі
     * відділи (і нулі — теж відповідь), решта — лише відділи зі своїми задачами.
     *
     * @return array<string, mixed>
     */
    private function control(TelegramUser $me): array
    {
        $counts = $this->tasks->departmentControl($me, self::STALE_DAYS);
        $all = $me->seesAllTasks();
        $zero = ['open' => 0, 'overdue' => 0, 'stale' => 0];
        $rows = [];
        $known = [];

        foreach ($this->departments->findActive() as $department) {
            $id = (int) $department->getId();
            $known[$id] = true;
            $row = $counts['departments'][$id] ?? $zero;

            if ($all || $row['open'] > 0) {
                $rows[] = ['id' => $id, 'name' => $department->getName(), ...$row];
            }
        }

        // Архівний відділ, у якому ще висять відкриті задачі, — теж відхилення.
        foreach ($counts['departments'] as $id => $row) {
            if (! isset($known[$id]) && $row['open'] > 0) {
                $rows[] = ['id' => $id, 'name' => $this->departments->find($id)?->getName() ?? 'Відділ #' . $id, ...$row];
            }
        }

        return [
            'rows' => $rows,
            'none' => $counts['none'],
            'total' => $counts['total'],
            'staleDays' => self::STALE_DAYS,
        ];
    }

    /** @return array<string, mixed> */
    private function supply(TelegramUser $me, DateTimeImmutable $now): array
    {
        $approve = $this->access->can($me, PlanCapability::SupplyApprove);
        $manage = $this->access->can($me, PlanCapability::SupplyManage);
        $from = $now->modify('first day of this month')->setTime(0, 0);
        $to = $now->modify('last day of this month')->setTime(0, 0);

        return [
            'counts' => $this->overview->supplyCounts($me),
            'approve' => $approve,
            'manage' => $manage || $approve,
            // Гроші — тим, хто веде або погоджує закупівлі (звіт постачання — так само).
            'month' => $manage || $approve ? [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                ...$this->reports->spend($from, $to),
            ] : null,
            'reports' => $manage,
        ];
    }

    /**
     * Автопарк по бронях: які машини мають броні сьогодні й завтра (години). Статусів
     * «в рейсі» / «ремонт» у даних немає — лише броні; розділ вимкнено — так і кажемо.
     *
     * @return array<string, mixed>
     */
    private function fleet(DateTimeImmutable $now): array
    {
        if (! $this->fleet->isEnabled()) {
            return ['enabled' => false];
        }

        $today = $now->setTime(0, 0);
        $rows = $this->db->fetchAllAssociative(
            "SELECT c.id, c.car_number, c.model,
                    COALESCE(array_to_json(array_agg(DISTINCT s.hour ORDER BY s.hour) FILTER (WHERE s.scheduled_at < CAST(:tomorrow AS timestamp))), '[]') AS today,
                    COALESCE(array_to_json(array_agg(DISTINCT s.hour ORDER BY s.hour) FILTER (WHERE s.scheduled_at >= CAST(:tomorrow AS timestamp))), '[]') AS tomorrow
               FROM car c
               LEFT JOIN scheduled_set s ON s.car_id = c.id AND s.scheduled_at >= CAST(:today AS timestamp) AND s.scheduled_at < CAST(:after AS timestamp)
              WHERE c.active = TRUE
              GROUP BY c.id, c.car_number, c.model
              ORDER BY c.car_number",
            [
                'today' => $today->format('Y-m-d H:i:s'),
                'tomorrow' => $today->modify('+1 day')->format('Y-m-d H:i:s'),
                'after' => $today->modify('+2 days')->format('Y-m-d H:i:s'),
            ],
        );

        $cars = array_map(static fn (array $row) => [
            'id' => (int) $row['id'],
            'number' => (string) $row['car_number'],
            'model' => $row['model'],
            'today' => array_map('intval', (array) json_decode((string) $row['today'], true)),
            'tomorrow' => array_map('intval', (array) json_decode((string) $row['tomorrow'], true)),
        ], $rows);
        $busy = count(array_filter($cars, static fn (array $c) => $c['today'] !== []));

        return [
            'enabled' => true,
            'cars' => $cars,
            'busyToday' => $busy,
            'freeToday' => count($cars) - $busy,
            'busyTomorrow' => count(array_filter($cars, static fn (array $c) => $c['tomorrow'] !== [])),
        ];
    }

    /** @return array<string, mixed>|null */
    private function warehouse(TelegramUser $me): ?array
    {
        $base = $this->overview->warehouse($me);

        if ($base === null) {
            return null;
        }

        $low = $this->lowStock->items();

        return [...$base, 'lowCount' => count($low), 'low' => array_slice($low, 0, self::LOW_LIMIT)];
    }

    /** @return list<array<string, mixed>> */
    private function mine(TelegramUser $me): array
    {
        $tasks = $this->tasks->search($me, ['member' => $me, 'open' => true], self::MINE_LIMIT, 'due');
        $ids = array_map(static fn (Task $t) => (int) $t->getId(), $tasks);
        $unread = $this->tasks->unreadCounts($me, $ids);
        $last = $this->messages->lastComments($ids);
        $files = $this->attachments->statsByTask($ids);

        return array_map(fn (Task $t) => $this->taskPresenter->listItem($t, $unread, $last, $files), $tasks);
    }
}
