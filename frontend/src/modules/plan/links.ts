import type { RouteLocationRaw, Router } from 'vue-router'
import type { SubjectRef } from './api'

/**
 * Куди веде суб'єкт із пошуку чи Хроніки, якщо це не задача (задача відкривається
 * праворуч у розділеному екрані самої сторінки).
 *
 * Заявка — у дошці заявок «Плану», коли вона є (потік S2), інакше стара картка;
 * людина — картка співробітника; склад — сторінки /sklad (поза SPA, повна адреса).
 */
export function subjectTarget(router: Router, subject: SubjectRef, url: string): { route: RouteLocationRaw } | { href: string } {
    const id = String(subject.id)

    if (subject.type === 'supply') {
        return { route: router.hasRoute('supply-board') ? { name: 'supply-board', params: { id } } : { name: 'request', params: { id } } }
    }

    if (subject.type === 'user') {
        return { route: router.hasRoute('staff-card') ? { name: 'staff-card', params: { id } } : { name: 'staff' } }
    }

    return { href: url }
}

export function go(router: Router, subject: SubjectRef, url: string): void {
    const target = subjectTarget(router, subject, url)
    if ('route' in target) router.push(target.route)
    else window.location.assign(target.href)
}

/** Адреса для <a href> (середня кнопка миші, «відкрити в новій вкладці»). */
export function hrefOf(router: Router, subject: SubjectRef, url: string): string {
    const target = subjectTarget(router, subject, url)
    return 'route' in target ? router.resolve(target.route).href : target.href
}
