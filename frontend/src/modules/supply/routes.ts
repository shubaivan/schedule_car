import type { RouteRecordRaw } from 'vue-router'
import RequestList from './RequestList.vue'
import RequestCard from './RequestCard.vue'

const SupplyBoard = () => import('./SupplyBoard.vue')
const SupplyView = () => import('./SupplyView.vue')
const SupplyFormPage = () => import('./SupplyFormPage.vue')

export const supplyRoutes: RouteRecordRaw[] = [
    // Стенди заявок (CRM_HOME=requests): стара таблиця й картка — як були.
    { path: '/', name: 'requests', component: RequestList },
    { path: '/requests/:id', name: 'request', component: RequestCard, props: true },
    // «План»: заявки в тій самій оболонці, що й задачі — список ліворуч, заявка праворуч.
    { path: '/supply/board/:id(\\d+)?', name: 'supply-board', component: SupplyBoard, meta: { shell: 'plan', title: 'Заявки' } },
    { path: '/supply/:id(\\d+)/full', name: 'supply-full', component: SupplyView, props: true, meta: { shell: 'plan', title: 'Заявка' } },
    // Нова заявка — окрема сторінка (і на стенді заявок, і в «Плані»), а не форма над таблицею.
    { path: '/supply/new', name: 'supply-new', component: SupplyFormPage, meta: { shell: 'auto', title: 'Нова заявка' } },
]
