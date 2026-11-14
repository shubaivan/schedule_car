import type { RouteRecordRaw } from 'vue-router'

const Placeholder = () => import('./PlaceholderPage.vue')
const FilePage = () => import('./FilePage.vue')

/**
 * Пункти «Плану», модулі яких будують паралельні потоки (склад),
 * поки ведуть на сторінки-заглушки — щоб меню було цілим уже зараз.
 */
export const planRoutes: RouteRecordRaw[] = [
    { path: '/warehouse', name: 'warehouse', component: Placeholder, props: { kind: 'warehouse' }, meta: { shell: 'plan', title: 'Склад' } },
    // Файл задачі на весь екран — сторінкою, на яку можна дати посилання.
    { path: '/files/:id(\\d+)', name: 'file', component: FilePage, props: true, meta: { shell: 'plan', title: 'Файл' } },
]
