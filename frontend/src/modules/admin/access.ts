import type { ApiUser } from '../../app/api'

/**
 * Пункт «Адмін» (журнал аудиту) — лише суперадміну: `audit.view` з PlanAccess.
 * Оболонка (рейка, «Ще») показує посилання на маршрут `admin` за цією умовою.
 */
export function canSeeAdmin(user: ApiUser | null | undefined): boolean {
    return user?.capabilities?.['audit.view'] === true
}

/**
 * Сторінка «Google Диск» (/crm/drive, стан копіювання) — суперадміну й адміністратору,
 * як на бекенді (ShareService::isDriveAdmin); іншим API відповідає 404.
 */
export function canSeeDrive(user: ApiUser | null | undefined): boolean {
    return canSeeAdmin(user) || user?.workRole === 'admin'
}

/**
 * Пункт «Склад» (рейка, «Ще») — з правом warehouse.view (інакше API складу відповідає 403):
 * хто веде склад, а також продажі й бухгалтерія — на читання.
 */
export function canSeeWarehouse(user: ApiUser | null | undefined): boolean {
    // warehouseEnabled — розділ увімкнено (WAREHOUSE_ENABLED); capabilities — право в «Плані».
    return user?.capabilities?.['warehouse.view'] === true && user?.warehouseEnabled === true
}

/** Записувати рухи й правити картки складу — лише тим, хто його веде (warehouse.edit). */
export function canEditWarehouse(user: ApiUser | null | undefined): boolean {
    return canSeeWarehouse(user) && user?.capabilities?.['warehouse.edit'] === true
}

/** Заявки на приєднання (вхід через Telegram) — лише суперадміну: `staff.join`. */
export function canSeeJoinRequests(user: ApiUser | null | undefined): boolean {
    return user?.capabilities?.['staff.join'] === true
}
