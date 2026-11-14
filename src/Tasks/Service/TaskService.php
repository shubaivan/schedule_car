<?php

namespace App\Tasks\Service;

use App\Entity\TelegramUser;
use App\Plan\Event\CommentAdded;
use App\Plan\Event\CommentDeleted;
use App\Plan\Event\CommentEdited;
use App\Plan\Event\FileAdded;
use App\Plan\Event\MemberAdded;
use App\Plan\Event\MemberRemoved;
use App\Plan\Event\StatusEvent;
use App\Plan\Event\SubjectCreated;
use App\Plan\Event\SubjectUpdated;
use App\Plan\EventStore\PlanEventRecorder;
use App\Plan\Subject\SubjectRef;
use App\Repository\TelegramUserRepository;
use App\Supply\Entity\Department;
use App\Supply\Repository\DepartmentRepository;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Entity\TaskMessageEdit;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Exception\TaskException;
use App\Tasks\Repository\TaskLabelRepository;
use App\Tasks\Repository\TaskMessageRepository;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Усе, що змінює задачу. Контролери лише розбирають запит і кличуть сюди;
 * кожна зміна лишає слід у чаті задачі системним повідомленням і рівно одну
 * подію в журналі «Плану» (plan_event) — у тій самій транзакції. Telegram шле
 * TaskNotificationListener за цією подією, не сервіс.
 */
class TaskService
{
    /** Поля задачі, зміна яких — SubjectUpdated::$fields. */
    private const array FIELDS = ['title', 'description', 'client', 'dueAt', 'labelIds', 'departmentIds', 'memberIds'];

    public function __construct(
        private EntityManagerInterface $em,
        private TaskPolicy $policy,
        private TelegramUserRepository $users,
        private DepartmentRepository $departments,
        private TaskLabelRepository $labels,
        private TaskMessageRepository $messages,
        private AttachmentStorage $storage,
        private PreviewGenerator $previews,
        private DeferredPreviews $deferred,
        private PlanEventRecorder $events,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{title?: mixed, description?: mixed, client?: mixed, dueAt?: mixed, labelIds?: mixed, memberIds?: mixed, departmentIds?: mixed} $data
     */
    public function create(TelegramUser $author, array $data): Task
    {
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            throw new TaskException('Вкажіть назву задачі.');
        }

        $task = new Task(mb_substr($title, 0, 255), $author);
        $this->fill($task, $data);
        // Автор завжди в задачі — інакше він сам її не побачить, якщо не директор.
        $task->addMember($author);

        $this->em->persist($task);
        $created = (new TaskMessage($task, $author, $this->membersLine('створив(ла) задачу', $task), TaskMessage::KIND_EVENT))
            ->setEventType(TaskMessage::EVENT_CREATED);
        $this->em->persist($created);

        $this->events->transactional(function () use ($task, $author, $created): void {
            $this->em->flush();
            $this->events->record(new SubjectCreated(
                SubjectRef::task((int) $task->getId()),
                $author->getId(),
                title: $task->getTitle(),
                refId: $created->getId(),
            ));
        });

        $this->logger->info('Task created', ['task' => $task->getId(), 'by' => $author->getId()]);

        return $task;
    }

    /** @param array<string, mixed> $data */
    public function update(Task $task, TelegramUser $user, array $data): Task
    {
        if (! $this->policy->canEdit($user, $task)) {
            throw new TaskException('Змінювати задачу може автор, менеджер або директор.');
        }

        $before = array_map(static fn ($m) => $m->getUser()->getId(), $task->getMembers()->toArray());

        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);

            if ($title === '') {
                throw new TaskException('Назва не може бути порожньою.');
            }

            $task->setTitle(mb_substr($title, 0, 255));
        }

        $this->fill($task, $data, replaceMembers: true);
        $task->addMember($task->getCreator());

        $added = array_filter(
            $task->getMembers()->toArray(),
            static fn ($m) => ! in_array($m->getUser()->getId(), $before, true),
        );

        $after = array_map(static fn ($m) => $m->getUser()->getId(), $task->getMembers()->toArray());
        $removed = array_values(array_diff($before, $after));
        $ref = SubjectRef::task((int) $task->getId());

        if ($added !== []) {
            $names = implode(', ', array_map(static fn ($m) => $m->getUser()->shortName(), $added));
            $message = $this->event($task, $user, 'додав(ла) учасників: ' . $names, TaskMessage::EVENT_MEMBERS);
            $addedIds = array_values(array_map(static fn ($m) => (int) $m->getUser()->getId(), $added));
            $record = fn () => new MemberAdded($ref, $user->getId(), userIds: $addedIds, refId: $message->getId(), removedIds: array_map('intval', $removed));
        } else {
            $message = $this->event($task, $user, 'оновив(ла) задачу', TaskMessage::EVENT_UPDATED);
            $record = $removed !== []
                ? fn () => new MemberRemoved($ref, $user->getId(), userIds: array_map('intval', $removed), refId: $message->getId())
                : fn () => new SubjectUpdated($ref, $user->getId(), fields: array_values(array_intersect(array_keys($data), self::FIELDS)), refId: $message->getId());
        }

        $this->events->transactional(function () use ($record): void {
            $this->em->flush();
            $this->events->record($record());
        });

        return $task;
    }

    public function changeStatus(Task $task, TelegramUser $user, TaskStatus $to, ?string $comment = null): Task
    {
        // Рядок задачі — під блокуванням, і статус у базі має збігатися з тим, з якого
        // переходимо: два «Погодити»/«Відхилити» одночасно (чи подвійний тап) не запишуть
        // два переходи з того самого «from» — другий отримає «статус щойно змінили».
        $this->events->transactional(function () use ($task, $user, $to, $comment): void {
            $current = $this->em->getConnection()->fetchOne('SELECT status FROM task WHERE id = ? FOR UPDATE', [(int) $task->getId()]);

            if ($current !== false && $current !== $task->getStatus()->value) {
                throw new TaskException(sprintf('Статус задачі щойно змінили: «%s». Оновіть картку.', TaskStatus::tryFrom((string) $current)?->label() ?? $current));
            }

            if (! $this->policy->canMove($user, $task, $to)) {
                throw new TaskException($task->getStatus()->awaitsDecision() || $to->isDecision()
                    ? 'Це рішення приймає директор.'
                    : sprintf('Перехід «%s» → «%s» недоступний.', $task->getStatus()->label(), $to->label()));
            }

            $from = $task->getStatus();
            $task->setStatus($to);
            $message = $this->event($task, $user, sprintf('змінив(ла) статус: %s → %s', $from->label(), $to->label()), $to->isClosed() ? TaskMessage::EVENT_CLOSED : TaskMessage::EVENT_STATUS);

            if ($comment !== null && trim($comment) !== '') {
                $this->em->persist(new TaskMessage($task, $user, trim($comment)));
            }

            $this->em->flush();
            $this->events->record(StatusEvent::for(
                SubjectRef::task((int) $task->getId()),
                $user->getId(),
                $from->value,
                $to->value,
                closes: $to->isClosed(),
                asksApproval: $to === TaskStatus::Approval,
                comment: $comment,
                refId: $message->getId(),
            ));
            $this->logger->info('Task status', ['task' => $task->getId(), 'from' => $from->value, 'to' => $to->value, 'by' => $user->getId()]);
        });

        return $task;
    }

    /**
     * Повідомлення в чат задачі — текст і/або файли.
     *
     * @param UploadedFile[] $files
     * @param int[] $mentions
     */
    public function post(Task $task, TelegramUser $author, string $text, array $files = [], array $mentions = [], ?int $replyToId = null): TaskMessage
    {
        if (! $this->policy->canComment($author, $task)) {
            throw new TaskException('Ви не учасник цієї задачі.');
        }

        $text = trim($text);

        if ($text === '' && $files === []) {
            throw new TaskException('Напишіть повідомлення або додайте файл.');
        }

        if (mb_strlen($text) > 10000) {
            throw new TaskException('Повідомлення задовге.');
        }

        foreach ($files as $file) {
            if (! $file->isValid()) {
                throw new TaskException('Файл не завантажився: ' . $file->getErrorMessage());
            }

            if ($file->getSize() > AttachmentStorage::MAX_BYTES) {
                throw new TaskException(sprintf('«%s» більший за 20 МБ.', $file->getClientOriginalName()));
            }
        }

        $replyTo = null;

        if ($replyToId !== null && $replyToId > 0) {
            $replyTo = $this->messages->find($replyToId);

            // Відповідати можна лише на коментар цієї ж задачі — інакше цитата
            // витягла б текст із задачі, якої людина не бачить.
            if ($replyTo === null || $replyTo->getTask()->getId() !== $task->getId() || $replyTo->isEvent() || $replyTo->getDeletedAt() !== null) {
                throw new TaskException('Повідомлення, на яке ви відповідаєте, не знайдено.');
            }
        }

        $mentions = array_values(array_unique(array_filter(array_map('intval', $mentions))));
        $message = new TaskMessage($task, $author, $text, TaskMessage::KIND_COMMENT, $mentions);
        $message->setReplyTo($replyTo);
        $this->em->persist($message);

        // Згаданий, але не учасник — додаємо, інакше він не побачить, де його кличуть.
        foreach ($mentions as $id) {
            $mentioned = $this->users->find($id);

            if ($mentioned !== null && ! $mentioned->isArchived()) {
                $task->addMember($mentioned);
            }
        }

        // Повідомлення, файли й подія — одна транзакція: файл не завантажився —
        // немає ні повідомлення, ні сповіщення про нього.
        $this->events->transactional(function () use ($task, $author, $message, $files, $text, $mentions): void {
            $this->em->flush();

            $attachments = [];

            foreach ($files as $file) {
                $attachments[] = $this->attach($message, $author, $file->getPathname(), (string) ($file->getClientOriginalName() ?: $file->getFilename()), (string) ($file->getMimeType() ?: $file->getClientMimeType()));
            }

            $task->touch();
            $this->em->flush();
            $this->markRead($task, $author);

            $ref = SubjectRef::task((int) $task->getId());
            $this->events->record($text === '' && $attachments !== []
                ? new FileAdded(
                    $ref,
                    $author->getId(),
                    fileIds: array_map(static fn (TaskAttachment $a) => (int) $a->getId(), $attachments),
                    names: array_map(static fn (TaskAttachment $a) => $a->getOriginalName(), $attachments),
                    refId: $message->getId(),
                )
                : new CommentAdded($ref, $author->getId(), refId: (int) $message->getId(), excerpt: mb_substr($text, 0, 200), mentions: $mentions, files: count($attachments)));
        });

        return $message;
    }

    /** Файл із диска (для демо-даних і тестів) — той самий шлях, що й завантаження. */
    public function attach(TaskMessage $message, ?TelegramUser $by, string $path, string $name, string $mime): TaskAttachment
    {
        $attachment = new TaskAttachment(
            $message,
            $by,
            mb_substr($name, 0, 255),
            mb_substr($mime, 0, 127),
            (int) filesize($path),
            (string) hash_file('sha256', $path),
            AttachmentStorage::kindOf($name, $mime),
        );
        $message->addAttachment($attachment);
        $this->em->persist($attachment);
        $this->em->flush();

        $this->storage->put($attachment, $path);

        if (PreviewGenerator::isHeavy($attachment)) {
            $this->deferred->push($attachment);
        } else {
            $this->previews->generate($attachment);
        }

        $this->em->flush();

        return $attachment;
    }

    public function deleteMessage(TaskMessage $message, TelegramUser $user): void
    {
        if (! $this->policy->canDeleteMessage($user, $message)) {
            throw new TaskException('Видалити можна лише своє повідомлення.');
        }

        $message->delete();
        $this->events->transactional(function () use ($message, $user): void {
            $this->em->flush();
            $this->events->record(new CommentDeleted(SubjectRef::task((int) $message->getTask()->getId()), $user->getId(), refId: (int) $message->getId()));
        });
        $this->logger->info('Task message deleted', ['task' => $message->getTask()->getId(), 'message' => $message->getId(), 'by' => $user->getId()]);
    }

    /** Правка свого коментаря: старий текст — в історію, у стрічці позначка «змінено». */
    public function editMessage(TaskMessage $message, TelegramUser $user, string $text): TaskMessage
    {
        if (! $this->policy->canEditMessage($user, $message)) {
            throw new TaskException('Змінити можна лише своє повідомлення.');
        }

        $text = trim($text);

        if ($text === '' && $message->getAttachments()->isEmpty()) {
            throw new TaskException('Повідомлення не може бути порожнім.');
        }

        if (mb_strlen($text) > 10000) {
            throw new TaskException('Повідомлення задовге.');
        }

        if ($text === $message->getText()) {
            return $message;
        }

        $this->em->persist(new TaskMessageEdit($message, $user, $message->getText()));
        $message->edit($text);
        $this->events->transactional(function () use ($message, $user, $text): void {
            $this->em->flush();
            $this->events->record(new CommentEdited(SubjectRef::task((int) $message->getTask()->getId()), $user->getId(), refId: (int) $message->getId(), excerpt: mb_substr($text, 0, 200)));
        });

        return $message;
    }

    /** Закріплене видно всім учасникам над стрічкою. */
    public function pin(TaskMessage $message, TelegramUser $user, bool $on): TaskMessage
    {
        if (! $this->policy->canComment($user, $message->getTask())) {
            throw new TaskException('Ви не учасник цієї задачі.');
        }

        if ($message->isEvent() || $message->getDeletedAt() !== null) {
            throw new TaskException('Закріпити можна лише повідомлення.');
        }

        $on ? $message->pin($user) : $message->unpin();
        $this->em->flush();

        return $message;
    }

    /** ★ повідомлення — особиста закладка. */
    public function star(TaskMessage $message, TelegramUser $user, bool $on): void
    {
        if (! $this->policy->canView($user, $message->getTask())) {
            throw new TaskException('Ви не учасник цієї задачі.');
        }

        // Подвійний клік — два запити одночасно: вставка без «find-then-insert», щоб не впасти на унікальному індексі.
        $conn = $this->em->getConnection();

        if ($on && $message->getDeletedAt() === null) {
            $conn->executeStatement(
                "INSERT INTO task_message_star (id, message_id, user_id, created_at)
                 VALUES (nextval('task_message_star_id_seq'), ?, ?, ?) ON CONFLICT (user_id, message_id) DO NOTHING",
                [$message->getId(), $user->getId(), Task::now()->format('Y-m-d H:i:s')],
            );
        } elseif (! $on) {
            $conn->executeStatement('DELETE FROM task_message_star WHERE message_id = ? AND user_id = ?', [$message->getId(), $user->getId()]);
        }
    }

    /** ★ задачі — особисте «Обране». */
    public function favorite(Task $task, TelegramUser $user, bool $on): void
    {
        if (! $this->policy->canView($user, $task)) {
            throw new TaskException('Ви не учасник цієї задачі.');
        }

        $conn = $this->em->getConnection();

        if ($on) {
            $conn->executeStatement(
                "INSERT INTO task_favorite (id, task_id, user_id, created_at)
                 VALUES (nextval('task_favorite_id_seq'), ?, ?, ?) ON CONFLICT (user_id, task_id) DO NOTHING",
                [$task->getId(), $user->getId(), Task::now()->format('Y-m-d H:i:s')],
            );
        } else {
            $conn->executeStatement('DELETE FROM task_favorite WHERE task_id = ? AND user_id = ?', [$task->getId(), $user->getId()]);
        }
    }

    /**
     * Одним запитом (upsert), щоб два одночасні відкриття задачі не впали на uniq_task_read.
     * Позначка лише росте: старіша відповідь не відкотить «дочитав до».
     *
     * @return int до якого повідомлення людина дочитала ДО цього відкриття (для «Нові повідомлення»)
     */
    public function markRead(Task $task, TelegramUser $user, ?int $upTo = null): int
    {
        // CTE prev читає знімок до вставки; INSERT у WITH виконується, навіть якщо на нього не посилаються.
        $before = $this->em->getConnection()->fetchOne(
            "WITH prev AS (
                 SELECT last_message_id FROM task_read WHERE task_id = :task AND user_id = :user
             ), up AS (
                 INSERT INTO task_read (id, task_id, user_id, last_message_id, read_at)
                 VALUES (nextval('task_read_id_seq'), :task, :user, :upTo, CAST(:now AS timestamp))
                 ON CONFLICT (task_id, user_id) DO UPDATE
                    SET last_message_id = GREATEST(task_read.last_message_id, EXCLUDED.last_message_id),
                        read_at = EXCLUDED.read_at
             )
             SELECT COALESCE((SELECT last_message_id FROM prev), 0)",
            [
                'task' => $task->getId(),
                'user' => $user->getId(),
                'upTo' => $upTo ?? $this->messages->lastId($task),
                'now' => Task::now()->format('Y-m-d H:i:s'),
            ],
        );

        return (int) $before;
    }

    /** @param array<string, mixed> $data */
    private function fill(Task $task, array $data, bool $replaceMembers = false): void
    {
        if (array_key_exists('description', $data)) {
            $description = trim((string) $data['description']);
            $task->setDescription($description !== '' ? $description : null);
        }

        if (array_key_exists('client', $data)) {
            $client = trim((string) $data['client']);
            $task->setClient($client !== '' ? mb_substr($client, 0, 255) : null);
        }

        if (array_key_exists('dueAt', $data)) {
            $task->setDueAt($this->date($data['dueAt']));
        }

        if (array_key_exists('labelIds', $data)) {
            $ids = array_map('intval', (array) $data['labelIds']);
            $task->setLabels($ids === [] ? [] : $this->labels->findBy(['id' => $ids]));
        }

        if (array_key_exists('departmentIds', $data)) {
            $wanted = $ids = array_map('intval', (array) $data['departmentIds']);
            $departments = $wanted === [] ? [] : $this->departments->findBy(['id' => $ids]);

            if ($replaceMembers) {
                foreach ($task->getDepartments()->toArray() as $department) {
                    if (! in_array($department->getId(), $wanted, true)) {
                        $task->removeDepartment($department);
                    }
                }
            }

            foreach ($departments as $department) {
                $task->addDepartment($department);
                // Логіка ПланФікса: обрав відділ — у задачі всі його люди.
                foreach ($this->peopleOf($department) as $person) {
                    $task->addMember($person, $department);
                }
            }
        }

        if (array_key_exists('memberIds', $data)) {
            $ids = array_map('intval', (array) $data['memberIds']);

            if ($replaceMembers) {
                foreach ($task->getMembers()->toArray() as $member) {
                    $viaKept = $member->getVia() !== null && $task->getDepartments()->contains($member->getVia());
                    // Автор лишається завжди: прибрати й одразу додати знову — дві операції над
                    // одним рядком, і Doctrine вставляє раніше, ніж видаляє (uniq_task_member).
                    $isCreator = $member->getUser()->getId() === $task->getCreator()->getId();

                    if (! in_array($member->getUser()->getId(), $ids, true) && ! $viaKept && ! $isCreator) {
                        $task->removeMember($member->getUser());
                    }
                }
            }

            foreach ($ids === [] ? [] : $this->users->findBy(['id' => $ids]) as $person) {
                if (! $person->isArchived()) {
                    $task->addMember($person);
                }
            }
        }
    }

    /** @return TelegramUser[] */
    private function peopleOf(Department $department): array
    {
        return array_filter(
            $this->users->findBy(['department' => $department]),
            static fn (TelegramUser $user) => ! $user->isArchived(),
        );
    }

    private function date(mixed $value): ?DateTime
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $tz = new DateTimeZone('Europe/Kyiv');
        $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value, $tz)
            ?: DateTime::createFromFormat('!Y-m-d', $value, $tz);

        if ($date === false) {
            throw new TaskException('Дата — у форматі РРРР-ММ-ДД.');
        }

        // Лише дата — дедлайн у кінці робочого дня.
        if (strlen($value) === 10) {
            $date->setTime(17, 0);
        }

        return $date;
    }

    private function event(Task $task, TelegramUser $user, string $text, ?string $type = null): TaskMessage
    {
        $message = (new TaskMessage($task, $user, $text, TaskMessage::KIND_EVENT))->setEventType($type);
        $this->em->persist($message);
        $task->touch();

        return $message;
    }

    private function membersLine(string $prefix, Task $task): string
    {
        $departments = array_map(static fn (Department $d) => $d->getName(), $task->getDepartments()->toArray());

        return $departments === [] ? $prefix : $prefix . ' · відділи: ' . implode(', ', $departments);
    }
}
