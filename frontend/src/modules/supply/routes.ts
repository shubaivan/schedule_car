import type { RouteRecordRaw } from 'vue-router'
import RequestList from './RequestList.vue'
import RequestCard from './RequestCard.vue'
import ReportsPage from '../../pages/ReportsPage.vue'
import SuppliersPage from '../../pages/SuppliersPage.vue'
import UsersPage from '../../pages/UsersPage.vue'
import DepartmentsPage from '../../pages/DepartmentsPage.vue'

// Заявки — головний розділ стендів zayavky.*: вантажаться одразу, без окремого шматка.
export const supplyRoutes: RouteRecordRaw[] = [
    { path: '/', name: 'requests', component: RequestList },
    { path: '/requests/:id', name: 'request', component: RequestCard, props: true },
    { path: '/suppliers', name: 'suppliers', component: SuppliersPage },
    { path: '/reports', name: 'reports', component: ReportsPage },
    { path: '/users', name: 'users', component: UsersPage },
    { path: '/departments', name: 'departments', component: DepartmentsPage },
]
