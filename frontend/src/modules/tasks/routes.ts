import type { RouteRecordRaw } from 'vue-router'

// Розділ «Задачі» вантажиться окремим шматком — заявкам він не потрібен.
const TasksHome = () => import('./TasksHome.vue')
const TaskBoard = () => import('./TaskBoard.vue')
const TaskView = () => import('./TaskView.vue')
const StaffPage = () => import('./StaffPage.vue')
const ProfilePage = () => import('./shell/ProfilePage.vue')
const ChronicleFeed = () => import('../plan/ChronicleFeed.vue')
const SearchPage = () => import('../plan/SearchPage.vue')

export const tasksRoutes: RouteRecordRaw[] = [
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
]
