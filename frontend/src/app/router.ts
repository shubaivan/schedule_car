import { createRouter, createWebHistory } from 'vue-router'
import RequestList from '../modules/supply/RequestList.vue'
import RequestCard from '../modules/supply/RequestCard.vue'
import ReportsPage from '../pages/ReportsPage.vue'
import SuppliersPage from '../pages/SuppliersPage.vue'
import UsersPage from '../pages/UsersPage.vue'
import DepartmentsPage from '../pages/DepartmentsPage.vue'
import FleetPage from '../pages/FleetPage.vue'
import SchedulePage from '../pages/SchedulePage.vue'

// Розділ «Задачі» вантажиться окремим шматком — заявкам він не потрібен.
const TasksHome = () => import('../modules/tasks/TasksHome.vue')
const TaskBoard = () => import('../modules/tasks/TaskBoard.vue')
const TaskView = () => import('../modules/tasks/TaskView.vue')
const StaffPage = () => import('../modules/tasks/StaffPage.vue')

export const router = createRouter({
    history: createWebHistory('/crm/'),
    routes: [
        { path: '/', name: 'requests', component: RequestList },
        { path: '/requests/:id', name: 'request', component: RequestCard, props: true },
        { path: '/suppliers', name: 'suppliers', component: SuppliersPage },
        { path: '/reports', name: 'reports', component: ReportsPage },
        { path: '/users', name: 'users', component: UsersPage },
        { path: '/departments', name: 'departments', component: DepartmentsPage },
        { path: '/fleet', name: 'fleet', component: FleetPage },
        { path: '/schedule', name: 'schedule', component: SchedulePage },
        { path: '/tasks', name: 'tasks', component: TasksHome },
        { path: '/tasks/board', name: 'tasks-board', component: TaskBoard },
        { path: '/tasks/:id(\\d+)', name: 'task', component: TaskView, props: true },
        { path: '/staff', name: 'staff', component: StaffPage },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
})
