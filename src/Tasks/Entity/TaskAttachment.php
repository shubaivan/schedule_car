<?php

namespace App\Tasks\Entity;

use App\Entity\TelegramUser;
use App\Plan\Drive\Entity\DriveSyncState;
use App\Tasks\Repository\TaskAttachmentRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Файл у чаті задачі. Лежить поза public/ (var/storage/tasks), віддається
 * лише контролером після перевірки доступу до задачі.
 *
 * Превʼю: картинка — мініатюра й екранна копія (GD); PDF — сторінки через
 * pdftoppm; офісні — спершу LibreOffice → PDF, далі як PDF.
 */
#[ORM\Entity(repositoryClass: TaskAttachmentRepository::class)]
#[ORM\Table(name: 'task_attachment')]
#[ORM\Index(columns: ['preview_state'], name: 'idx_task_attachment_preview')]
// Черга plan:drive-sync — частковий індекс (міграція Version20261115170000).
#[ORM\Index(name: 'idx_task_attachment_drive_queue', columns: ['id'], options: ['where' => "((drive_file_id IS NULL) AND ((drive_state)::text = 'pending'::text))"])]
class TaskAttachment
{
    use DriveSyncState;

    public const KIND_IMAGE = 'image';
    public const KIND_PDF = 'pdf';
    public const KIND_OFFICE = 'office';
    public const KIND_OTHER = 'other';

    public const PREVIEW_NONE = 'none';
    public const PREVIEW_PENDING = 'pending';
    public const PREVIEW_READY = 'ready';
    public const PREVIEW_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TaskMessage::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'message_id', nullable: false, onDelete: 'CASCADE')]
    private TaskMessage $message;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $uploadedBy;

    #[ORM\Column(name: 'original_name', type: 'string', length: 255)]
    private string $originalName;

    #[ORM\Column(type: 'string', length: 127)]
    private string $mime;

    #[ORM\Column(type: 'integer')]
    private int $size;

    #[ORM\Column(type: 'string', length: 64)]
    private string $sha256;

    /** Шлях відносно сховища задач. */
    #[ORM\Column(type: 'string', length: 255)]
    private string $path = '';

    #[ORM\Column(type: 'string', length: 10)]
    private string $kind;

    #[ORM\Column(name: 'preview_state', type: 'string', length: 10, options: ['default' => 'none'])]
    private string $previewState = self::PREVIEW_NONE;

    /** Сторінок у PDF (або в PDF, на який сконвертовано офісний файл). */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $pages = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $width = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $height = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private DateTime $createdAt;

    /** Копія на Google Диску «Плану» (план → Диск, односторонньо; див. App\Plan\Drive\DriveSync). */
    #[ORM\Column(name: 'drive_file_id', type: 'string', length: 128, nullable: true)]
    private ?string $driveFileId = null;

    #[ORM\Column(name: 'drive_url', type: 'string', length: 512, nullable: true)]
    private ?string $driveUrl = null;

    public function __construct(TaskMessage $message, ?TelegramUser $uploadedBy, string $originalName, string $mime, int $size, string $sha256, string $kind)
    {
        $this->message = $message;
        $this->uploadedBy = $uploadedBy;
        $this->originalName = $originalName;
        $this->mime = $mime;
        $this->size = $size;
        $this->sha256 = $sha256;
        $this->kind = $kind;
        $this->createdAt = Task::now();
        $this->previewState = $kind === self::KIND_OTHER ? self::PREVIEW_NONE : self::PREVIEW_PENDING;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): TaskMessage
    {
        return $this->message;
    }

    public function getTask(): Task
    {
        return $this->message->getTask();
    }

    public function getUploadedBy(): ?TelegramUser
    {
        return $this->uploadedBy;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getPreviewState(): string
    {
        return $this->previewState;
    }

    public function previewReady(?int $pages = null, ?int $width = null, ?int $height = null): self
    {
        $this->previewState = self::PREVIEW_READY;
        $this->pages = $pages;
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    public function previewFailed(): self
    {
        $this->previewState = self::PREVIEW_FAILED;

        return $this;
    }

    public function getPages(): ?int
    {
        return $this->pages;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    /** Вміст можна гортати сторінками (PDF або сконвертований офісний документ). */
    public function isPaged(): bool
    {
        return ($this->kind === self::KIND_PDF || $this->kind === self::KIND_OFFICE) &&
            $this->previewState === self::PREVIEW_READY;
    }

    public function getDriveFileId(): ?string
    {
        return $this->driveFileId;
    }

    public function getDriveUrl(): ?string
    {
        return $this->driveUrl;
    }
}
