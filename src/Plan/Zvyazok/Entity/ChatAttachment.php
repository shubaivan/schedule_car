<?php

namespace App\Plan\Zvyazok\Entity;

use App\Entity\TelegramUser;
use App\Plan\Drive\Entity\DriveSyncState;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Файл у повідомленні «Звʼязку» (Олександр 17.11): фото, офісні документи, PDF, до 20 МБ.
 * Фото стискаємо одразу при завантаженні (ZvyazokFiles: довша сторона до 1600 px, JPEG ~70%),
 * тож у сховищі лежить уже стиснена копія. Лежить поза public/ (var/storage/zvyazok),
 * віддає лише контролер «Звʼязку» учасникам чату. Копія на Google Диску — як у файлів
 * задач (DriveSyncState, DriveSource ZvyazokDriveFiles).
 *
 * Читання/запис — DBAL у ZvyazokFiles; сутність тримає схему.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_chat_attachment')]
#[ORM\Index(name: 'idx_plan_chat_attachment_message', columns: ['message_id'])]
#[ORM\Index(name: 'idx_plan_chat_attachment_chat', columns: ['chat_id'])]
// Черга plan:drive-sync — частковий індекс, як у task_attachment.
#[ORM\Index(name: 'idx_plan_chat_attachment_drive_queue', columns: ['id'], options: ['where' => "((drive_file_id IS NULL) AND ((drive_state)::text = 'pending'::text))"])]
class ChatAttachment
{
    use DriveSyncState;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatMessage::class)]
    #[ORM\JoinColumn(name: 'message_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ChatMessage $message;

    /** Дубль chat_id повідомлення — щоб перевірка членства не потребувала join. */
    #[ORM\ManyToOne(targetEntity: Chat::class)]
    #[ORM\JoinColumn(name: 'chat_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Chat $chat;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $uploadedBy = null;

    #[ORM\Column(name: 'original_name', type: Types::STRING, length: 255)]
    private string $originalName = '';

    #[ORM\Column(name: 'mime', type: Types::STRING, length: 127)]
    private string $mime = '';

    #[ORM\Column(name: 'size', type: Types::INTEGER)]
    private int $size = 0;

    /** Скільки важив файл до стиснення (фото); для решти — те саме, що size. */
    #[ORM\Column(name: 'original_size', type: Types::INTEGER, options: ['default' => 0])]
    private int $originalSize = 0;

    #[ORM\Column(name: 'sha256', type: Types::STRING, length: 64)]
    private string $sha256 = '';

    /** Шлях відносно сховища «Звʼязку». */
    #[ORM\Column(name: 'path', type: Types::STRING, length: 255, options: ['default' => ''])]
    private string $path = '';

    /** image | pdf | office */
    #[ORM\Column(name: 'kind', type: Types::STRING, length: 10)]
    private string $kind = 'office';

    #[ORM\Column(name: 'width', type: Types::INTEGER, nullable: true)]
    private ?int $width = null;

    #[ORM\Column(name: 'height', type: Types::INTEGER, nullable: true)]
    private ?int $height = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'drive_file_id', type: Types::STRING, length: 128, nullable: true)]
    private ?string $driveFileId = null;

    #[ORM\Column(name: 'drive_url', type: Types::STRING, length: 512, nullable: true)]
    private ?string $driveUrl = null;

    public function __construct(ChatMessage $message, Chat $chat)
    {
        $this->message = $message;
        $this->chat = $chat;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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
