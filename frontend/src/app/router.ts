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
const ProfilePage = () => import('../modules/tasks/shell/ProfilePage.vue')
const ChronicleFeed = () => import('../modules/tasks/ChronicleFeed.vue')
const SearchPage = () => import('../modules/tasks/SearchPage.vue')

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
        // Розділений екран: дошка зліва, відкрита задача справа (на телефоні — на весь екран).
        { path: '/tasks/board/:id(\\d+)?', name: 'tasks-board', component: TaskBoard },
        // Старі посилання (Telegram, «Огляд») відкривають задачу на дошці; окрема сторінка — /tasks/:id/full.
        { path: '/tasks/:id(\\d+)', name: 'task', redirect: (to) => ({ name: 'tasks-board', params: { id: to.params.id }, query: to.query }) },
        { path: '/tasks/:id(\\d+)/full', name: 'task-full', component: TaskView, props: true },
        { path: '/me', name: 'tasks-profile', component: ProfilePage },
        { path: '/tasks/chronicle/:id(\\d+)?', name: 'tasks-chronicle', component: ChronicleFeed },
        { path: '/tasks/search/:id(\\d+)?', name: 'tasks-search', component: SearchPage },
        { path: '/staff', name: 'staff', component: StaffPage },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
})
