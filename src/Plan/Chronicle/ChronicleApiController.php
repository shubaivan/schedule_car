<?php

namespace App\Plan\Chronicle;

use App\Plan\Event\ApprovalRequested;
use App\Plan\Event\CommentAdded;
use App\Plan\Event\FileAdded;
use App\Plan\Event\ItemCreated;
use App\Plan\Event\MemberAdded;
use App\Plan\Event\MemberRemoved;
use App\Plan\Event\PurchaseRecorded;
use App\Plan\Event\StatusChanged;
use App\Plan\Event\StockMoved;
use App\Plan\Event\SubjectClosed;
use App\Plan\Event\SubjectCreated;
use App\Plan\Event\SubjectUpdated;
use App\Plan\Search\SearchCatalog;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectRegistry;
use App\Plan\Subject\SubjectType;
use App\Repository\TelegramUserRepository;
use App\Supply\Enum\SupplyStatus;
use App\Tasks\Controller\TasksController;
use App\Tasks\Entity\Task;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Service\TaskPolicy;
use App\Tasks\Service\TaskPresenter;
use App\Tasks\Service\TaskService;
use App\Warehouse\Enum\MovementType;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Хроніка v2 «Плану»: `GET /api/plan/chronicle` (задачі + заявки + склад,
 * ?module=task,supply,warehouse &event=comment,status,file,created,stock &unread=1 &favorites=1 &cursor=),
 * `GET /counts` (бейдж меню), `POST /read-all`, `POST /{task-12|supply-4|wh_item-7}/read`.
 */
#[Route('/api/plan/chronicle')]
final class ChronicleApiController extends TasksController
{
    private const int PAGE = 30;

    private const array MODULE_LABELS = ['task' => 'Задача', 'supply' => 'Постачання', 'warehouse' => 'Склад'];

    public function __construct(
        private readonly PlanChronicle $chronicle,
        private readonly TelegramUserRepository $users,
        private readonly TaskPresenter $presenter,
        private readonly SubjectRegistry $subjects,
        private readonly TaskPolicy $policy,
        private readonly TaskService $tasks,
        private readonly Connection $db,
    ) {
    }

    #[Route('', name: 'api_plan_chronicle', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $me = $this->me();
        $limit = min(100, max(5, $request->query->getInt('limit', self::PAGE)));
        $modules = self::csv($request->query->get('module'), array_keys(PlanChronicle::MODULES));
        $events = self::csv($request->query->get('event'), array_keys(PlanChronicle::EVENT_GROUPS));
        $cursor = $request->query->get('cursor');

        try {
            $rows = $this->chronicle->rows(
                $me,
                $modules,
                $events,
                $request->query->getBoolean('unread'),
                $request->query->getBoolean('favorites'),
                is_string($cursor) && $cursor !== '' ? $cursor : null,
                $limit,
            );
        } catch (InvalidArgumentException) {
            return $this->error('Невірний курсор сторінки.', Response::HTTP_BAD_REQUEST);
        }

        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $subjects = $this->subjectsOf($rows);
        $payloads = array_map(static fn (array $r) => json_decode((string) $r['payload'], true) ?: [], $rows);
        $payloads = $this->liveComments($rows, $payloads);
        $peopleIds = array_column($rows, 'actor_id');

        foreach ($subjects as $s) {
            $peopleIds[] = $s['owner'] ?? null;
        }

        foreach ($payloads as $p) {
            array_push($peopleIds, ...array_map('intval', (array) ($p['userIds'] ?? [])), ...array_map('intval', (array) ($p['removedIds'] ?? [])));
        }

        $people = $this->people($peopleIds);
        $items = [];

        foreach ($rows as $i => $row) {
            $ref = SubjectRef::of((string) $row['subject_type'], (int) $row['subject_id']);
            $subject = $subjects[$ref->key()] ?? ['title' => $this->subjects->title($ref) ?? '—', 'number' => null, 'status' => null, 'client' => null, 'owner' => null];
            $module = PlanChronicle::moduleOf($ref->type->value);
            $type = (string) $row['event_type'];

            $items[] = [
                'key' => $ref->key(),
                'subject' => $ref->toArray(),
                'module' => $module,
                'moduleLabel' => self::MODULE_LABELS[$module] ?? $module,
                'number' => $subject['number'],
                'title' => $subject['title'],
                'status' => $subject['status'],
                'statusLabel' => $subject['status'] !== null ? SearchCatalog::statusLabel($subject['status']) : null,
                'closed' => SearchCatalog::isClosed($subject['status']),
                'client' => $subject['client'] !== '' ? $subject['client'] : null,
                'owner' => $subject['owner'] !== null ? ($people[(int) $subject['owner']] ?? null) : null,
                'url' => $ref->type === SubjectType::Task ? '/crm/tasks/chronicle/' . $ref->id : $this->subjects->url($ref),
                'event' => [
                    'id' => (int) $row['last_event_id'],
                    'type' => $type,
                    'group' => self::group($type),
                    'author' => $row['actor_id'] !== null ? ($people[(int) $row['actor_id']] ?? null) : null,
                    'text' => mb_substr($this->line($ref, $type, $payloads[$i], $people, $subject['body'] ?? ''), 0, 300),
                    'at' => self::atom((string) $row['occurred_at']),
                ],
                'unread' => (int) $row['unread'],
                'favorite' => (bool) $row['favorite'],
                'lastActivityAt' => self::atom((string) $row['last_at']),
            ];
        }

        $last = end($rows);

        return $this->json([
            'items' => $items,
            'next' => $more && $last !== false ? PlanChronicle::cursor((string) $last['last_at'], (int) $last['last_event_id']) : null,
            'counts' => $this->chronicle->counts($me),
            'modules' => array_map(static fn (string $m) => ['key' => $m, 'label' => self::MODULE_LABELS[$m]], $this->chronicle->modulesFor($me)),
        ]);
    }

    #[Route('/counts', name: 'api_plan_chronicle_counts', methods: ['GET'])]
    public function counts(): JsonResponse
    {
        return $this->json($this->chronicle->counts($this->me()));
    }

    #[Route('/read-all', name: 'api_plan_chronicle_read_all', methods: ['POST'])]
    public function readAll(): JsonResponse
    {
        $me = $this->me();
        $this->chronicle->readAll($me);

        return $this->json($this->chronicle->counts($me));
    }

    #[Route('/{key}/read', name: 'api_plan_chronicle_read', methods: ['POST'], requirements: ['key' => '[a-z_]{1,16}-\d{1,10}'])]
    public function read(string $key): JsonResponse
    {
        $me = $this->me();

        try {
            $ref = SubjectRef::fromKey($key);
        } catch (InvalidArgumentException) {
            return $this->error('Невідомий об\'єкт.', Response::HTTP_NOT_FOUND);
        }

        if (! in_array($ref->type->value, PlanChronicle::subjectTypes(), true) || ! $this->subjects->visibleTo($ref, $me)) {
            return $this->error('Не знайдено.', Response::HTTP_NOT_FOUND);
        }

        if ($ref->type === SubjectType::Task) {
            $task = $this->em->find(Task::class, $ref->id);

            if ($task !== null && $this->policy->canView($me, $task)) {
                $this->tasks->markRead($task, $me);
            }
        } else {
            $this->chronicle->markRead($me, $ref);
        }

        return $this->json($this->chronicle->counts($me));
    }

    /**
     * Назва, номер, статус, контрагент і «власник» суб'єктів сторінки — пачкою.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array{title: string, number: ?string, status: ?string, client: ?string, owner: ?int, body?: string}>
     */
    private function subjectsOf(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids[(string) $row['subject_type']][] = (int) $row['subject_id'];
        }

        $out = [];
        $in = static fn (array $list) => [$list];
        $type = [ArrayParameterType::INTEGER];

        if (isset($ids['task'])) {
            foreach ($this->db->fetchAllAssociative('SELECT id, title, status, client, creator_id FROM task WHERE id IN (?)', $in($ids['task']), $type) as $r) {
                $out['task-' . $r['id']] = ['title' => (string) $r['title'], 'number' => sprintf('%04d', (int) $r['id']), 'status' => 'task:' . $r['status'], 'client' => $r['client'] !== null ? (string) $r['client'] : null, 'owner' => (int) $r['creator_id']];
            }
        }

        if (isset($ids['supply'])) {
            foreach ($this->db->fetchAllAssociative('SELECT id, number, item, status, site, author_id FROM supply_request WHERE id IN (?)', $in($ids['supply']), $type) as $r) {
                $out['supply-' . $r['id']] = ['title' => (string) $r['item'], 'number' => (string) $r['number'], 'status' => 'supply:' . $r['status'], 'client' => $r['site'] !== null ? (string) $r['site'] : null, 'owner' => (int) $r['author_id']];
            }
        }

        // Склад — з пошукового індексу (там уже зібрані підписи); немає — SubjectRegistry.
        $warehouse = [];

        foreach (['wh_item', 'wh_movement', 'wh_client', 'wh_site'] as $t) {
            foreach ($ids[$t] ?? [] as $id) {
                $warehouse[] = $t . ':' . $id;
            }
        }

        if ($warehouse !== []) {
            foreach ($this->db->fetchAllAssociative(
                "SELECT doc_type, ref_id, title, body, status, client, author_id FROM search_document WHERE doc_type || ':' || ref_id IN (?)",
                [$warehouse],
                [ArrayParameterType::STRING],
            ) as $r) {
                $out[$r['doc_type'] . '-' . $r['ref_id']] = ['title' => (string) $r['title'], 'number' => null, 'status' => $r['status'] !== null ? (string) $r['status'] : null, 'client' => (string) $r['client'], 'owner' => $r['author_id'] !== null ? (int) $r['author_id'] : null, 'body' => (string) $r['body']];
            }
        }

        return $out;
    }

    /**
     * Коментар задачі з журналу міг бути змінений чи видалений — показуємо живий текст
     * (видалене не має «світитись» у Хроніці, як і в старій стрічці).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $payloads
     *
     * @return list<array<string, mixed>>
     */
    private function liveComments(array $rows, array $payloads): array
    {
        $ids = [];

        foreach ($rows as $i => $row) {
            if ($row['subject_type'] === 'task' && $row['event_type'] === CommentAdded::TYPE && isset($payloads[$i]['refId'])) {
                $ids[$i] = (int) $payloads[$i]['refId'];
            }
        }

        if ($ids === []) {
            return $payloads;
        }

        $live = [];

        foreach ($this->db->fetchAllAssociative('SELECT id, text, deleted_at FROM task_message WHERE id IN (?)', [array_values($ids)], [ArrayParameterType::INTEGER]) as $m) {
            $live[(int) $m['id']] = $m['deleted_at'] !== null ? null : (string) $m['text'];
        }

        foreach ($ids as $i => $id) {
            $text = $live[$id] ?? null;
            $payloads[$i]['excerpt'] = $text !== null ? mb_substr($text, 0, 200) : '';
            $payloads[$i]['deleted'] = $text === null;
        }

        return $payloads;
    }

    private static function group(string $type): string
    {
        return match ($type) {
            CommentAdded::TYPE => 'comment',
            FileAdded::TYPE => 'file',
            StatusChanged::TYPE => 'status',
            SubjectClosed::TYPE => 'closed',
            ApprovalRequested::TYPE => 'approval',
            SubjectCreated::TYPE, ItemCreated::TYPE => 'created',
            MemberAdded::TYPE, MemberRemoved::TYPE => 'members',
            PurchaseRecorded::TYPE => 'purchase',
            StockMoved::TYPE => 'stock',
            default => 'updated',
        };
    }

    /**
     * Один рядок про подію — з payload журналу, без звернень до модулів
     * (рух складу — що й куди, з документа пошуку; назву вже показує заголовок).
     *
     * @param array<string, mixed> $p
     * @param array<int, array<string, mixed>> $people
     */
    private function line(SubjectRef $ref, string $type, array $p, array $people, string $body = ''): string
    {
        $names = static fn (array $ids) => implode(', ', array_filter(array_map(static fn ($id) => $people[(int) $id]['name'] ?? null, $ids)));
        $status = static fn (?string $value) => match (true) {
            $value === null => '—',
            $ref->type === SubjectType::Task => TaskStatus::tryFrom($value)?->label() ?? $value,
            $ref->type === SubjectType::Supply => SupplyStatus::tryFrom($value)?->label() ?? $value,
            default => $value,
        };

        switch ($type) {
            case CommentAdded::TYPE:
                if ((bool) ($p['deleted'] ?? false)) {
                    return 'Повідомлення видалено';
                }

                $files = (int) ($p['files'] ?? 0);

                return trim((string) ($p['excerpt'] ?? '')) . ($files > 0 ? ' · 📎 ' . $files : '');

            case FileAdded::TYPE:
                $list = array_map('strval', (array) ($p['names'] ?? []));

                return '📎 ' . implode(', ', array_slice($list, 0, 3)) . (count($list) > 3 ? ' +' . (count($list) - 3) : '');

            case StatusChanged::TYPE:
            case SubjectClosed::TYPE:
            case ApprovalRequested::TYPE:
                $line = ($type === ApprovalRequested::TYPE ? 'На погодження: ' : 'Статус: ')
                    . $status(isset($p['from']) ? (string) $p['from'] : null) . ' → ' . $status((string) ($p['to'] ?? ''));
                $comment = trim((string) ($p['comment'] ?? ''));

                return $comment !== '' ? $line . ' — ' . $comment : $line;

            case SubjectCreated::TYPE:
                return match ($ref->type) {
                    SubjectType::Task => 'Створено задачу',
                    SubjectType::Supply => 'Нова заявка на постачання',
                    default => 'Створено',
                };

            case ItemCreated::TYPE:
                return 'Нова позиція';

            case MemberAdded::TYPE:
                return 'Додано учасників: ' . $names((array) ($p['userIds'] ?? []));

            case MemberRemoved::TYPE:
                return 'Прибрано учасників: ' . $names((array) ($p['userIds'] ?? []));

            case PurchaseRecorded::TYPE:
                return ((bool) ($p['updated'] ?? false) ? 'Закупівлю змінено' : 'Закупівля') . ': ' . implode(' · ', array_filter([(string) ($p['supplier'] ?? ''), (string) ($p['total'] ?? '')]));

            case StockMoved::TYPE:
                $movement = MovementType::tryFrom((string) ($p['movementType'] ?? ''))?->label() ?? 'Рух';

                return trim($body) !== '' ? $movement . ': ' . $body : $movement;

            case SubjectUpdated::TYPE:
                $summary = trim((string) ($p['summary'] ?? ''));

                return $summary !== '' ? $summary : 'Оновлено';
        }

        return $type;
    }

    /**
     * @param array<int, mixed> $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function people(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];

        foreach ($ids === [] ? [] : $this->users->findBy(['id' => $ids]) as $user) {
            $out[(int) $user->getId()] = $this->presenter->person($user);
        }

        return $out;
    }

    /**
     * `a,b` або `a` → лише відомі значення.
     *
     * @param list<string> $known
     *
     * @return list<string>
     */
    private static function csv(mixed $value, array $known): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $out = array_values(array_intersect(array_unique(array_map('trim', explode(',', $value))), $known));

        // Лише невідомі значення — «нічого», а не «усе».
        return $out === [] ? ['-'] : $out;
    }

    private static function atom(string $value): string
    {
        return new DateTime($value, new DateTimeZone('Europe/Kyiv'))->format(DateTimeInterface::ATOM);
    }
}
