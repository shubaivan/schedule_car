import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { supplyRoutes } from '../modules/supply/routes'
import { fleetRoutes } from '../modules/fleet/routes'
import { tasksRoutes } from '../modules/tasks/routes'

/**
 * Лише збирає маршрути модулів. Кожен модуль (і кожен потік «Плану») тримає свої
 * маршрути у власному `modules/<модуль>/routes.ts` — так паралельні гілки не
 * конфліктують у цьому файлі. Новий модуль: експортуй масив і додай його сюди.
 */
const modules: RouteRecordRaw[][] = [supplyRoutes, fleetRoutes, tasksRoutes]

export const router = createRouter({
    history: createWebHistory('/crm/'),
    routes: [
        ...modules.flat(),
        // Невідома адреса — на головну. Завжди останнім.
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
})
