<?php

namespace App\Warehouse\Service;

use App\Entity\TelegramUser;
use App\Supply\Enum\SupplyRole;
use App\Tasks\Enum\WorkRole;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Вимикач розділу «Склад» і правило, хто його веде.
 *
 * Каркас спільний на двох клієнтів, а склад поки потрібен лише одному, тож
 * розділ вмикається змінною оточення, як і автопарк.
 *
 * Вести склад (картки, рухи, документи) — справа менеджера, адміністратора й
 * директора. Сканувати наклейку може будь-який підтверджений працівник: йому
 * бот покаже, що це й де воно, але без цін.
 *
 * Дивитись склад у «Плані» (позиції, залишки, рухи, документи) можуть ще й
 * продажі та бухгалтерія — їм щодня потрібне «є на складі?» (ревʼю UX №6, 16.11.2026).
 * Лише на читання: записують і далі тільки ті, хто веде склад (canManage).
 */
class WarehouseSection
{
    public function __construct(
        #[Autowire('%warehouse_enabled%')]
        private bool $enabled = false,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Чи показувати людині кнопку «🏗 Склад» у головному меню. */
    public function inMenuFor(?TelegramUser $user): bool
    {
        return $this->enabled && self::canManage($user);
    }

    /** Ролі «Плану» (WorkRole), яким склад видно на читання, навіть якщо в постачанні вони робітники: продажі й бухгалтерія. */
    private const array READERS = [WorkRole::Manager, WorkRole::Accountant];

    /** Бачить склад у «Плані»: хто веде склад, а також продажі й бухгалтерія — на читання. */
    public static function canView(?TelegramUser $user): bool
    {
        if ($user === null || ! $user->isApproved()) {
            return false;
        }

        return self::canManage($user) || in_array($user->getWorkRole(), self::READERS, true);
    }

    public static function canManage(?TelegramUser $user): bool
    {
        if ($user === null || ! $user->isApproved()) {
            return false;
        }

        return in_array($user->getSupplyRole(), [SupplyRole::Manager, SupplyRole::Admin, SupplyRole::Director], true);
    }
}
