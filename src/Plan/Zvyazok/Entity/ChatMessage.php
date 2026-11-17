<?php

namespace App\Plan\Zvyazok\Entity;

use App\Entity\TelegramUser;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Повідомлення «Звʼязку». Редагування зберігає попередній текст у plan_chat_message_edit
 * (незмінна історія), видалення «для всіх» — м'яке: рядок лишається, текст ховаємо.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_chat_message')]
#[ORM\Index(name: 'idx_plan_chat_message_chat', columns: ['chat_id', 'id'])]
class ChatMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Chat::class)]
    #[ORM\JoinColumn(name: 'chat_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Chat $chat;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false)]
    private TelegramUser $author;

    #[ORM\Column(name: 'body', type: Types::TEXT)]
    private string $body;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'reply_to_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $replyTo = null;

    /** Кого згадано (@Імʼя) — id людей, для «вас згадали» в лівій колонці. */
    #[ORM\Column(name: 'mentions', type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $mentions = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'edited_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $editedAt = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    public function __construct(Chat $chat, TelegramUser $author, string $body)
    {
        $this->chat = $chat;
        $this->author = $author;
        $this->body = $body;
        $this->createdAt = new DateTimeImmutable();
    }
}
