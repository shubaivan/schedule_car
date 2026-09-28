<?php

namespace App\Warehouse\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Entity\TelegramUser;
use App\Warehouse\Enum\DocumentType;
use App\Warehouse\Repository\WhDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Документ складу: договір клієнта, накладна руху, паспорт техніки.
 *
 * Прив'язується до того, чого стосується, — клієнта, об'єкта, позиції чи руху
 * (рівно одного). Файл лежить у нашому сховищі поза public/, а на Google Диск
 * їде копія, розкладена по клієнтах і об'єктах, — так само, як у заявках.
 */
#[ORM\Entity(repositoryClass: WhDocumentRepository::class)]
#[ORM\Table(name: 'wh_document')]
#[ORM\Index(name: 'wh_document_client_idx', columns: ['client_id'])]
#[ORM\Index(name: 'wh_document_site_idx', columns: ['site_id'])]
#[ORM\Index(name: 'wh_document_item_idx', columns: ['item_id'])]
#[ORM\Index(name: 'wh_document_movement_idx', columns: ['movement_id'])]
#[ORM\HasLifecycleCallbacks]
class WhDocument
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 16, enumType: DocumentType::class, nullable: false)]
    private DocumentType $type = DocumentType::Other;

    #[ORM\ManyToOne(targetEntity: WhClient::class)]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?WhClient $client = null;

    #[ORM\ManyToOne(targetEntity: WhSite::class)]
    #[ORM\JoinColumn(name: 'site_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?WhSite $site = null;

    #[ORM\ManyToOne(targetEntity: WhItem::class)]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?WhItem $item = null;

    #[ORM\ManyToOne(targetEntity: WhMovement::class)]
    #[ORM\JoinColumn(name: 'movement_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?WhMovement $movement = null;

    #[ORM\Column(name: 'original_name', type: 'string', length: 255, nullable: false)]
    private string $originalName;

    #[ORM\Column(name: 'storage_path', type: 'string', length: 512, unique: true, nullable: false)]
    private string $storagePath;

    #[ORM\Column(type: 'string', length: 128, nullable: false)]
    private string $mime;

    #[ORM\Column(type: 'integer', nullable: false)]
    private int $size;

    #[ORM\Column(type: 'string', length: 64, nullable: false)]
    private string $sha256;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $uploadedBy = null;

    #[ORM\Column(name: 'drive_file_id', type: 'string', length: 128, nullable: true)]
    private ?string $driveFileId = null;

    #[ORM\Column(name: 'drive_url', type: 'string', length: 512, nullable: true)]
    private ?string $driveUrl = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): DocumentType
    {
        return $this->type;
    }

    public function setType(DocumentType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getClient(): ?WhClient
    {
        return $this->client;
    }

    public function getSite(): ?WhSite
    {
        return $this->site;
    }

    public function getItem(): ?WhItem
    {
        return $this->item;
    }

    public function getMovement(): ?WhMovement
    {
        return $this->movement;
    }

    /** Прив'язка рівно до одного власника — інакше незрозуміло, в яку теку Диска класти. */
    public function attachTo(WhClient|WhSite|WhItem|WhMovement $owner): self
    {
        $this->client = $owner instanceof WhClient ? $owner : null;
        $this->site = $owner instanceof WhSite ? $owner : null;
        $this->item = $owner instanceof WhItem ? $owner : null;
        $this->movement = $owner instanceof WhMovement ? $owner : null;

        return $this;
    }

    public function getOwner(): WhClient|WhSite|WhItem|WhMovement|null
    {
        return $this->client ?? $this->site ?? $this->item ?? $this->movement;
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

    public function getDriveUrl(): ?string
    {
        return $this->driveUrl;
    }

    public function markMirrored(string $fileId, ?string $url): self
    {
        $this->driveFileId = $fileId;
        $this->driveUrl = $url;

        return $this;
    }

    public function isMirrored(): bool
    {
        return $this->driveFileId !== null;
    }

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

    /** «Договір — Договір оренди 15.pdf»: тип спереду, щоб у теці було видно, що це. */
    public function getDriveName(): string
    {
        return sprintf('%s — %s', $this->type->label(), $this->originalName);
    }
}
