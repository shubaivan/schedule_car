import type { RouteRecordRaw } from 'vue-router'
import ReportsPage from '../../pages/ReportsPage.vue'
import SuppliersPage from '../../pages/SuppliersPage.vue'
import UsersPage from '../../pages/UsersPage.vue'
import DepartmentsPage from '../../pages/DepartmentsPage.vue'
import FleetPage from '../../pages/FleetPage.vue'
import SchedulePage from '../../pages/SchedulePage.vue'

/** Довідники заявок і автопарк — стара шапка, як на стендах заявок. */
export const classicRoutes: RouteRecordRaw[] = [
    { path: '/suppliers', name: 'suppliers', component: SuppliersPage },
    { path: '/reports', name: 'reports', component: ReportsPage },
    { path: '/users', name: 'users', component: UsersPage },
    { path: '/departments', name: 'departments', component: DepartmentsPage },
    { path: '/fleet', name: 'fleet', component: FleetPage },
    { path: '/schedule', name: 'schedule', component: SchedulePage },
]
