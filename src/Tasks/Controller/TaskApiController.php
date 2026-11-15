<?php

namespace App\Tasks\Controller;

use App\Entity\TelegramUser;
use App\Plan\Chronicle\PlanChronicle;
use App\Plan\Overview\DirectorOverview;
use App\Plan\Overview\PlanOverview;
use App\Plan\Telegram\PlanBotMode;
use App\Repository\TelegramUserRepository;
use App\Supply\Repository\DepartmentRepository;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Enum\WorkRole;
use App\Tasks\Exception\TaskException;
use App\Tasks\Repository\TaskAttachmentRepository;
use App\Tasks\Repository\TaskFeedRepository;
use App\Tasks\Repository\TaskLabelRepository;
use App\Tasks\Repository\TaskMessageRepository;
use App\Tasks\Repository\TaskRepository;
use App\Tasks\Service\TaskPolicy;
use App\Tasks\Service\TaskPresenter;
use App\Tasks\Service\TaskService;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** API задач: огляд, дошка, картка, чат. Логіка — у TaskService і TaskPolicy. */
#[Route('/api/tasks')]
class TaskApiController extends TasksController
{
    private const int LIST_LIMIT = 200;

    public function __construct(
        private TaskRepository $tasks,
        private TaskMessageRepository $messages,
        private TaskPresenter $presenter,
        private TaskPolicy $policy,
        private TaskService $service,
        private TaskAttachmentRepository $attachments,
        private TaskFeedRepository $feed,
    ) {
    }

    /** Довідники для форм: статуси, мітки, люди, відділи, ролі. */
    #[Route('/meta', name: 'api_tasks_meta', methods: ['GET'])]
    public function meta(TaskLabelRepository $labels, TelegramUserRepository $users, DepartmentRepository $departments, PlanBotMode $planBot): JsonResponse
    {
        $me = $this->me();
        $people = array_values(array_filter(
            $users->findBy([], ['first_name' => 'ASC']),
            static fn (TelegramUser $u) => ! $u->isArchived() && $u->isApproved(),
        ));

        return $this->json([
            'me' => [
                ...$this->presenter->person($me),
                'can' => [
                    'staff' => $me->managesStaff(),
                    'seeAll' => $me->seesAllTasks(),
                    'decide' => $me->getWorkRole()->decides(),
                ],
                // «План»: вхід лише через Telegram — поля пароля в картці співробітника немає.
                'passwordLogin' => ! $planBot->enabled(),
            ],
            'statuses' => array_map($this->presenter->status(...), TaskStatus::cases()),
            'labels' => array_map($this->presenter->label(...), $labels->ordered()),
            'people' => array_map($this->presenter->person(...), $people),
            'departments' => array_map(static fn ($d) => [
                'id' => $d->getId(),
                'name' => $d->getName(),
                'people' => array_values(array_map(
                    static fn (TelegramUser $u) => $u->getId(),
                    array_filter($people, static fn (TelegramUser $u) => $u->getDepartment()?->getId() === $d->getId()),
                )),
            ], $departments->findActive()),
            'roles' => array_map(static fn (WorkRole $r) => ['value' => $r->value, 'label' => $r->label()], WorkRole::cases()),
        ]);
    }

    /** «Мій день»: мої відкриті, прострочені, мого відділу, остання активність. */
    #[Route('/dashboard', name: 'api_tasks_dashboard', methods: ['GET'])]
    public function dashboard(PlanOverview $overview, PlanChronicle $chronicle): JsonResponse
    {
        $me = $this->me();

        // Кожна задача — в одному блоці, у порядку важливості: прострочені →
        // мої → відділ → остання активність (раніше одна задача стояла в 3–4 блоках).
        $seen = [];
        $take = static function (array $tasks, int $limit) use (&$seen): array {
            $out = [];

            foreach ($tasks as $task) {
                $id = (int) $task->getId();

                if (isset($seen[$id]) || count($out) >= $limit) {
                    continue;
                }

                $seen[$id] = true;
                $out[] = $task;
            }

            return $out;
        };

        $overdue = $take($this->tasks->search($me, ['overdue' => true], 20, 'due'), 8);
        $mine = $take($this->tasks->search($me, ['member' => $me, 'open' => true], 20, 'due'), 8);
        $department = $me->getDepartment() !== null
            ? $take($this->tasks->search($me, ['department' => $me->getDepartment()->getId(), 'open' => true], 20), 6)
            : [];
        $recent = $take($this->tasks->search($me, [], 16), 6);

        $all = array_merge($overdue, $mine, $department, $recent);
        $ids = array_map(static fn (Task $t) => (int) $t->getId(), $all);
        $unread = $this->tasks->unreadCounts($me, $ids);
        $last = $this->messages->lastComments($ids);
        $files = $this->attachments->statsByTask($ids);
        $item = fn (Task $t) => $this->presenter->listItem($t, $unread, $last, $files);

        // Лічильники — одним агрегатним запитом; «з новим» — те саме число, що біля «Хроніки».
        $stats = $this->tasks->dashboardStats($me);
        $news = $chronicle->counts($me);

        return self::compact($this->json([
            'stats' => [
                'mine' => $stats['mine'],
                'overdue' => $stats['overdue'],
                'open' => $stats['open'],
                'unread' => $news['tasks'],
                'unreadEvents' => $news['events'],
                'byStatus' => $stats['byStatus'],
            ],
            'mine' => array_map($item, $mine),
            'overdue' => array_map($item, $overdue),
            'department' => array_map($item, $department),
            'departmentName' => $me->getDepartment()?->getName(),
            'recent' => array_map($item, $recent),
            'supply' => $overview->supply($me),
            'warehouse' => $overview->warehouse($me),
        ]));
    }

    /**
     * Дошка: Хроніка · Мої повідомлення · Нова заявка · В роботі · Виконана
     * (колонки — за відповіддю клієнта 13.11.2026).
     */
    #[Route('/board', name: 'api_tasks_board', methods: ['GET'])]
    public function board(Request $request): JsonResponse
    {
        $me = $this->me();
        $filters = [
            'q' => $request->query->get('q') ?: null,
            'label' => $request->query->getInt('label') ?: null,
        ];

        $columns = [
            'chronicle' => $this->tasks->search($me, [...$filters, 'ids' => $this->tasks->openedBy($me)], 30),
            'messages' => $this->tasks->search($me, [...$filters, 'ids' => $this->tasks->myMessages($me)], 30),
            'new' => $this->tasks->search($me, [...$filters, 'status' => [TaskStatus::New]], 60),
            'work' => $this->tasks->search($me, [...$filters, 'status' => [TaskStatus::InWork, TaskStatus::Approval, TaskStatus::Approved, TaskStatus::Waiting]], 60, 'due'),
            'done' => $this->tasks->search($me, [...$filters, 'status' => [TaskStatus::Done, TaskStatus::Rejected]], 30),
        ];

        $ids = [];

        foreach ($columns as $tasks) {
            foreach ($tasks as $task) {
                $ids[] = (int) $task->getId();
            }
        }

        $ids = array_values(array_unique($ids));
        $unread = $this->tasks->unreadCounts($me, $ids);
        $last = $this->messages->lastComments($ids);
        $files = $this->attachments->statsByTask($ids);

        // Та сама задача в кількох колонках — одна презентація; кирилиця без \uXXXX
        // (DB-perf #4: відповідь дошки була 0.5–0.6 МБ, удвічі більша через екранування).
        $memo = [];
        $item = function (Task $t) use (&$memo, $unread, $last, $files): array {
            return $memo[(int) $t->getId()] ??= $this->presenter->listItem($t, $unread, $last, $files);
        };

        return self::compact($this->json(array_map(static fn (array $tasks) => array_map($item, $tasks), $columns)));
    }

    /**
     * Список задач під фільтром — куди ведуть числа «Огляду»: ?f=open|overdue|stale|mine
     * і ?dep=<id відділу>|none. Ті самі правила, що й у лічильниках (TaskRepository),
     * у межах видимості людини.
     */
    #[Route('/list', name: 'api_tasks_list', methods: ['GET'])]
    public function list(Request $request, DepartmentRepository $departments): JsonResponse
    {
        $me = $this->me();
        $f = (string) $request->query->get('f', 'open');
        $filters = match ($f) {
            'overdue' => ['overdue' => true],
            'stale' => ['stale' => DirectorOverview::STALE_DAYS],
            'mine' => ['member' => $me, 'open' => true],
            default => ['open' => true],
        };
        $f = in_array($f, ['overdue', 'stale', 'mine'], true) ? $f : 'open';

        $dep = (string) $request->query->get('dep', '');
        $department = null;

        if ($dep === 'none') {
            $filters['taggedDepartment'] = TaskRepository::DEPARTMENT_NONE;
        } elseif (ctype_digit($dep) && (int) $dep > 0) {
            $department = $departments->find((int) $dep);

            if ($department === null) {
                return $this->error('Такого відділу немає.', Response::HTTP_NOT_FOUND);
            }

            $filters['taggedDepartment'] = (int) $department->getId();
        } else {
            $dep = '';
        }

        $tasks = $this->tasks->search($me, $filters, self::LIST_LIMIT, $f === 'stale' ? 'stale' : 'due');
        $ids = array_map(static fn (Task $t) => (int) $t->getId(), $tasks);
        $unread = $this->tasks->unreadCounts($me, $ids);
        $last = $this->messages->lastComments($ids);
        $files = $this->attachments->statsByTask($ids);

        return self::compact($this->json([
            'items' => array_map(fn (Task $t) => $this->presenter->listItem($t, $unread, $last, $files), $tasks),
            'total' => count($tasks) < self::LIST_LIMIT ? count($tasks) : $this->tasks->countMatching($me, $filters),
            'limit' => self::LIST_LIMIT,
            'filter' => [
                'f' => $f,
                'dep' => $dep,
                'departmentName' => $dep === 'none' ? 'Без відділу' : $department?->getName(),
                'staleDays' => DirectorOverview::STALE_DAYS,
                'all' => $me->seesAllTasks(),
            ],
        ]));
    }

    /** JSON без екранування кирилиці — у 2–3 рази менше байтів для українського тексту. */
    private static function compact(JsonResponse $response): JsonResponse
    {
        return $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE);
    }

    #[Route('', name: 'api_tasks_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $me = $this->me();

        try {
            $task = $this->service->create($me, $this->payload($request));
        } catch (TaskException $e) {
            return $this->error($e);
        }

        return $this->json($this->detail($task, $me), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_tasks_view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function view(Task $task): JsonResponse
    {
        $me = $this->guard($task);
        $readUpTo = $this->service->markRead($task, $me);

        return $this->json($this->presenter->detail($task, $me, $this->messages->forTask($task), $readUpTo));
    }

    #[Route('/{id}', name: 'api_tasks_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(Task $task, Request $request): JsonResponse
    {
        $me = $this->guard($task);

        try {
            $this->service->update($task, $me, $this->payload($request));
        } catch (TaskException $e) {
            return $this->error($e);
        }

        return $this->json($this->detail($task, $me));
    }

    #[Route('/{id}/status', name: 'api_tasks_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function status(Task $task, Request $request): JsonResponse
    {
        $me = $this->guard($task);
        $payload = $this->payload($request);
        $to = TaskStatus::tryFrom((string) ($payload['to'] ?? ''));

        if ($to === null) {
            return $this->error('Невідомий статус.');
        }

        try {
            $this->service->changeStatus($task, $me, $to, isset($payload['comment']) ? (string) $payload['comment'] : null);
        } catch (TaskException $e) {
            return $this->error($e);
        }

        return $this->json($this->detail($task, $me));
    }

    /** Нові повідомлення після after — чат опитує раз на кілька секунд. */
    #[Route('/{id}/messages', name: 'api_tasks_messages', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function messages(Task $task, Request $request): JsonResponse
    {
        $me = $this->guard($task);
        // «now» — ДО вибірок і з запасом у секунду (стовпці TIMESTAMP(0)): зміна,
        // що встигла між запитами, прийде наступного разу, а не загубиться.
        $now = Task::now()->modify('-1 second');
        $after = max(0, $request->query->getInt('after'));
        $messages = $this->messages->forTask($task, $after);

        // Вкладення, чиє превʼю ще готується, — щоб чат замінив заглушку на мініатюру.
        $pending = array_filter(array_map('intval', (array) $request->query->all('pending')));
        $updated = [];

        foreach ($pending as $id) {
            $attachment = $this->em->find(TaskAttachment::class, $id);

            if ($attachment !== null && $attachment->getTask()->getId() === $task->getId()) {
                $updated[] = $this->presenter->attachment($attachment);
            }
        }

        if ($messages !== []) {
            // Прочитане — лише те, що віддаємо, а не те, що могло зʼявитись після вибірки.
            $this->service->markRead($task, $me, max(array_map(static fn (TaskMessage $m) => (int) $m->getId(), $messages)));
        }

        // Правки, видалення й закріплення вже показаних повідомлень — з моменту
        // попереднього опитування (сервер віддає свій «now», клієнт повертає його).
        $changed = [];
        $since = DateTime::createFromFormat(DateTimeInterface::ATOM, (string) $request->query->get('changedSince'));

        if ($since !== false && $after > 0) {
            $changed = $this->messages->changedSince($task, $since->setTimezone(new DateTimeZone('Europe/Kyiv')), $after);
        }

        $all = [...$messages, ...$changed];
        $starred = $this->feed->starredOf($me, array_map(static fn (TaskMessage $m) => (int) $m->getId(), $all));

        return $this->json([
            'messages' => array_map(fn (TaskMessage $m) => $this->presenter->message($m, $me, $starred), $messages),
            'changed' => array_map(fn (TaskMessage $m) => $this->presenter->message($m, $me, $starred), $changed),
            'attachments' => $updated,
            'status' => $task->getStatus()->value,
            'reads' => $this->feed->reads($task),
            'now' => $now->format(DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}/messages', name: 'api_tasks_post', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function post(Task $task, Request $request): JsonResponse
    {
        $me = $this->guard($task);

        /** @var UploadedFile[] $files */
        $files = array_values(array_filter(
            (array) ($request->files->all()['files'] ?? []),
            static fn ($f) => $f instanceof UploadedFile,
        ));

        $payload = $this->payload($request);

        try {
            $message = $this->service->post(
                $task,
                $me,
                (string) ($payload['text'] ?? ''),
                $files,
                array_map('intval', (array) ($payload['mentions'] ?? [])),
                isset($payload['replyTo']) ? (int) $payload['replyTo'] : null,
            );
        } catch (TaskException $e) {
            return $this->error($e);
        }

        return $this->json($this->presenter->message($message, $me), Response::HTTP_CREATED);
    }

    #[Route('/{id}/messages/{messageId}', name: 'api_tasks_message_delete', methods: ['DELETE'], requirements: ['id' => '\d+', 'messageId' => '\d+'])]
    public function deleteMessage(Task $task, int $messageId): JsonResponse
    {
        $me = $this->guard($task);
        $message = $this->messages->find($messageId);

        if ($message === null || $message->getTask()->getId() !== $task->getId()) {
            return $this->error('Повідомлення не знайдено.', Response::HTTP_NOT_FOUND);
        }

        try {
            $this->service->deleteMessage($message, $me);
        } catch (TaskException $e) {
            return $this->error($e);
        }

        return $this->json($this->presenter->message($message, $me));
    }

    private function guard(Task $task): TelegramUser
    {
        $me = $this->me();

        if (! $this->policy->canView($me, $task)) {
            throw $this->createAccessDeniedException('Ви не учасник цієї задачі.');
        }

        return $me;
    }

    /** @return array<string, mixed> */
    private function detail(Task $task, TelegramUser $me): array
    {
        return $this->presenter->detail($task, $me, $this->messages->forTask($task));
    }
}
