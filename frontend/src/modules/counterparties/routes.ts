import type { RouteRecordRaw } from 'vue-router'

const CounterpartiesBoard = () => import('./CounterpartiesBoard.vue')
const CounterpartyView = () => import('./CounterpartyView.vue')
const CounterpartyFormPage = () => import('./CounterpartyFormPage.vue')

const plan = { shell: 'plan' as const }

/**
 * Без модалок: список ліворуч, картка праворуч (/counterparties/12), форма — сторінкою
 * (/counterparties/new, /counterparties/12/edit), картка окремо — /counterparties/12/full.
 * Фільтри — в адресі (?q=&kind=&vat=&manager=&terms=&form=&archived=1).
 */
export const counterpartiesRoutes: RouteRecordRaw[] = [
    { path: '/counterparties/:id(\\d+)?', name: 'counterparties', component: CounterpartiesBoard, meta: { ...plan, title: 'Контрагенти' } },
    { path: '/counterparties/new', name: 'counterparty-new', component: CounterpartyFormPage, meta: { ...plan, title: 'Новий контрагент' } },
    { path: '/counterparties/:id(\\d+)/edit', name: 'counterparty-edit', component: CounterpartyFormPage, props: true, meta: { ...plan, title: 'Змінити контрагента' } },
    { path: '/counterparties/:id(\\d+)/full', name: 'counterparty-full', component: CounterpartyView, props: true, meta: { ...plan, title: 'Контрагент' } },
]

/** На телефоні — на весь екран (без шапки й вкладок оболонки). */
export const COUNTERPARTY_FULLSCREEN = ['counterparty-new', 'counterparty-edit', 'counterparty-full']
/** Розділений екран: з відкритою карткою — на телефоні теж на весь екран. */
export const COUNTERPARTY_SPLIT = ['counterparties']
export const COUNTERPARTY_FORMS = ['counterparty-new', 'counterparty-edit']
