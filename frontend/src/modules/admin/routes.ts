import type { RouteRecordRaw } from 'vue-router'

// Адмін-розділ «Плану» — журнал аудиту суперадміна; окремий шматок, решті він не потрібен.
const AuditPage = () => import('./AuditPage.vue')

const plan = { shell: 'plan' as const }

export const adminRoutes: RouteRecordRaw[] = [
    { path: '/admin', name: 'admin', redirect: { name: 'admin-audit' } },
    // Подія — окремий маршрут (не модалка): на десктопі праворуч від журналу, на телефоні на весь екран.
    // Фільтри й сторінка журналу — у ?query, тож «Назад» повертає той самий зріз.
    { path: '/admin/audit/:id(\\d+)?', name: 'admin-audit', component: AuditPage, meta: { ...plan, title: 'Журнал аудиту' } },
]
