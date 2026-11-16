<?php

namespace App\Plan\Share;

use App\Entity\TelegramUser;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Supply\Repository\SupplyAttachmentRepository;
use App\Supply\Repository\SupplyVisibility;
use App\Supply\Service\AttachFile;
use App\Supply\Service\SupplyFeed;
use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Repository\TaskAttachmentRepository;
use App\Tasks\Service\AttachmentStorage;
use App\Tasks\Service\TaskPolicy;
use App\Tasks\Service\TaskPresenter;
use App\Tasks\Service\TasksSection;
use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhDocument;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use App\Warehouse\Repository\WhDocumentRepository;
use App\Warehouse\Service\DocumentStore;
use App\Warehouse\Service\WarehouseSection;
use DateTimeInterface;
use RuntimeException;

/**
 * Файли «Плану» трьох модулів за одним інтерфейсом: знайти, перевірити доступ,
 * прочитати, показати у форматі Attachment фронту.
 *
 * Права — ті самі, що в модулів (нового правила тут немає):
 * - файл задачі — бачиш задачу (TaskPolicy::canView), повідомлення не видалене;
 * - документ заявки — бачиш заявку (SupplyVisibility: у «Плані» лише присутні, директор, адмін, постачання);
 * - документ складу — усім, кому склад видно (WarehouseSection::canView, як GET /api/warehouse/documents/{id}).
 */
class PlanFiles
{
    public function __construct(
        private readonly TaskAttachmentRepository $taskFiles,
        private readonly SupplyAttachmentRepository $supplyFiles,
        private readonly WhDocumentRepository $warehouseFiles,
        private readonly TaskPolicy $taskPolicy,
        private readonly TasksSection $tasks,
        private readonly WarehouseSection $warehouse,
        private readonly AttachmentStorage $taskStorage,
        private readonly AttachFile $supplyStore,
        private readonly DocumentStore $warehouseStore,
        private readonly TaskPresenter $taskPresenter,
        private readonly SupplyFeed $supplyFeed,
        private readonly ?SupplyVisibility $supplyVisibility = null,
    ) {
    }

    public function find(FileType $type, int $id): ?PlanFile
    {
        return match ($type) {
            FileType::Task => $this->task($id),
            FileType::Supply => $this->supply($id),
            FileType::Warehouse => $this->document($id),
        };
    }

    public function canView(?TelegramUser $user, PlanFile $file): bool
    {
        if ($user === null || $user->isArchived() || ! $user->isApproved()) {
            return false;
        }

        $entity = $file->entity;

        return match (true) {
            $entity instanceof TaskAttachment => $this->tasks->enabled() && $entity->getMessage()->getDeletedAt() === null && $this->taskPolicy->canView($user, $entity->getTask()),
            $entity instanceof WhDocument => $this->warehouse->isEnabled() && WarehouseSection::canView($user),
            // Решта — документ заявки (SupplyAttachment).
            default => $this->supplyVisibility?->canView($entity->getRequest(), $user) ?? true,
        };
    }

    /** Вміст файлу з нашого сховища. */
    public function read(PlanFile $file): string
    {
        $entity = $file->entity;

        if ($entity instanceof TaskAttachment) {
            $contents = @file_get_contents($this->taskStorage->original($entity));

            return $contents !== false ? $contents : throw new RuntimeException('Файла немає у сховищі.');
        }

        return $entity instanceof WhDocument ? $this->warehouseStore->read($entity) : $this->supplyStore->read($entity);
    }

    /** Шлях на диску, якщо файл лежить звичайним файлом (задачі), — для BinaryFileResponse. */
    /**
     * Потік документа заявки чи складу (Flysystem) — для віддачі шматками.
     *
     * @return resource
     */
    public function readStream(PlanFile $file)
    {
        $entity = $file->entity;

        if ($entity instanceof TaskAttachment) {
            $stream = @fopen($this->taskStorage->original($entity), 'rb');

            return $stream !== false ? $stream : throw new RuntimeException('Файла немає у сховищі.');
        }

        return $entity instanceof WhDocument ? $this->warehouseStore->readStream($entity) : $this->supplyStore->readStream($entity);
    }

    public function localPath(PlanFile $file): ?string
    {
        return $file->entity instanceof TaskAttachment ? $this->taskStorage->original($file->entity) : null;
    }

    /**
     * Формат Attachment фронту (як у стрічці задачі чи заявки) + driveUrl / driveState.
     *
     * @return array<string, mixed>
     */
    public function present(PlanFile $file): array
    {
        $entity = $file->entity;

        $out = match (true) {
            $entity instanceof TaskAttachment => $this->taskPresenter->attachment($entity),
            $entity instanceof WhDocument => $this->warehouseAttachment($entity),
            default => $this->supplyFeed->attachment($entity),
        };

        return [...$out, 'fileType' => $file->type->value, 'driveUrl' => $file->driveUrl(), 'driveState' => $file->driveState()];
    }

    private function task(int $id): ?PlanFile
    {
        $a = $this->taskFiles->find($id);

        if ($a === null) {
            return null;
        }

        $task = $a->getTask();

        return new PlanFile(FileType::Task, $id, $a->getOriginalName(), $a->getMime(), $a->getSize(), SubjectRef::task((int) $task->getId()), sprintf('Задача №%s %s', $task->number(), $task->getTitle()), $a);
    }

    private function supply(int $id): ?PlanFile
    {
        $a = $this->supplyFiles->find($id);

        if ($a === null) {
            return null;
        }

        $request = $a->getRequest();

        return new PlanFile(FileType::Supply, $id, $a->getOriginalName(), $a->getMime(), $a->getSize(), SubjectRef::supply((int) $request->getId()), sprintf('Заявка №%s %s', $request->getNumber(), $request->getItem()), $a);
    }

    private function document(int $id): ?PlanFile
    {
        $d = $this->warehouseFiles->find($id);
        $owner = $d?->getOwner();

        if ($d === null || $owner === null) {
            return null;
        }

        [$type, $label] = match (true) {
            $owner instanceof WhClient => [SubjectType::WhClient, 'Клієнт ' . $owner->getName()],
            $owner instanceof WhSite => [SubjectType::WhSite, "Об'єкт " . $owner->getName()],
            $owner instanceof WhMovement => [SubjectType::WhMovement, 'Рух складу від ' . $owner->getOccurredAt()->format('d.m.Y')],
            default => [SubjectType::WhItem, sprintf('Позиція %s %s', $owner->getInventoryNumber(), $owner->getName())],
        };

        return new PlanFile(FileType::Warehouse, $id, $d->getOriginalName(), $d->getMime(), $d->getSize(), SubjectRef::of($type, (int) $owner->getId()), 'Склад: ' . $label, $d);
    }

    /** @return array<string, mixed> */
    private function warehouseAttachment(WhDocument $d): array
    {
        $base = '/api/plan/files/wh/' . $d->getId();
        $mime = $d->getMime();
        $image = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);

        return [
            'id' => $d->getId(),
            'messageId' => 0,
            'name' => $d->getOriginalName(),
            'ext' => mb_strtoupper((string) pathinfo($d->getOriginalName(), PATHINFO_EXTENSION)),
            'mime' => $mime,
            'size' => $d->getSize(),
            'sizeLabel' => $d->getSizeLabel(),
            'kind' => $image ? 'image' : ($mime === 'application/pdf' ? 'pdf' : 'other'),
            'preview' => 'none',
            'pages' => null,
            'width' => null,
            'height' => null,
            'uploadedBy' => $d->getUploadedBy()?->shortName(),
            'createdAt' => $d->getCreatedAt()->format(DateTimeInterface::ATOM),
            'urls' => [
                'thumb' => $image ? $base . '/inline' : null,
                'view' => $image ? $base . '/inline' : null,
                'page' => null,
                'inline' => $base . '/inline',
                'download' => $base . '/download',
            ],
        ];
    }
}
