import { onBeforeUnmount, ref, type Ref } from 'vue'
import type { RouteLocationRaw, Router } from 'vue-router'

/**
 * Куди ведуть «Хроніка» й «Пошук» в оболонці. Окремі екрани для них
 * (стрічка подій і глобальний пошук) робляться паралельно; поки маршрутів
 * немає, ведемо на дошку: колонка «Хроніка» і поле фільтра.
 */
export const CHRONICLE_ROUTE = 'tasks-chronicle'
export const SEARCH_ROUTE = 'tasks-search'

export function chronicleTarget(router: Router): RouteLocationRaw {
    return router.hasRoute(CHRONICLE_ROUTE) ? { name: CHRONICLE_ROUTE } : { name: 'tasks-board', query: { col: 'chronicle' } }
}

export function searchTarget(router: Router, q = ''): RouteLocationRaw {
    const query = q ? { q } : {}
    return router.hasRoute(SEARCH_ROUTE) ? { name: SEARCH_ROUTE, query } : { name: 'tasks-board', query: { ...query, find: '1' } }
}

/** Телефон: одна колонка, задача на весь екран, нижня панель вкладок. */
export const MOBILE_QUERY = '(max-width: 760px)'

/** Реактивне «зараз телефон?» — для поведінки, яку CSS сам не вирішить (push чи replace, фокус). */
export function useMobile(): Ref<boolean> {
    const mq = window.matchMedia(MOBILE_QUERY)
    const on = ref(mq.matches)
    const change = (e: MediaQueryListEvent) => (on.value = e.matches)
    mq.addEventListener('change', change)
    onBeforeUnmount(() => mq.removeEventListener('change', change))
    return on
}

/** Подія «задачу створено/змінено» — дошка перечитує себе, хоч би де була форма. */
export const TASK_SAVED = 'plan:task-saved'
