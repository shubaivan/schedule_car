<?php

namespace App\Plan\Login\Entity;

use App\Entity\TelegramUser;
use App\Plan\Login\JoinRequestStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Заявка на приєднання до «Плану»: людина відкрила бота, поділилась номером, а
 * такого номера серед співробітників немає. Суперадміни затверджують (обравши роль
 * і відділ) або відхиляють — у боті кнопками або в Плані (/crm/admin/join).
 * Ім'я — з Telegram, телефон — з контакту, який Telegram підтвердив (contact.user_id = from.id).
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_join_request')]
#[ORM\Index(name: 'idx_plan_join_request_status', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_plan_join_request_tg', columns: ['telegram_id', 'created_at'])]
class JoinRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'telegram_id', type: Types::STRING, length: 32)]
    private string $telegramId;

    #[ORM\Column(name: 'chat_id', type: Types::STRING, length: 32, nullable: true)]
    private ?string $chatId = null;

    #[ORM\Column(name: 'first_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(name: 'last_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(name: 'username', type: Types::STRING, length: 255, nullable: true)]
    private ?string $username = null;

    /** Лише цифри, як telegram_user.phone_number. */
    #[ORM\Column(name: 'phone', type: Types::STRING, length: 32)]
    private string $phone;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 16, enumType: JoinRequestStatus::class, options: ['default' => 'pending'])]
    private JoinRequestStatus $status = JoinRequestStatus::Pending;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $decidedBy = null;

    /** Співробітник, якого заведено при затвердженні. */
    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $user = null;

    #[ORM\Column(name: 'reason', type: Types::STRING, length: 255, nullable: true)]
    private ?string $reason = null;

    public function __construct(string $telegramId, ?string $chatId, ?string $firstName, ?string $lastName, ?string $username, string $phone, DateTimeImmutable $createdAt)
    {
        $this->telegramId = $telegramId;
        $this->chatId = $chatId;
        $this->firstName = self::clip($firstName);
        $this->lastName = self::clip($lastName);
        $this->username = self::clip($username);
        $this->phone = $phone;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTelegramId(): string
    {
        return $this->telegramId;
    }

    public function getChatId(): ?string
    {
        return $this->chatId;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getStatus(): JoinRequestStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecidedBy(): ?TelegramUser
    {
        return $this->decidedBy;
    }

    public function getUser(): ?TelegramUser
    {
        return $this->user;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function name(): string
    {
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $name !== '' ? $name : ($this->username !== null ? '@' . $this->username : 'Без імені');
    }

    public function approve(TelegramUser $user, ?TelegramUser $by, DateTimeImmutable $at): void
    {
        $this->status = JoinRequestStatus::Approved;
        $this->user = $user;
        $this->decidedBy = $by;
        $this->decidedAt = $at;
    }

    public function reject(?TelegramUser $by, ?string $reason, DateTimeImmutable $at): void
    {
        $this->status = JoinRequestStatus::Rejected;
        $this->decidedBy = $by;
        $this->decidedAt = $at;
        $this->reason = self::clip($reason);
    }

    private static function clip(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== null && $value !== '' ? mb_substr($value, 0, 255) : null;
    }
}
