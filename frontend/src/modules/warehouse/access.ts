import { SessionExpired } from '../../app/api'

/**
 * Чесна відповідь замість порожнього списку: склад ведуть менеджер, адміністратор
 * і директор (як /sklad), а на стенді без складу (WAREHOUSE_ENABLED=0) його немає.
 * Інша помилка — '' (її показує звичайний банер).
 */
export function accessError(e: unknown): string {
    if (e instanceof SessionExpired || !(e instanceof Error)) return ''
    if (/\b403\b|Access Denied/i.test(e.message)) return 'Склад ведуть менеджер, адміністратор і директор. Якщо вам потрібен доступ — попросіть адміністратора змінити роль.'
    if (/\b404\b/.test(e.message)) return 'На цьому стенді розділ «Склад» вимкнено.'
    return ''
}
