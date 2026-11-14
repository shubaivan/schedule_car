import type { RouteRecordRaw } from 'vue-router'

const FilePage = () => import('./FilePage.vue')

/**
 * Спільні сторінки «Плану» (модулі — у власних modules/<модуль>/routes.ts).
 */
export const planRoutes: RouteRecordRaw[] = [
    // Файл задачі на весь екран — сторінкою, на яку можна дати посилання.
    { path: '/files/:id(\\d+)', name: 'file', component: FilePage, props: true, meta: { shell: 'plan', title: 'Файл' } },
]
