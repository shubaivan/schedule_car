<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useSession } from './app/store'

const session = useSession()
const route = useRoute()

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
const section = computed(() => (FLEET_SECTION.includes(String(route.name)) ? 'fleet' : 'requests'))

onMounted(() => session.load())
</script>

<template>
    <div class="layout">
        <div v-if="session.expired" class="center">
            <p>Сесія завершилась.</p>
            <p class="muted">Відкрийте бота, натисніть «🔐 Вхід у CRM» і перейдіть за новим посиланням.</p>
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
                    <router-link :to="{ name: 'requests' }" :class="{ on: section === 'requests' }">📦 Заявки</router-link>
                    <router-link :to="{ name: 'schedule' }" :class="{ on: section === 'fleet' }">🚗 Автопарк</router-link>
                    <a v-if="session.user?.warehouse" href="/sklad">🏗 Склад</a>
                    <a v-if="session.user?.shop" href="/shop">🛒 Магазин</a>
                </nav>
                <span class="who">
                    {{ session.user?.name }} · {{ session.user?.roleLabel }}
                    · <a href="/crm/logout">вийти</a>
                </span>
            </header>
            <nav class="subnav">
                <template v-if="section === 'requests'">
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
