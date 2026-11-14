import type { RouteRecordRaw } from 'vue-router'
import FleetPage from '../../pages/FleetPage.vue'
import SchedulePage from '../../pages/SchedulePage.vue'

export const fleetRoutes: RouteRecordRaw[] = [
    { path: '/fleet', name: 'fleet', component: FleetPage },
    { path: '/schedule', name: 'schedule', component: SchedulePage },
]
