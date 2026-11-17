<?php

namespace App\Plan\Access\Entity;

use App\Entity\TelegramUser;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Зміна права ролі адміністратором (сторінка «Ролі й доступ»). Рядка немає — діє значення
 * з коду (RolePermission::byDefault). Читання/запис — DBAL у RolePermissions; сутність тримає схему.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_role_permission')]
class RolePermissionRow
{
    #[ORM\Id]
    #[ORM\Column(name: 'role', type: Types::STRING, length: 16)]
    private string $role;

    #[ORM\Id]
    #[ORM\Column(name: 'permission', type: Types::STRING, length: 32)]
    private string $permission;

    #[ORM\Column(name: 'allowed', type: Types::BOOLEAN)]
    private bool $allowed = false;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $updatedBy = null;

    public function __construct(string $role, string $permission)
    {
        $this->role = $role;
        $this->permission = $permission;
        $this->updatedAt = new DateTimeImmutable();
    }
}
