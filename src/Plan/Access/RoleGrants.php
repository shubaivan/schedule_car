<?php

namespace App\Plan\Access;

use App\Tasks\Enum\WorkRole;
use Closure;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Throwable;

/**
 * Поточні права ролей (RolePermission) з урахуванням змін адміністратора (plan_role_permission).
 *
 * Чому статично: правила ролей питають сутність і enum (TelegramUser::seesAllTasks(),
 * WorkRole::decides() — десятки місць у задачах, пошуку, Хроніці), куди сервіс не передати.
 * Таблицю читає RolePermissions (loader) — ліниво, раз на запит / команду / повідомлення
 * воркера (RoleGrantsReset скидає кеш). Без loader-а (юніт-тести, стенди) або при помилці
 * таблиці (ще не мігровано) — значення за замовчуванням; інша помилка бази — усе незафіксоване «ні». Зафіксовані клітинки
 * (RolePermission::locked) таблиця не перекриває ніколи.
 */
final class RoleGrants
{
    /** @var array<string, array<string, bool>>|null role => permission => allowed */
    private static ?array $overrides = null;

    /** @var (Closure(): array<string, array<string, bool>>)|null */
    private static ?Closure $loader = null;

    public static function allows(WorkRole $role, RolePermission $permission): bool
    {
        $locked = $permission->locked($role);

        if ($locked !== null) {
            return $locked;
        }

        return self::overrides()[$role->value][$permission->value] ?? $permission->byDefault($role);
    }

    /** @param Closure(): array<string, array<string, bool>> $loader */
    public static function useLoader(Closure $loader): void
    {
        self::$loader = $loader;
        self::$overrides = null;
    }

    /** Наступне питання знову прочитає таблицю (новий запит, зміна прав). */
    public static function reset(): void
    {
        self::$overrides = null;
    }

    /** Лише для тестів: прибрати loader — лише значення за замовчуванням. */
    public static function forget(): void
    {
        self::$loader = null;
        self::$overrides = null;
    }

    /** @return array<string, array<string, bool>> усе «ні» */
    private static function closed(): array
    {
        $out = [];

        foreach (WorkRole::cases() as $role) {
            foreach (RolePermission::cases() as $permission) {
                $out[$role->value][$permission->value] = false;
            }
        }

        return $out;
    }

    /** @return array<string, array<string, bool>> */
    private static function overrides(): array
    {
        if (self::$overrides !== null) {
            return self::$overrides;
        }

        if (self::$loader === null) {
            return [];
        }

        try {
            return self::$overrides = (self::$loader)();
        } catch (TableNotFoundException) {
            // Міграція ще не пройшла (секунди деплою) — змін ще й не могло бути: права з коду.
            return [];
        } catch (Throwable) {
            // Інша помилка бази — закрито: незафіксовані права «ні» (не відкриваємо те, що адмін закрив).
            return self::closed();
        }
    }
}
