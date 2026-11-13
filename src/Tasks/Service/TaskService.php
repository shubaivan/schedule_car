<?php

namespace App\Tasks\Service;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Supply\Entity\Department;
use App\Supply\Repository\DepartmentRepository;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Entity\TaskRead;
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
 * кожна зміна лишає слід у чаті задачі системним повідомленням.
 */
class TaskService
{
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
        private TaskNotifier $notifier,
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
        $this->em->persist(new TaskMessage($task, $author, $this->membersLine('створив(ла) задачу', $task), TaskMessage::KIND_EVENT));
        $this->em->flush();

        $this->logger->info('Task created', ['task' => $task->getId(), 'by' => $author->getId()]);
        $this->notifier->created($task);

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

        $notifyAdded = [];

        if ($added !== []) {
            $names = implode(', ', array_map(static fn ($m) => $m->getUser()->shortName(), $added));
            $this->event($task, $user, 'додав(ла) учасників: ' . $names);
            $notifyAdded = array_map(static fn ($m) => $m->getUser(), $added);
        } else {
            $this->event($task, $user, 'оновив(ла) задачу');
        }

        $this->em->flush();

        if ($notifyAdded !== []) {
            $this->notifier->added($task, $notifyAdded, $user);
        }

        return $task;
    }

    public function changeStatus(Task $task, TelegramUser $user, TaskStatus $to, ?string $comment = null): Task
    {
        if (! $this->policy->canMove($user, $task, $to)) {
            throw new TaskException($task->getStatus()->awaitsDecision() || $to->isDecision()
                ? 'Це рішення приймає директор.'
                : sprintf('Перехід «%s» → «%s» недоступний.', $task->getStatus()->label(), $to->label()));
        }

        $from = $task->getStatus();
        $task->setStatus($to);
        $this->event($task, $user, sprintf('змінив(ла) статус: %s → %s', $from->label(), $to->label()));

        if ($comment !== null && trim($comment) !== '') {
            $this->em->persist(new TaskMessage($task, $user, trim($comment)));
        }

        $this->em->flush();
        $this->logger->info('Task status', ['task' => $task->getId(), 'from' => $from->value, 'to' => $to->value, 'by' => $user->getId()]);
        $this->notifier->statusChanged($task, $from, $user, $comment);

        return $task;
    }

    /**
     * Повідомлення в чат задачі — текст і/або файли.
     *
     * @param UploadedFile[] $files
     * @param int[] $mentions
     */
    public function post(Task $task, TelegramUser $author, string $text, array $files = [], array $mentions = []): TaskMessage
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

        $mentions = array_values(array_unique(array_filter(array_map('intval', $mentions))));
        $message = new TaskMessage($task, $author, $text, TaskMessage::KIND_COMMENT, $mentions);
        $this->em->persist($message);

        // Згаданий, але не учасник — додаємо, інакше він не побачить, де його кличуть.
        foreach ($mentions as $id) {
            $mentioned = $this->users->find($id);

            if ($mentioned !== null && ! $mentioned->isArchived()) {
                $task->addMember($mentioned);
            }
        }

        $this->em->flush();

        foreach ($files as $file) {
            $this->attach($message, $author, $file->getPathname(), (string) ($file->getClientOriginalName() ?: $file->getFilename()), (string) ($file->getMimeType() ?: $file->getClientMimeType()));
        }

        $task->touch();
        $this->em->flush();
        $this->markRead($task, $author);
        $this->notifier->message($message);

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
        $isAuthor = $message->getAuthor()?->getId() === $user->getId();

        if ($message->isEvent() || (! $isAuthor && ! $user->getWorkRole()->managesStaff())) {
            throw new TaskException('Видалити можна лише своє повідомлення.');
        }

        $message->delete();
        $this->em->flush();
    }

    public function markRead(Task $task, TelegramUser $user): void
    {
        $read = $this->em->getRepository(TaskRead::class)->findOneBy(['task' => $task, 'user' => $user]);

        if ($read === null) {
            $read = new TaskRead($task, $user);
            $this->em->persist($read);
        }

        $read->readUpTo($this->messages->lastId($task));
        $this->em->flush();
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

                    if (! in_array($member->getUser()->getId(), $ids, true) && ! $viaKept) {
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

    private function event(Task $task, TelegramUser $user, string $text): void
    {
        $this->em->persist(new TaskMessage($task, $user, $text, TaskMessage::KIND_EVENT));
        $task->touch();
    }

    private function membersLine(string $prefix, Task $task): string
    {
        $departments = array_map(static fn (Department $d) => $d->getName(), $task->getDepartments()->toArray());

        return $departments === [] ? $prefix : $prefix . ' · відділи: ' . implode(', ', $departments);
    }
}
