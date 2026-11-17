<?php

namespace App\Plan\Zvyazok\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Попередня версія відредагованого повідомлення «Звʼязку» (історія не змінюється). */
#[ORM\Entity]
#[ORM\Table(name: 'plan_chat_message_edit')]
#[ORM\Index(name: 'idx_plan_chat_message_edit_msg', columns: ['message_id'])]
class ChatMessageEdit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatMessage::class)]
    #[ORM\JoinColumn(name: 'message_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ChatMessage $message;

    #[ORM\Column(name: 'old_body', type: Types::TEXT)]
    private string $oldBody;

    #[ORM\Column(name: 'edited_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $editedAt;

    public function __construct(ChatMessage $message, string $oldBody)
    {
        $this->message = $message;
        $this->oldBody = $oldBody;
        $this->editedAt = new DateTimeImmutable();
    }
}
