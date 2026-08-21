import { createRouter, createWebHistory } from 'vue-router'
import RequestList from '../modules/supply/RequestList.vue'
import RequestCard from '../modules/supply/RequestCard.vue'
import ReportsPage from '../pages/ReportsPage.vue'
import SuppliersPage from '../pages/SuppliersPage.vue'
import UsersPage from '../pages/UsersPage.vue'
import FleetPage from '../pages/FleetPage.vue'
import SchedulePage from '../pages/SchedulePage.vue'

export const router = createRouter({
    history: createWebHistory('/crm/'),
    routes: [
        { path: '/', name: 'requests', component: RequestList },
        { path: '/requests/:id', name: 'request', component: RequestCard, props: true },
        { path: '/suppliers', name: 'suppliers', component: SuppliersPage },
        { path: '/reports', name: 'reports', component: ReportsPage },
        { path: '/users', name: 'users', component: UsersPage },
        { path: '/fleet', name: 'fleet', component: FleetPage },
        { path: '/schedule', name: 'schedule', component: SchedulePage },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
})
