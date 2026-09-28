<?php

namespace App\Warehouse\Service;

use App\Entity\TelegramUser;
use App\Supply\Enum\SupplyRole;
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

    public static function canManage(?TelegramUser $user): bool
    {
        if ($user === null || ! $user->isApproved()) {
            return false;
        }

        return in_array($user->getSupplyRole(), [SupplyRole::Manager, SupplyRole::Admin, SupplyRole::Director], true);
    }
}
