<?php

namespace App\Entity;

use App\Repository\CrmLoginRepository;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Один вхід у CRM чи склад — вдалий чи ні.
 *
 * Паролів немає, вхід лише за одноразовим посиланням із бота, тож «невдалий
 * вхід» тут — це прострочене чи вже використане посилання. Сам по собі він
 * не тривога (посилання живе 15 хвилин, люди відкривають його пізніше), але
 * десяток таких за день від невідомого пристрою — це те, що варто побачити.
 *
 * Ім'я записуємо рядком поруч із посиланням на людину: людину можуть
 * прибрати, а рядок журналу має лишитись читабельним.
 */
#[ORM\Entity(repositoryClass: CrmLoginRepository::class)]
#[ORM\Table(name: 'crm_login')]
#[ORM\Index(name: 'crm_login_at_idx', columns: ['at'])]
class CrmLogin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $user;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name;

    #[ORM\Column(type: 'boolean', nullable: false)]
    private bool $success;

    /** Чому не пустило — текст, який побачила людина. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $reason;

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ip;

    #[ORM\Column(name: 'user_agent', type: 'string', length: 500, nullable: true)]
    private ?string $userAgent;

    /** Куди людина йшла: /crm, /sklad/items/5… */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $target;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private DateTime $at;

    public function __construct(
        ?TelegramUser $user,
        bool $success,
        ?string $reason,
        ?string $ip,
        ?string $userAgent,
        ?string $target,
    ) {
        $this->user = $user;
        $this->name = $user?->displayName();
        $this->success = $success;
        $this->reason = $reason !== null ? mb_substr($reason, 0, 255) : null;
        $this->ip = $ip;
        $this->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 500) : null;
        $this->target = $target !== null ? mb_substr($target, 0, 255) : null;
        $this->at = new DateTime('now', new DateTimeZone('Europe/Kyiv'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?TelegramUser
    {
        return $this->user;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    public function getAt(): DateTime
    {
        return $this->at;
    }

    /** «iPhone · Safari», «Windows · Chrome» — пристрій, зрозумілий людині. */
    public function getDevice(): string
    {
        $ua = (string) $this->userAgent;

        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            str_contains($ua, 'Telegram') => 'Telegram',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        $parts = array_filter([$os, $browser]);

        return $parts !== [] ? implode(' · ', $parts) : ($ua !== '' ? 'інше' : '—');
    }
}
