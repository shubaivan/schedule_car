import type { ApiUser } from '../../app/api'
import { SessionExpired } from '../../app/api'

/** Пункт «Контрагенти» (рейка, «Ще») — з правом counterparties.view (інакше API відповідає 403). */
export function canSeeCounterparties(user: ApiUser | null | undefined): boolean {
    return user?.capabilities?.['counterparties.view'] === true
}

/**
 * Чесна відповідь замість порожнього списку: контрагентів ведуть продажі, бухгалтерія,
 * постачання й керівництво; на стенді заявок «Плану» немає (404).
 */
export function accessError(e: unknown): string {
    if (e instanceof SessionExpired || !(e instanceof Error)) return ''
    if (/\b403\b|Access Denied/i.test(e.message))
        return 'Контрагентів ведуть продажі, бухгалтерія, постачання й керівництво. Якщо вам потрібен доступ — попросіть адміністратора змінити роль.'
    if (/\b404\b/.test(e.message)) return 'На цьому стенді довідника контрагентів немає.'
    return ''
}
