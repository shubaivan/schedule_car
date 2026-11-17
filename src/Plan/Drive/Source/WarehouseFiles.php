<?php

namespace App\Plan\Drive\Source;

use App\Plan\Drive\DriveItem;
use App\Plan\Drive\DrivePaths;
use App\Plan\Share\FileType;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Repository\WhDocumentRepository;
use App\Warehouse\Service\DocumentStore;
use App\Warehouse\Service\WarehouseDriveMirror;
use RuntimeException;

/**
 * Документи складу: План / <рік> / Склад / <те саме дерево, що в стенда: Клієнти/…, Позиції/…, Надходження/…>.
 * Шлях складу бере в WarehouseDriveMirror::folderPath() (той клас не змінюється), без його кореня «Склад».
 */
final readonly class WarehouseFiles implements DriveSource
{
    public function __construct(
        private WhDocumentRepository $documents,
        private DocumentStore $store,
        private DrivePaths $paths,
    ) {
    }

    public function type(): FileType
    {
        return FileType::Warehouse;
    }

    public function item(int $id): ?DriveItem
    {
        $document = $this->documents->find($id);

        $owner = $document?->getOwner();

        // Документ без власника (клієнта/об'єкта/позиції/руху) DocumentStore не створює.
        if ($document === null || $owner === null) {
            return null;
        }

        $warehousePath = array_slice(WarehouseDriveMirror::folderPath($document), 1);

        return new DriveItem(
            FileType::Warehouse,
            $id,
            DrivePaths::dated($document->getCreatedAt(), $document->getDriveName()),
            $document->getMime(),
            $this->paths->warehouse($owner->getCreatedAt(), $warehousePath),
            match (true) {
                $owner instanceof WhClient => SubjectRef::of(SubjectType::WhClient, (int) $owner->getId()),
                $owner instanceof WhSite => SubjectRef::of(SubjectType::WhSite, (int) $owner->getId()),
                $owner instanceof WhMovement => SubjectRef::of(SubjectType::WhMovement, (int) $owner->getId()),
                default => SubjectRef::of(SubjectType::WhItem, (int) $owner->getId()),
            },
        );
    }

    public function read(int $id): string
    {
        return $this->store->read($this->documents->find($id) ?? throw new RuntimeException('Документ складу не знайдено.'));
    }
}
