import type { RouteRecordRaw } from 'vue-router'

const Placeholder = () => import('./PlaceholderPage.vue')
const FilePage = () => import('./FilePage.vue')

/**
 * Пункти «Плану», модулі яких будують паралельні потоки (сповіщення, аудит; склад — modules/warehouse),
 * поки ведуть на сторінки-заглушки — щоб меню було цілим уже зараз.
 */
export const planRoutes: RouteRecordRaw[] = [
    { path: '/notifications', name: 'notifications', component: Placeholder, props: { kind: 'notifications' }, meta: { shell: 'plan', title: 'Сповіщення' } },
    { path: '/admin', name: 'admin', component: Placeholder, props: { kind: 'admin' }, meta: { shell: 'plan', title: 'Адмін' } },
    // Файл задачі на весь екран — сторінкою, на яку можна дати посилання.
    { path: '/files/:id(\\d+)', name: 'file', component: FilePage, props: true, meta: { shell: 'plan', title: 'Файл' } },
]
