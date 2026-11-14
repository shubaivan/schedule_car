import type { RouteRecordRaw } from 'vue-router'

// Центр і налаштування сповіщень (потік S1) — окремі сторінки, не модалки.
const NotificationsPage = () => import('../../plan/notifications/NotificationsPage.vue')
const SettingsPage = () => import('../../plan/notifications/SettingsPage.vue')

export const notificationsRoutes: RouteRecordRaw[] = [
    { path: '/notifications', name: 'notifications', component: NotificationsPage, meta: { shell: 'plan', title: 'Сповіщення' } },
    { path: '/notifications/settings', name: 'notifications-settings', component: SettingsPage, meta: { shell: 'plan', title: 'Налаштування сповіщень' } },
]
