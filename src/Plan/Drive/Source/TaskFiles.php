<?php

namespace App\Plan\Drive\Source;

use App\Plan\Drive\DriveItem;
use App\Plan\Drive\DrivePaths;
use App\Plan\Share\FileType;
use App\Plan\Subject\SubjectRef;
use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Repository\TaskAttachmentRepository;
use App\Tasks\Service\AttachmentStorage;
use RuntimeException;

/** Файли чатів задач: План / <рік> / Задачі / №0012 <назва> / <файл>. */
final readonly class TaskFiles implements DriveSource
{
    public function __construct(
        private TaskAttachmentRepository $attachments,
        private AttachmentStorage $storage,
        private DrivePaths $paths,
    ) {
    }

    public function type(): FileType
    {
        return FileType::Task;
    }

    public function item(int $id): ?DriveItem
    {
        $attachment = $this->attachments->find($id);

        if (! $attachment instanceof TaskAttachment || $attachment->getMessage()->getDeletedAt() !== null) {
            return null;
        }

        $task = $attachment->getTask();

        return new DriveItem(
            FileType::Task,
            $id,
            DrivePaths::safe($attachment->getOriginalName()),
            $attachment->getMime(),
            $this->paths->task($task->getCreatedAt(), $task->number(), $task->getTitle()),
            SubjectRef::task((int) $task->getId()),
        );
    }

    public function read(int $id): string
    {
        $attachment = $this->attachments->find($id) ?? throw new RuntimeException('Файл задачі не знайдено.');
        $contents = @file_get_contents($this->storage->original($attachment));

        if ($contents === false) {
            throw new RuntimeException('Файла немає у сховищі задач.');
        }

        return $contents;
    }
}
