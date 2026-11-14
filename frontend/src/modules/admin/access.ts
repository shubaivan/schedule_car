import type { ApiUser } from '../../app/api'

/**
 * Пункт «Адмін» (журнал аудиту) — лише суперадміну: `audit.view` з PlanAccess.
 * Оболонка (рейка, «Ще») показує посилання на маршрут `admin` за цією умовою.
 */
export function canSeeAdmin(user: ApiUser | null | undefined): boolean {
    return user?.capabilities?.['audit.view'] === true
}
