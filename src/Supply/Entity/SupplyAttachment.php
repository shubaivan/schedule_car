<?php

namespace App\Supply\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Entity\TelegramUser;
use App\Supply\Enum\AttachmentType;
use App\Supply\Repository\SupplyAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Файл, прикріплений до заявки: накладна, рахунок, договір, фото товару.
 *
 * Сам файл лежить у нашому сховищі поза public/ — джерело правди тут, разом
 * із прив'язкою до заявки, автором завантаження й хешем. Google Drive —
 * упорядкована копія для людей, туди файл їде окремим кроком.
 */
#[ORM\Entity(repositoryClass: SupplyAttachmentRepository::class)]
#[ORM\Table(name: 'supply_attachment')]
#[ORM\Index(name: 'supply_attachment_request_idx', columns: ['request_id'])]
#[ORM\HasLifecycleCallbacks]
class SupplyAttachment
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SupplyRequest::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SupplyRequest $request;

    /** Якщо файл стосується конкретної покупки — накладна саме від цього постачальника. */
    #[ORM\ManyToOne(targetEntity: SupplyPurchase::class)]
    #[ORM\JoinColumn(name: 'purchase_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SupplyPurchase $purchase = null;

    #[ORM\Column(type: 'string', length: 16, enumType: AttachmentType::class, nullable: false)]
    private AttachmentType $type = AttachmentType::Other;

    /** Як файл називався в людини — показуємо саме це. */
    #[ORM\Column(name: 'original_name', type: 'string', length: 255, nullable: false)]
    private string $originalName;

    /** Шлях у сховищі. Ім'я генероване: оригінальне пускати у файлову систему не можна. */
    #[ORM\Column(name: 'storage_path', type: 'string', length: 512, unique: true, nullable: false)]
    private string $storagePath;

    #[ORM\Column(type: 'string', length: 128, nullable: false)]
    private string $mime;

    #[ORM\Column(type: 'integer', nullable: false)]
    private int $size;

    /** Для контролю цілісності й пошуку однакових файлів. */
    #[ORM\Column(type: 'string', length: 64, nullable: false)]
    private string $sha256;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $uploadedBy = null;

    /** Заповнюються після виїзду копії в Google Drive. */
    #[ORM\Column(name: 'drive_file_id', type: 'string', length: 128, nullable: true)]
    private ?string $driveFileId = null;

    #[ORM\Column(name: 'drive_url', type: 'string', length: 512, nullable: true)]
    private ?string $driveUrl = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): SupplyRequest
    {
        return $this->request;
    }

    public function setRequest(SupplyRequest $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function getPurchase(): ?SupplyPurchase
    {
        return $this->purchase;
    }

    public function setPurchase(?SupplyPurchase $purchase): self
    {
        $this->purchase = $purchase;

        return $this;
    }

    public function getType(): AttachmentType
    {
        return $this->type;
    }

    public function setType(AttachmentType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): self
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function setStoragePath(string $storagePath): self
    {
        $this->storagePath = $storagePath;

        return $this;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function setMime(string $mime): self
    {
        $this->mime = $mime;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function setSha256(string $sha256): self
    {
        $this->sha256 = $sha256;

        return $this;
    }

    public function getUploadedBy(): ?TelegramUser
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?TelegramUser $uploadedBy): self
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getDriveFileId(): ?string
    {
        return $this->driveFileId;
    }

    public function setDriveFileId(?string $driveFileId): self
    {
        $this->driveFileId = $driveFileId;

        return $this;
    }

    public function getDriveUrl(): ?string
    {
        return $this->driveUrl;
    }

    public function setDriveUrl(?string $driveUrl): self
    {
        $this->driveUrl = $driveUrl;

        return $this;
    }

    public function isMirrored(): bool
    {
        return $this->driveFileId !== null;
    }

    /** «2,4 МБ» — розмір, зрозумілий людині. */
    public function getSizeLabel(): string
    {
        if ($this->size < 1024) {
            return $this->size . ' Б';
        }

        if ($this->size < 1024 * 1024) {
            return number_format($this->size / 1024, 0, ',', ' ') . ' КБ';
        }

        return number_format($this->size / 1024 / 1024, 1, ',', ' ') . ' МБ';
    }

    /**
     * Ім'я файлу в Google Drive: тип і номер заявки спереду, щоб у папці
     * бухгалтер бачив, що це, не відкриваючи.
     */
    public function getDriveName(): string
    {
        $extension = pathinfo($this->originalName, PATHINFO_EXTENSION);

        return sprintf(
            '%s %s%s',
            $this->type->label(),
            str_replace('/', '-', $this->request->getNumber()),
            $extension !== '' ? '.' . strtolower($extension) : '',
        );
    }
}
