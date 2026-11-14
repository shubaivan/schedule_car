import { createRouter, createWebHistory } from 'vue-router'
import './routes'
import { supplyRoutes } from '../modules/supply/routes'
import { taskRoutes } from '../modules/tasks/routes'
import { classicRoutes } from '../modules/classic/routes'
import { planRoutes } from '../modules/plan/routes'

/**
 * Лише збирає маршрути модулів: кожен модуль описує свої в modules/<m>/routes.ts,
 * щоб паралельні потоки робіт не правили один файл.
 */
export const router = createRouter({
    history: createWebHistory('/crm/'),
    routes: [...supplyRoutes, ...classicRoutes, ...taskRoutes, ...planRoutes, { path: '/:pathMatch(.*)*', redirect: '/' }],
})
