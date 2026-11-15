<?php

namespace App\Tasks\Controller;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Exception\TaskException;
use App\Tasks\Repository\TaskFeedRepository;
use App\Tasks\Repository\TaskMessageRepository;
use App\Tasks\Search\Snippet;
use App\Tasks\Search\TaskSearch;
use App\Tasks\Service\TaskPolicy;
use App\Tasks\Service\TaskPresenter;
use App\Tasks\Service\TaskService;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Стрічка «Плану»: Хроніка (події як вхідні), дії з повідомленням
 * (правка, закріплення, ★), ★ задачі, «прочитано» і глобальний пошук.
 * Права — у TaskPolicy; чужих задач тут не видно так само, як на дошці.
 */
#[Route('/api/tasks')]
class TaskFeedController extends TasksController
{
    private const int PAGE = 30;

    public function __construct(
        private TaskFeedRepository $feed,
        private TaskMessageRepository $messages,
        private TaskPresenter $presenter,
        private TaskPolicy $policy,
        private TaskService $service,
        private TaskSearch $search,
        private TelegramUserRepository $users,
    ) {
    }

    /** Хроніка: ?unread=1 — лише з новим, ?favorites=1 — лише ★, ?cursor= — наступна сторінка. */
    #[Route('/chronicle', name: 'api_tasks_chronicle', methods: ['GET'])]
    public function chronicle(Request $request): JsonResponse
    {
        $me = $this->me();
        $limit = min(100, max(5, $request->query->getInt('limit', self::PAGE)));

        try {
            $rows = $this->feed->chronicle(
                $me,
                $request->query->getBoolean('unread'),
                $request->query->getBoolean('favorites'),
                $request->query->get('cursor') !== null ? (string) $request->query->get('cursor') : null,
                $limit,
            );
        } catch (InvalidArgumentException) {
            return $this->error('Невірний курсор сторінки.', Response::HTTP_BAD_REQUEST);
        }

        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $people = $this->people(array_merge(array_column($rows, 'creator_id'), array_column($rows, 'msg_author')));
        $files = $this->feed->filesOf(array_values(array_filter(array_map('intval', array_column($rows, 'msg_id')))));

        $items = array_map(function (array $row) use ($people, $files) {
            $status = TaskStatus::from((string) $row['status']);
            $msgId = $row['msg_id'] !== null ? (int) $row['msg_id'] : null;
            $msgFiles = $msgId !== null ? ($files[$msgId] ?? []) : [];
            $kind = (string) ($row['msg_kind'] ?? '');
            $text = (string) ($row['msg_text'] ?? '');
            $type = $kind === TaskMessage::KIND_EVENT
                ? ((string) ($row['msg_event'] ?? '') ?: TaskMessage::EVENT_UPDATED)
                : ($msgFiles !== [] && trim($text) === '' ? 'file' : 'comment');

            return [
                'task' => [
                    'id' => (int) $row['id'],
                    'number' => sprintf('%04d', (int) $row['id']),
                    'title' => (string) $row['title'],
                    'client' => $row['client'],
                    'status' => $status->value,
                    'statusLabel' => $status->label(),
                    'closed' => $status->isClosed(),
                    'creator' => $people[(int) $row['creator_id']] ?? null,
                ],
                'event' => $msgId === null ? null : [
                    'id' => $msgId,
                    'type' => $type,
                    'author' => $row['msg_author'] !== null ? ($people[(int) $row['msg_author']] ?? null) : null,
                    'text' => mb_substr($text, 0, 300),
                    'files' => array_slice($msgFiles, 0, 5),
                    'filesCount' => count($msgFiles),
                    'at' => self::atom((string) $row['msg_at']),
                ],
                'unread' => (int) $row['unread'],
                'favorite' => (bool) $row['favorite'],
                'lastActivityAt' => self::atom((string) $row['last_activity_at']),
            ];
        }, $rows);

        $last = end($rows);

        return $this->json([
            'items' => $items,
            'next' => $more && $last !== false ? TaskFeedRepository::cursor((string) $last['last_activity_at'], (int) $last['id']) : null,
            'counts' => $this->feed->unreadCounts($me),
        ]);
    }

    /** Лічильник для бейджа: опитується раз на 15 с. */
    #[Route('/chronicle/counts', name: 'api_tasks_chronicle_counts', methods: ['GET'])]
    public function counts(): JsonResponse
    {
        return $this->json($this->feed->unreadCounts($this->me()));
    }

    #[Route('/chronicle/read-all', name: 'api_tasks_chronicle_read_all', methods: ['POST'])]
    public function readAll(): JsonResponse
    {
        $me = $this->me();
        $this->feed->readAll($me);

        return $this->json($this->feed->unreadCounts($me));
    }

    /** «Прочитано» зі списку, не відкриваючи задачу. */
    #[Route('/{id}/read', name: 'api_tasks_read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function read(Task $task): JsonResponse
    {
        $me = $this->guard($task);
        $this->service->markRead($task, $me);

        return $this->json($this->feed->unreadCounts($me));
    }

    #[Route('/{id}/favorite', name: 'api_tasks_favorite', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function favorite(Task $task, Request $request): JsonResponse
    {
        $me = $this->guard($task);
        $on = (bool) ($this->payload($request)['on'] ?? true);

        try {
            $this->service->favorite($task, $me, $on);
        } catch (TaskException $e) {
            return $this->error($e);
        }

        return $this->json(['favorite' => $on]);
    }

    #[Route('/{id}/messages/{messageId}', name: 'api_tasks_message_edit', methods: ['PATCH'], requirements: ['id' => '\d+', 'messageId' => '\d+'])]
    public function edit(Task $task, int $messageId, Request $request): JsonResponse
    {
        [$me, $message] = $this->message($task, $messageId);

        try {
            $this->service->editMessage($message, $me, (string) ($this->payload($request)['text'] ?? ''));
        } catch (TaskException $e) {
            return $this->error($e);
        }

        return $this->json($this->presenter->message($message, $me, $this->feed->starredOf($me, [$messageId])));
    }

    #[Route('/{id}/messages/{messageId}/pin', name: 'api_tasks_message_pin', methods: ['POST'], requirements: ['id' => '\d+', 'messageId' => '\d+'])]
    public function pin(Task $task, int $messageId, Request $request): JsonResponse
    {
        [$me, $message] = $this->message($task, $messageId);

        try {
            $this->service->pin($message, $me, (bool) ($this->payload($request)['on'] ?? true));
        } catch (TaskException $e) {
            return $this->error($e);
        }

        return $this->json($this->presenter->message($message, $me, $this->feed->starredOf($me, [$messageId])));
    }

    #[Route('/{id}/messages/{messageId}/star', name: 'api_tasks_message_star', methods: ['POST'], requirements: ['id' => '\d+', 'messageId' => '\d+'])]
    public function star(Task $task, int $messageId, Request $request): JsonResponse
    {
        [$me, $message] = $this->message($task, $messageId);

        try {
            $this->service->star($message, $me, (bool) ($this->payload($request)['on'] ?? true));
        } catch (TaskException $e) {
            return $this->error($e);
        }

        return $this->json($this->presenter->message($message, $me, $this->feed->starredOf($me, [$messageId])));
    }

    /**
     * Глобальний пошук. ?q= (від 2 символів), ?tab=all|tasks|comments|files|people,
     * ?starred=1 — лише ★, ?offset= — наступна сторінка вкладки.
     */
    #[Route('/search', name: 'api_tasks_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $me = $this->me();
        $q = mb_substr(trim((string) $request->query->get('q', '')), 0, 120);
        $tab = (string) $request->query->get('tab', 'all');
        $starred = $request->query->getBoolean('starred');
        $offset = max(0, $request->query->getInt('offset'));
        $terms = mb_strlen($q) >= 2 ? TaskSearch::terms($q) : [];

        if (! in_array($tab, ['all', ...TaskSearch::GROUPS], true)) {
            return $this->error('Невідома вкладка пошуку.');
        }

        $groups = $tab === 'all' ? TaskSearch::GROUPS : [$tab];
        $limit = $tab === 'all' ? 5 : 20;
        // Лічильники — лише для першої сторінки: догортання їх не змінює.
        $out = ['q' => $q, 'counts' => $offset === 0 ? $this->search->counts($me, $terms, $starred) : null];

        foreach ($groups as $group) {
            $rows = $this->search->find($me, $group, $terms, $starred, $limit, $tab === 'all' ? 0 : $offset);
            $out[$group] = $this->searchItems($group, $rows, $terms);
        }

        return $this->json($out);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $terms
     *
     * @return list<array<string, mixed>>
     */
    private function searchItems(string $group, array $rows, array $terms): array
    {
        $people = $group === 'comments' ? $this->people(array_column($rows, 'author_id')) : [];

        return array_map(function (array $row) use ($group, $terms, $people) {
            switch ($group) {
                case 'tasks':
                    $status = TaskStatus::from((string) $row['status']);
                    $titleHit = self::hits((string) $row['title'], $terms);
                    $source = $titleHit ? (string) ($row['description'] ?? '') : trim(($row['client'] ?? '') . ' · ' . ($row['description'] ?? ''), ' ·');

                    return [
                        'id' => (int) $row['id'],
                        'number' => sprintf('%04d', (int) $row['id']),
                        'title' => Snippet::mark((string) $row['title'], $terms),
                        'client' => $row['client'],
                        'snippet' => Snippet::around($source, $terms),
                        'status' => $status->value,
                        'statusLabel' => $status->label(),
                        'closed' => $status->isClosed(),
                        'favorite' => (bool) $row['favorite'],
                        'at' => self::atom((string) $row['last_activity_at']),
                    ];

                case 'comments':
                    $status = TaskStatus::from((string) $row['status']);

                    return [
                        'id' => (int) $row['id'],
                        'taskId' => (int) $row['task_id'],
                        'taskNumber' => sprintf('%04d', (int) $row['task_id']),
                        'taskTitle' => (string) $row['title'],
                        'closed' => $status->isClosed(),
                        'author' => $row['author_id'] !== null ? ($people[(int) $row['author_id']] ?? null) : null,
                        'snippet' => Snippet::around((string) $row['text'], $terms),
                        'starred' => (bool) $row['starred'],
                        'at' => self::atom((string) $row['created_at']),
                    ];

                case 'files':
                    $status = TaskStatus::from((string) $row['status']);

                    return [
                        'id' => (int) $row['id'],
                        'name' => Snippet::mark((string) $row['original_name'], $terms),
                        'plainName' => (string) $row['original_name'],
                        'kind' => (string) $row['kind'],
                        'sizeLabel' => TaskPresenter::size((int) $row['size']),
                        'thumb' => $row['preview_state'] === 'ready' ? '/api/tasks/files/' . (int) $row['id'] . '/thumb' : null,
                        'taskId' => (int) $row['task_id'],
                        'taskNumber' => sprintf('%04d', (int) $row['task_id']),
                        'taskTitle' => (string) $row['title'],
                        'closed' => $status->isClosed(),
                        'at' => self::atom((string) $row['created_at']),
                    ];

                default:
                    /** @var TelegramUser $user */
                    $user = $row['user'];

                    return [
                        ...$this->presenter->person($user),
                        'nameMarked' => Snippet::mark($user->shortName(), $terms),
                    ];
            }
        }, $rows);
    }

    /** @param list<string> $terms */
    private static function hits(string $text, array $terms): bool
    {
        return array_any($terms, fn ($term) => mb_stripos($text, $term) !== false);
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

    /** @return array{0: TelegramUser, 1: TaskMessage} */
    private function message(Task $task, int $messageId): array
    {
        $me = $this->guard($task);
        $message = $this->messages->find($messageId);

        if ($message === null || $message->getTask()->getId() !== $task->getId()) {
            throw $this->createNotFoundException('Повідомлення не знайдено.');
        }

        return [$me, $message];
    }

    private function guard(Task $task): TelegramUser
    {
        $me = $this->me();

        if (! $this->policy->canView($me, $task)) {
            throw $this->createAccessDeniedException('Ви не учасник цієї задачі.');
        }

        return $me;
    }

    /** Час із бази (стіна Києва, без поясу) → ISO з поясом, як решта API. */
    private static function atom(string $value): string
    {
        return new DateTime($value, new DateTimeZone('Europe/Kyiv'))->format(DateTimeInterface::ATOM);
    }
}
