import { createRouter, createWebHistory } from 'vue-router'
import RequestList from '../modules/supply/RequestList.vue'
import RequestCard from '../modules/supply/RequestCard.vue'
import SuppliersPage from '../pages/SuppliersPage.vue'
import UsersPage from '../pages/UsersPage.vue'

export const router = createRouter({
    history: createWebHistory('/crm/'),
    routes: [
        { path: '/', name: 'requests', component: RequestList },
        { path: '/requests/:id', name: 'request', component: RequestCard, props: true },
        { path: '/suppliers', name: 'suppliers', component: SuppliersPage },
        { path: '/users', name: 'users', component: UsersPage },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
})
