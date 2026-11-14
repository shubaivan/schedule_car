<?php

namespace App\Plan\Drive\Source;

use App\Plan\Drive\DriveItem;
use App\Plan\Drive\DrivePaths;
use App\Plan\Share\FileType;
use App\Plan\Subject\SubjectRef;
use App\Supply\Repository\SupplyAttachmentRepository;
use App\Supply\Service\AttachFile;
use RuntimeException;

/** Документи заявок: План / <рік> / Заявки / №042-2026 <матеріал> / <тип> <номер>.pdf. */
final readonly class SupplyFiles implements DriveSource
{
    public function __construct(
        private SupplyAttachmentRepository $attachments,
        private AttachFile $files,
        private DrivePaths $paths,
    ) {
    }

    public function type(): FileType
    {
        return FileType::Supply;
    }

    public function item(int $id): ?DriveItem
    {
        $attachment = $this->attachments->find($id);

        if ($attachment === null) {
            return null;
        }

        $request = $attachment->getRequest();

        return new DriveItem(
            FileType::Supply,
            $id,
            DrivePaths::safe($attachment->getDriveName()),
            $attachment->getMime(),
            $this->paths->supply($request->getCreatedAt(), $request->getNumber(), $request->getItem()),
            SubjectRef::supply((int) $request->getId()),
        );
    }

    public function read(int $id): string
    {
        return $this->files->read($this->attachments->find($id) ?? throw new RuntimeException('Документ заявки не знайдено.'));
    }
}
