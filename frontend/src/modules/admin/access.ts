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
