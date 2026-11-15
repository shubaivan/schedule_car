<?php

namespace App\Tasks\Service;

use App\Entity\TelegramUser;
use App\Plan\People\PhoneFormat;
use App\Supply\Entity\Department;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Entity\TaskLabel;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Enum\TaskStatus;
use App\Tasks\Repository\TaskFeedRepository;
use DateTimeInterface;

/** JSON-вигляд задач для CRM. */
class TaskPresenter
{
    /** Палітра аватарок — колір стабільний для людини (за id). */
    private const array PALETTE = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#ec4899', '#84cc16', '#f97316', '#06b6d4', '#a855f7'];

    public function __construct(private TaskPolicy $policy, private TaskFeedRepository $feed)
    {
    }

    /** @return array<string, mixed> */
    public function person(?TelegramUser $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $name = $user->shortName();
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $initials = mb_strtoupper(implode('', array_map(static fn ($p) => mb_substr(ltrim($p, '@'), 0, 1), array_slice($parts, 0, 2))));

        return [
            'id' => $user->getId(),
            'name' => $name,
            'initials' => $initials !== '' ? $initials : '?',
            'color' => self::PALETTE[(int) $user->getId() % count(self::PALETTE)],
            'position' => $user->getPosition(),
            // Телефон поруч з іменем скрізь у Плані (PersonName на фронті); немає — null.
            'phone' => PhoneFormat::display($user->getPhoneNumber()),
            'tel' => PhoneFormat::tel($user->getPhoneNumber()),
            'role' => $user->getWorkRole()->value,
            'roleLabel' => $user->getWorkRole()->label(),
            'department' => $user->getDepartment()?->getName(),
            'departmentId' => $user->getDepartment()?->getId(),
            'archived' => $user->isArchived(),
        ];
    }

    /**
     * Коротко для аватара в списках: ім'я, ініціали, колір, посада (підказка).
     *
     * @return array{id: ?int, name: string, initials: string, color: string, position: ?string}|null
     */
    public function brief(?TelegramUser $user): ?array
    {
        $full = $this->person($user);

        return $full === null ? null : [
            'id' => $full['id'],
            'name' => $full['name'],
            'initials' => $full['initials'],
            'color' => $full['color'],
            'position' => $full['position'],
        ];
    }

    /** @return array<string, mixed> */
    public function label(TaskLabel $label): array
    {
        return ['id' => $label->getId(), 'name' => $label->getName(), 'color' => $label->getColor()];
    }

    /** @return array<string, mixed> */
    public function status(TaskStatus $status): array
    {
        return [
            'value' => $status->value,
            'label' => $status->label(),
            'action' => $status->action(),
            'column' => $status->column(),
            'closed' => $status->isClosed(),
            'decision' => $status->isDecision(),
        ];
    }

    /**
     * @param array<int, int> $unread
     * @param array<int, TaskMessage> $last
     * @param array<int, array{count: int, pdf: int, images: int}> $files
     *
     * @return array<string, mixed>
     */
    public function listItem(Task $task, array $unread = [], array $last = [], array $files = [], bool $brief = true): array
    {
        // Картки дошки й «Огляду»: людина — лише те, що малює аватар (DB-perf #4: дошка
        // повторює ті самі обличчя в кожній картці, повний профіль їй не потрібен).
        $who = $brief ? $this->brief(...) : $this->person(...);
        $members = $task->getMembers()->toArray();
        $message = $last[$task->getId()] ?? null;

        return [
            'id' => $task->getId(),
            'number' => $task->number(),
            'title' => $task->getTitle(),
            'client' => $task->getClient(),
            'status' => $task->getStatus()->value,
            'statusLabel' => $task->getStatus()->label(),
            'column' => $task->getStatus()->column(),
            'closed' => $task->getStatus()->isClosed(),
            'labels' => array_map($this->label(...), $task->getLabels()->toArray()),
            'dueAt' => $this->date($task->getDueAt()),
            'overdue' => $task->isOverdue(),
            'creator' => $who($task->getCreator()),
            'members' => array_map(fn ($m) => $who($m->getUser()), array_slice($members, 0, 6)),
            'membersCount' => count($members),
            'departmentNames' => $this->departmentNames($task),
            'fileStats' => $files[$task->getId()] ?? ['count' => 0, 'pdf' => 0, 'images' => 0],
            'unread' => $unread[$task->getId()] ?? 0,
            'lastActivityAt' => $this->date($task->getLastActivityAt()),
            'createdAt' => $this->date($task->getCreatedAt()),
            'last' => $message === null ? null : [
                'author' => $message->getAuthor()?->shortName(),
                'text' => mb_substr($message->getText(), 0, 140),
                'files' => $message->getAttachments()->count(),
                'at' => $this->date($message->getCreatedAt()),
            ],
        ];
    }

    /**
     * @param TaskMessage[] $messages
     * @param int|null $readUpTo до якого повідомлення людина дочитала до цього відкриття
     *
     * @return array<string, mixed>
     */
    public function detail(Task $task, TelegramUser $viewer, array $messages, ?int $readUpTo = null): array
    {
        // Мітка для першого опитування змін (правки між відкриттям і опитуванням).
        $now = Task::now()->modify('-1 second');
        $starred = $this->feed->starredOf($viewer, array_map(static fn (TaskMessage $m) => (int) $m->getId(), $messages));

        $files = [];

        foreach ($messages as $message) {
            if ($message->getDeletedAt() === null) {
                foreach ($message->getAttachments() as $attachment) {
                    $files[] = $this->attachment($attachment);
                }
            }
        }

        return [
            ...$this->listItem($task, brief: false),
            'description' => $task->getDescription(),
            'members' => array_map(fn ($m) => [
                ...$this->person($m->getUser()),
                'via' => $m->getVia()?->getName(),
            ], $task->getMembers()->toArray()),
            'departments' => array_map(
                static fn (Department $d) => ['id' => $d->getId(), 'name' => $d->getName()],
                $task->getDepartments()->toArray(),
            ),
            'closedAt' => $this->date($task->getClosedAt()),
            'can' => [
                'edit' => $this->policy->canEdit($viewer, $task),
                'comment' => $this->policy->canComment($viewer, $task),
                'transitions' => array_map($this->status(...), $this->policy->transitions($viewer, $task)),
            ],
            'awaitsDecision' => $task->getStatus()->awaitsDecision(),
            'messages' => array_map(fn (TaskMessage $m) => $this->message($m, $viewer, $starred), $messages),
            'files' => $files,
            'favorite' => $this->feed->isFavorite($viewer, $task),
            'reads' => $this->feed->reads($task),
            'readUpTo' => $readUpTo,
            'now' => $now->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param int[] $starred id повідомлень, які глядач позначив ★
     *
     * @return array<string, mixed>
     */
    public function message(TaskMessage $message, TelegramUser $viewer, array $starred = []): array
    {
        $deleted = $message->getDeletedAt() !== null;
        $reply = $message->getReplyTo();

        return [
            'id' => $message->getId(),
            'kind' => $message->getKind(),
            'eventType' => self::eventType($message),
            'author' => $this->person($message->getAuthor()),
            'text' => $deleted ? '' : $message->getText(),
            'mentions' => $message->getMentions(),
            'createdAt' => $this->date($message->getCreatedAt()),
            'deleted' => $deleted,
            'mine' => $message->getAuthor()?->getId() === $viewer->getId(),
            'attachments' => $deleted ? [] : array_map($this->attachment(...), $message->getAttachments()->toArray()),
            'replyTo' => $reply === null || $deleted ? null : [
                'id' => $reply->getId(),
                'author' => $reply->getAuthor()?->shortName(),
                'authorColor' => $reply->getAuthor() === null ? null : self::PALETTE[(int) $reply->getAuthor()->getId() % count(self::PALETTE)],
                'text' => $reply->getDeletedAt() !== null ? '' : mb_substr($reply->getText(), 0, 160),
                'files' => $reply->getDeletedAt() !== null ? 0 : $reply->getAttachments()->count(),
                'deleted' => $reply->getDeletedAt() !== null,
            ],
            'editedAt' => $deleted ? null : $this->date($message->getEditedAt()),
            'pinned' => $message->getPinnedAt() === null ? null : [
                'at' => $this->date($message->getPinnedAt()),
                'by' => $message->getPinnedBy()?->shortName(),
            ],
            'starred' => in_array((int) $message->getId(), $starred, true),
            'can' => [
                'edit' => $this->policy->canEditMessage($viewer, $message),
                'delete' => $this->policy->canDeleteMessage($viewer, $message),
            ],
        ];
    }

    /** comment | file | created | status | closed | members | updated — для іконки в Хроніці. */
    public static function eventType(TaskMessage $message): string
    {
        if (! $message->isEvent()) {
            return $message->getAttachments()->isEmpty() || trim($message->getText()) !== '' ? 'comment' : 'file';
        }

        return $message->getEventType() ?? TaskMessage::EVENT_UPDATED;
    }

    /** @return array<string, mixed> */
    public function attachment(TaskAttachment $a): array
    {
        $base = '/api/tasks/files/' . $a->getId();
        $ready = $a->getPreviewState() === TaskAttachment::PREVIEW_READY;

        return [
            'id' => $a->getId(),
            'messageId' => $a->getMessage()->getId(),
            'name' => $a->getOriginalName(),
            'ext' => mb_strtoupper(AttachmentStorage::extension($a->getOriginalName())),
            'mime' => $a->getMime(),
            'size' => $a->getSize(),
            'sizeLabel' => self::size($a->getSize()),
            'kind' => $a->getKind(),
            'preview' => $a->getPreviewState(),
            'pages' => $a->getPages(),
            'width' => $a->getWidth(),
            'height' => $a->getHeight(),
            'uploadedBy' => $a->getUploadedBy()?->shortName(),
            'createdAt' => $this->date($a->getCreatedAt()),
            'urls' => [
                'thumb' => $ready ? $base . '/thumb' : null,
                'view' => $ready && $a->getKind() === TaskAttachment::KIND_IMAGE ? $base . '/view' : null,
                'page' => $a->isPaged() ? $base . '/page/' : null,
                'inline' => $base . '/inline',
                'download' => $base . '/download',
            ],
        ];
    }

    /**
     * Виконавці на картці дошки — відділи «[3. Відділ продажу]», як у ПланФіксі.
     * Якщо задачу адресовано людям, а не відділам, — відділи цих людей
     * (поки в задачі немає ролей «виконавець / учасник / аудитор», спец. #15).
     * Порядок — за номером у назві відділу («1. Дирекція» … «9. …»).
     *
     * @return list<string>
     */
    public function departmentNames(Task $task): array
    {
        $names = array_map(static fn (Department $d) => $d->getName(), $task->getDepartments()->toArray());

        if ($names === []) {
            foreach ($task->getMembers() as $member) {
                $department = $member->getUser()->getDepartment();

                if ($department !== null && $member->getUser() !== $task->getCreator()) {
                    $names[] = $department->getName();
                }
            }
        }

        $names = array_values(array_unique($names));
        natsort($names);

        return array_values($names);
    }

    public static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => number_format($bytes / 1048576, 1, ',', ' ') . ' МБ',
            $bytes >= 1024 => number_format($bytes / 1024, 0, ',', ' ') . ' КБ',
            default => $bytes . ' Б',
        };
    }

    private function date(?DateTimeInterface $date): ?string
    {
        return $date?->format(DateTimeInterface::ATOM);
    }
}
