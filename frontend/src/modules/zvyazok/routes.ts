import type { RouteRecordRaw } from 'vue-router'

const ZvyazokPage = () => import('./ZvyazokPage.vue')

/** «Звʼязок»: список чатів ліворуч, розмова праворуч (/zvyazok/12); на телефоні — по черзі. */
export const zvyazokRoutes: RouteRecordRaw[] = [{ path: '/zvyazok/:id(\\d+)?', name: 'zvyazok', component: ZvyazokPage, meta: { shell: 'plan', title: 'Звʼязок' } }]
