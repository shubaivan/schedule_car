<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSession } from './app/store'
import './modules/tasks/tasks.css'

const session = useSession()
const route = useRoute()
const router = useRouter()

/** Довідники, звіти й люди — робоче місце менеджера; заявки відкриті всім. */
const MANAGER_ROUTES = ['suppliers', 'reports', 'users', 'departments']

/**
 * Автопарк роздає машини й людей — це рівень директора, не менеджера.
 * Календар завантаження сюди не входить: чим зайняті машини, бачать усі.
 */
const DIRECTOR_ROUTES = ['fleet']

// Перевіряємо тут, а не в router.beforeEach: на момент першої навігації
// сесія ще не завантажена, і менеджер із прямого посилання полетів би на «/».
const allowed = computed(() => {
    const name = String(route.name)

    if (DIRECTOR_ROUTES.includes(name)) return session.isDirector()

    return session.isManager() || !MANAGER_ROUTES.includes(name)
})

/** Розділ, у якому людина зараз: від нього залежить нижній ряд меню. */
const FLEET_SECTION = ['schedule', 'fleet']
const TASKS_SECTION = ['tasks', 'tasks-board', 'task', 'staff']
const section = computed(() => {
    const name = String(route.name)
    if (TASKS_SECTION.includes(name)) return 'tasks'
    return FLEET_SECTION.includes(name) ? 'fleet' : 'requests'
})
const wide = computed(() => section.value === 'tasks')

onMounted(() => session.load())

// CRM_HOME=tasks: стенд «робочого місця» відкривається з задач, а не з заявок.
watch(
    () => session.ready,
    (ready) => {
        if (ready && session.user?.home === 'tasks' && route.name === 'requests' && route.path === '/') {
            router.replace({ name: 'tasks' })
        }
    },
)
</script>

<template>
    <div class="layout" :class="{ wide }">
        <div v-if="session.expired" class="center">
            <p>Сесія завершилась.</p>
            <p class="muted">Відкрийте бота, натисніть «🔐 Вхід у CRM» і перейдіть за новим посиланням.</p>
            <p><a href="/vhid">Увійти знову</a></p>
        </div>

        <div v-else-if="!session.ready" class="center">Завантаження…</div>

        <template v-else>
            <!--
                Одна адмінка на всі розділи: верхній ряд — розділи (той самий у заявках,
                на складі й у магазині), нижній — сторінки поточного розділу. Склад і
                магазин — окремі сторінки сервера, тому там звичайні посилання.
            -->
            <header class="topbar">
                <nav class="sections">
                    <router-link v-if="session.user?.tasks" :to="{ name: 'tasks' }" :class="{ on: section === 'tasks' }">✅ Задачі</router-link>
                    <router-link :to="{ name: 'requests' }" :class="{ on: section === 'requests' }">📦 Заявки</router-link>
                    <router-link v-if="session.user?.fleet !== false" :to="{ name: 'schedule' }" :class="{ on: section === 'fleet' }">🚗 Автопарк</router-link>
                    <a v-if="session.user?.warehouse" href="/sklad">🏗 Склад</a>
                    <a v-if="session.user?.shop" href="/shop">🛒 Магазин</a>
                </nav>
                <span class="who">
                    {{ session.user?.name }} · {{ session.user?.roleLabel }}
                    · <a href="/crm/logout">вийти</a>
                </span>
            </header>
            <nav class="subnav">
                <template v-if="section === 'tasks'">
                    <router-link :to="{ name: 'tasks' }" exact-active-class="router-link-active" active-class="">Огляд</router-link>
                    <router-link :to="{ name: 'tasks-board' }">Дошка</router-link>
                    <router-link :to="{ name: 'staff' }">Співробітники</router-link>
                </template>
                <template v-else-if="section === 'requests'">
                    <router-link :to="{ name: 'requests' }" active-class="" exact-active-class="router-link-active">Заявки</router-link>
                    <template v-if="session.isManager()">
                        <router-link :to="{ name: 'suppliers' }">Постачальники</router-link>
                        <router-link :to="{ name: 'reports' }">Звіти</router-link>
                        <router-link :to="{ name: 'users' }">Люди</router-link>
                        <router-link :to="{ name: 'departments' }">Підрозділи</router-link>
                    </template>
                </template>
                <template v-else>
                    <router-link :to="{ name: 'schedule' }">Розклад машин</router-link>
                    <router-link v-if="session.isDirector()" :to="{ name: 'fleet' }">Машини й водії</router-link>
                </template>
            </nav>

            <router-view v-if="allowed" />
            <div v-else class="center">Цей розділ ведуть менеджери з постачання.</div>
        </template>
    </div>
</template>
