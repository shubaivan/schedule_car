import type { RouteRecordRaw } from 'vue-router'

const FilePage = () => import('../plan/FilePage.vue')
const DriveStatusPage = () => import('./DriveStatusPage.vue')

/**
 * Потік S5 — файли й Диск. Файл задачі — /files/:id (маршрут у plan/routes.ts),
 * документи заявок і складу — /files/supply/:id, /files/wh/:id; ?share=1 — одразу «Поділитися».
 * /drive — стан копіювання на Google Диск (суперадмін і адміністратор).
 */
export const filesRoutes: RouteRecordRaw[] = [
    { path: '/files/:type(supply|wh)/:id(\\d+)', name: 'file-typed', component: FilePage, props: true, meta: { shell: 'plan', title: 'Файл' } },
    { path: '/drive', name: 'drive-status', component: DriveStatusPage, meta: { shell: 'plan', title: 'Google Диск' } },
]
