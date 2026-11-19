import type { RouteRecordRaw } from 'vue-router'

// Розділ «Задачі» вантажиться окремим шматком — заявкам на стендах він не потрібен.
const TasksHome = () => import('./TasksHome.vue')
const TaskBoard = () => import('./TaskBoard.vue')
const TaskView = () => import('./TaskView.vue')
const TaskFormPage = () => import('./TaskFormPage.vue')
const StaffPage = () => import('./StaffPage.vue')
const ProfilePage = () => import('./shell/ProfilePage.vue')
const ChronicleFeed = () => import('../plan/ChronicleFeed.vue')
const SearchPage = () => import('../plan/SearchPage.vue')
const TaskListPage = () => import('./TaskListPage.vue')
const TaskTrashPage = () => import('./TaskTrashPage.vue')

const plan = { shell: 'plan' as const }

export const tasksRoutes: RouteRecordRaw[] = [
    { path: '/tasks', name: 'tasks', component: TasksHome, meta: { ...plan, title: 'Огляд' } },
    // Розділений екран: дошка зліва, відкрита задача справа (на телефоні — на весь екран).
    { path: '/tasks/board/:id(\\d+)?', name: 'tasks-board', component: TaskBoard, meta: { ...plan, title: 'Задачі' } },
    // Список задач під фільтром — куди ведуть числа «Огляду» (?show=overdue&dep=3); задача праворуч.
    { path: '/tasks/list/:id(\\d+)?', name: 'tasks-list', component: TaskListPage, meta: { ...plan, title: 'Задачі' } },
    // Нова задача й правка — окремі сторінки, не модалки: «Назад» закриває форму.
    // Кошик задач: видалені за 30 днів — відновити (право tasks.delete).
    { path: '/tasks/trash', name: 'tasks-trash', component: TaskTrashPage, meta: { ...plan, title: 'Кошик задач' } },
    { path: '/tasks/new', name: 'tasks-new', component: TaskFormPage, meta: { ...plan, title: 'Нова задача' } },
    { path: '/tasks/:id(\\d+)/edit', name: 'task-edit', component: TaskFormPage, props: true, meta: { ...plan, title: 'Редагувати задачу' } },
    // Старі посилання (Telegram, «Огляд») відкривають задачу на дошці; окрема сторінка — /tasks/:id/full.
    { path: '/tasks/:id(\\d+)', name: 'task', redirect: (to) => ({ name: 'tasks-board', params: { id: to.params.id }, query: to.query }) },
    { path: '/tasks/:id(\\d+)/full', name: 'task-full', component: TaskView, props: true, meta: { ...plan, title: 'Задача' } },
    { path: '/me', name: 'tasks-profile', component: ProfilePage, meta: { ...plan, title: 'Профіль' } },
    { path: '/tasks/chronicle/:id(\\d+)?', name: 'tasks-chronicle', component: ChronicleFeed, meta: { ...plan, title: 'Хроніка' } },
    { path: '/tasks/search/:id(\\d+)?', name: 'tasks-search', component: SearchPage, meta: { ...plan, title: 'Пошук' } },
    // Люди: список ліворуч, картка праворуч (/staff/12, /staff/new) — замість модалки.
    { path: '/staff', name: 'staff', component: StaffPage, meta: { ...plan, title: 'Співробітники' } },
    { path: '/staff/new', name: 'staff-new', component: StaffPage, meta: { ...plan, title: 'Новий співробітник' } },
    { path: '/staff/:id(\\d+)', name: 'staff-card', component: StaffPage, meta: { ...plan, title: 'Картка співробітника' } },
]
