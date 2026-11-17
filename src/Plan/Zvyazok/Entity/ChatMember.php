<?php

namespace App\Plan\Zvyazok\Entity;

use App\Entity\TelegramUser;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Учасник чату «Звʼязку» з особистими налаштуваннями: закріпити, архів, до якого
 * повідомлення прочитано (з нього — непрочитані й галочки «прочитано»). left_at —
 * вийшов або його прибрали: старі повідомлення лишаються, нових не бачить.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_chat_member')]
#[ORM\Index(name: 'idx_plan_chat_member_user', columns: ['user_id'])]
class ChatMember
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Chat::class)]
    #[ORM\JoinColumn(name: 'chat_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Chat $chat;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $user;

    #[ORM\Column(name: 'role', type: Types::STRING, length: 8, options: ['default' => 'member'])]
    private string $role = 'member';

    #[ORM\Column(name: 'pinned', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $pinned = false;

    #[ORM\Column(name: 'archived', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $archived = false;

    #[ORM\Column(name: 'last_read_id', type: Types::INTEGER, options: ['default' => 0])]
    private int $lastReadId = 0;

    #[ORM\Column(name: 'joined_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $joinedAt;

    #[ORM\Column(name: 'left_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $leftAt = null;

    public function __construct(Chat $chat, TelegramUser $user)
    {
        $this->chat = $chat;
        $this->user = $user;
        $this->joinedAt = new DateTimeImmutable();
    }
}
