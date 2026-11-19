<?php

namespace App\Tasks\Enum;

use App\Plan\Access\RoleGrants;
use App\Plan\Access\RolePermission;
use App\Supply\Enum\SupplyRole;

/**
 * Роль людини на «робочому місці» (модуль задач).
 *
 * Вимога клієнта від 13.11.2026: у кожної ролі своя функціональність і права —
 * продажі, бухгалтерія й виробництво бачать різне. Роль постачання (SupplyRole)
 * лишається окремою: у заявках людина може бути робітником, а в задачах — менеджером.
 *
 * Що саме може роль — RolePermission (значення з коду + зміни на сторінці «Ролі й доступ»,
 * RoleGrants). Системний адміністратор (Олександр 17.11) — усе, що адміністратор, крім
 * задач і «Звʼязку»: їх не бачить ніде (API, пошук, Хроніка, сповіщення).
 */
enum WorkRole: string
{
    case Admin = 'admin';
    case Director = 'director';
    case Manager = 'manager';
    case Accountant = 'accountant';
    case Production = 'production';
    case SysAdmin = 'sysadmin';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Адміністратор',
            self::Director => 'Директор',
            self::Manager => 'Менеджер з продажу',
            self::Accountant => 'Бухгалтер',
            self::Production => 'Виробництво',
            self::SysAdmin => 'Системний адміністратор',
        };
    }

    /** Хто бачить усі задачі заводу, а не лише ті, де він учасник. */
    public function seesAllTasks(): bool
    {
        return $this->seesTasks() && RoleGrants::allows($this, RolePermission::TasksSeeAll);
    }

    /** Задачі й угоди взагалі. Системний адміністратор — ні (зафіксовано). */
    public function seesTasks(): bool
    {
        return RoleGrants::allows($this, RolePermission::TasksAccess);
    }

    /** Веде людей, відділи й кошик. */
    public function managesStaff(): bool
    {
        return RoleGrants::allows($this, RolePermission::StaffManage);
    }

    /** Рішення директора: погодити, відхилити, відкласти. Адмін — запасний ключ. */
    public function decides(): bool
    {
        return $this->seesTasks() && RoleGrants::allows($this, RolePermission::TasksDecide);
    }

    /** Править будь-яку задачу (назву, учасників, мітки) і рухає її статуси. */
    public function editsAnyTask(): bool
    {
        return $this->seesTasks() && RoleGrants::allows($this, RolePermission::TasksEditAny);
    }

    /** Видаляє задачі, які бачить (у кошик на 30 днів), і відновлює їх звідти. */
    public function deletesTasks(): bool
    {
        return $this->seesTasks() && RoleGrants::allows($this, RolePermission::TasksDelete);
    }

    /** Керує ролями й правами (сторінка «Ролі й доступ») — фіксовано, не налаштовується. */
    public function managesRoles(): bool
    {
        return $this === self::Admin || $this === self::SysAdmin;
    }

    /** Роль для тих, кому її ще не призначили: виводимо з ролі в заявках. */
    public static function fromSupply(SupplyRole $role): self
    {
        return match ($role) {
            SupplyRole::Admin => self::Admin,
            SupplyRole::Director => self::Director,
            SupplyRole::Manager => self::Manager,
            SupplyRole::Worker => self::Production,
        };
    }
}
