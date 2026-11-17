<?php

namespace App\Plan\Access;

use App\Tasks\Enum\WorkRole;

/**
 * Права ролі в «Плані», які адміністратор бачить і змінює на сторінці «Ролі й доступ».
 *
 * Це та сама модель, що й раніше жила в коді (WorkRole::seesAllTasks/editsAnyTask/decides/
 * managesStaff і частина PlanAccess), — значення за замовчуванням нижче дорівнюють старим,
 * тож без жодної зміни в таблиці plan_role_permission поведінка не змінюється.
 *
 * Деякі клітинки зафіксовані (locked) і не змінюються з інтерфейсу:
 * - «Системний адміністратор» ніколи не бачить задач і «Звʼязку» (Олександр 17.11) і завжди
 *   веде людей — інакше роль втрачає сенс;
 * - адміністратор завжди веде людей — щоб не замкнути себе поза налаштуваннями.
 */
enum RolePermission: string
{
    /** Задачі й угоди взагалі (розділ, пошук, Хроніка, сповіщення). */
    case TasksAccess = 'tasks.access';
    /** Бачить усі задачі заводу, а не лише ті, де учасник. */
    case TasksSeeAll = 'tasks.seeAll';
    /** Править будь-яку задачу й рухає її статуси. */
    case TasksEditAny = 'tasks.editAny';
    /** Рішення директора: погодити, відхилити, відкласти. */
    case TasksDecide = 'tasks.decide';
    /** «Звʼязок» (месенджер) — якщо його відкрито людині (до погодження — повний доступ). */
    case Zvyazok = 'zvyazok.use';
    /** Веде людей, відділи, кошик. */
    case StaffManage = 'staff.manage';
    /** Отримує ліди (AI-консультант сайту). */
    case LeadsReceive = 'leads.receive';
    case CounterpartiesView = 'counterparties.view';
    case CounterpartiesEdit = 'counterparties.edit';
    /** Умови оплати, відстрочка, кредитний ліміт, архів контрагентів. */
    case CounterpartiesFinance = 'counterparties.finance';
    /**
     * Приховані розділи (Постачання, Склад, Контрагенти, Автопарк, Диск) усій ролі.
     * Олександр 17.11: повний доступ видає суперадмін — тож і цю клітинку змінює лише він.
     */
    case SectionsFull = 'sections.full';

    public function label(): string
    {
        return match ($this) {
            self::TasksAccess => 'Задачі й угоди',
            self::TasksSeeAll => 'Бачить усі задачі заводу',
            self::TasksEditAny => 'Править будь-яку задачу',
            self::TasksDecide => 'Рішення директора в задачах',
            self::Zvyazok => '«Звʼязок» (чати)',
            self::StaffManage => 'Веде людей і відділи',
            self::LeadsReceive => 'Отримує ліди з сайту',
            self::CounterpartiesView => 'Контрагенти: бачить',
            self::CounterpartiesEdit => 'Контрагенти: заводить і править',
            self::CounterpartiesFinance => 'Контрагенти: гроші й архів',
            self::SectionsFull => 'Приховані розділи (Постачання, Склад, Контрагенти, Автопарк, Диск)',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::TasksAccess, self::TasksSeeAll, self::TasksEditAny, self::TasksDecide => 'Задачі',
            self::Zvyazok => 'Звʼязок',
            self::StaffManage => 'Люди',
            self::LeadsReceive, self::CounterpartiesView, self::CounterpartiesEdit, self::CounterpartiesFinance => 'Продажі й контрагенти',
            self::SectionsFull => 'Розділи',
        };
    }

    /** Значення за замовчуванням — те, що було в коді до сторінки «Ролі й доступ». */
    public function byDefault(WorkRole $role): bool
    {
        return $this->locked($role) ?? match ($this) {
            self::TasksAccess, self::Zvyazok => true,
            self::TasksSeeAll, self::TasksDecide => in_array($role, [WorkRole::Admin, WorkRole::Director], true),
            self::TasksEditAny => in_array($role, [WorkRole::Admin, WorkRole::Manager], true),
            self::StaffManage => $role === WorkRole::Admin,
            self::LeadsReceive => in_array($role, [WorkRole::Manager, WorkRole::Director, WorkRole::Admin], true),
            self::CounterpartiesView, self::CounterpartiesEdit => in_array($role, [WorkRole::Admin, WorkRole::Director, WorkRole::Manager, WorkRole::Accountant, WorkRole::SysAdmin], true),
            self::CounterpartiesFinance => in_array($role, [WorkRole::Admin, WorkRole::Director, WorkRole::Accountant, WorkRole::SysAdmin], true),
            self::SectionsFull => false,
        };
    }

    /** Зафіксоване значення (не змінюється з інтерфейсу) або null — можна змінювати. */
    public function locked(WorkRole $role): ?bool
    {
        if ($role === WorkRole::SysAdmin) {
            return match ($this) {
                self::TasksAccess, self::TasksSeeAll, self::TasksEditAny, self::TasksDecide, self::Zvyazok => false,
                self::StaffManage => true,
                default => null,
            };
        }

        return $role === WorkRole::Admin && $this === self::StaffManage ? true : null;
    }

    /** Чому клітинка зафіксована — підказка в інтерфейсі. */
    public function lockReason(WorkRole $role): ?string
    {
        return match (true) {
            $this->locked($role) === null => null,
            $role === WorkRole::SysAdmin && $this === self::StaffManage => 'Системний адміністратор завжди веде людей і налаштування.',
            $role === WorkRole::SysAdmin => 'Системний адміністратор не бачить задач і чатів.',
            default => 'Адміністратор завжди веде людей — щоб не замкнути себе поза налаштуваннями.',
        };
    }

    /** Клітинку може змінити лише суперадмін (не адмін і не системний адміністратор). */
    public function superadminOnly(): bool
    {
        // «Веде людей» дає заводити картки — через неї роль обходила б «Ролі й доступ».
        return $this === self::SectionsFull || $this === self::StaffManage;
    }
}
