<?php

namespace App\Plan\Presence\Entity;

use App\Entity\TelegramUser;
use App\Plan\Presence\PresenceSection;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * «Хто зараз у Плані» (ТЗ §24; як portal_presence у PMAP): один рядок на людину —
 * у якому розділі вона була востаннє і коли. Пише лише PresenceTracker
 * (INSERT … ON CONFLICT, раз на хвилину з видимої вкладки), не кожен GET.
 * Що саме людина відкрила (задачу, заявку), не зберігаємо — лише розділ.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'plan_presence')]
#[ORM\Index(name: 'idx_plan_presence_seen', columns: ['last_seen_at'])]
class PlanPresence
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private TelegramUser $user;

    #[ORM\Column(type: Types::STRING, length: 24, enumType: PresenceSection::class)]
    private PresenceSection $section;

    /** З часовим поясом: «3 хвилини» не ламаються, коли годинник переводять. */
    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $lastSeenAt;

    public function __construct(TelegramUser $user, PresenceSection $section, DateTimeImmutable $lastSeenAt)
    {
        $this->user = $user;
        $this->section = $section;
        $this->lastSeenAt = $lastSeenAt;
    }

    public function getUser(): TelegramUser
    {
        return $this->user;
    }

    public function getSection(): PresenceSection
    {
        return $this->section;
    }

    public function getLastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
