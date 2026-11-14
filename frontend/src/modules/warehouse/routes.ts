import type { RouteRecordRaw } from 'vue-router'

// Склад у «Плані» — окремим шматком: стендам заявок він не потрібен.
const ItemsBoard = () => import('./ItemsBoard.vue')
const ItemView = () => import('./ItemView.vue')
const ItemFormPage = () => import('./ItemFormPage.vue')
const MovementsList = () => import('./MovementsList.vue')
const MovementView = () => import('./MovementView.vue')
const MovementFormPage = () => import('./MovementFormPage.vue')
const JournalPage = () => import('./JournalPage.vue')

const plan = { shell: 'plan' as const }

/**
 * Без модалок: кожне «вікно» складу — адреса. Список позицій і рухів — розділений
 * екран (/sklad/items/12, /sklad/movements/5), форми — окремі сторінки
 * (/sklad/items/new, /sklad/items/12/edit, /sklad/movements/new?type=shipment&item=12).
 * Twig-склад /sklad (без /crm) лишається як був — для стендів заявок.
 */
export const warehouseRoutes: RouteRecordRaw[] = [
    // Пункт меню «Склад» (рейка, «Ще») веде сюди.
    { path: '/warehouse', name: 'warehouse', redirect: { name: 'warehouse-items' } },
    { path: '/sklad', redirect: { name: 'warehouse-items' } },
    { path: '/sklad/items/:id(\\d+)?', name: 'warehouse-items', component: ItemsBoard, meta: { ...plan, title: 'Склад' } },
    { path: '/sklad/items/new', name: 'warehouse-item-new', component: ItemFormPage, meta: { ...plan, title: 'Нова позиція' } },
    { path: '/sklad/items/:id(\\d+)/edit', name: 'warehouse-item-edit', component: ItemFormPage, props: true, meta: { ...plan, title: 'Редагувати позицію' } },
    { path: '/sklad/items/:id(\\d+)/full', name: 'warehouse-item-full', component: ItemView, props: true, meta: { ...plan, title: 'Позиція' } },
    { path: '/sklad/movements/:id(\\d+)?', name: 'warehouse-movements', component: MovementsList, meta: { ...plan, title: 'Рухи складу' } },
    { path: '/sklad/movements/new', name: 'warehouse-movement-new', component: MovementFormPage, meta: { ...plan, title: 'Новий рух' } },
    { path: '/sklad/movements/:id(\\d+)/full', name: 'warehouse-movement-full', component: MovementView, props: true, meta: { ...plan, title: 'Рух' } },
    { path: '/sklad/journal', name: 'warehouse-journal', component: JournalPage, meta: { ...plan, title: 'Журнал складу' } },
]

/** Сторінки-вікна складу: на телефоні — на весь екран (без шапки й вкладок оболонки). */
export const WAREHOUSE_FULLSCREEN = ['warehouse-item-new', 'warehouse-item-edit', 'warehouse-item-full', 'warehouse-movement-new', 'warehouse-movement-full']
/** Розділені екрани складу: з відкритим праворуч — на телефоні теж на весь екран. */
export const WAREHOUSE_SPLIT = ['warehouse-items', 'warehouse-movements']
