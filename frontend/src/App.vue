<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useSession } from './app/store'

const session = useSession()
const route = useRoute()

/** Довідники, звіти й люди — робоче місце менеджера; заявки відкриті всім. */
const MANAGER_ROUTES = ['suppliers', 'reports', 'users']

/** Автопарк роздає машини й людей — це рівень керівника, не менеджера. */
const ADMIN_ROUTES = ['fleet']

// Перевіряємо тут, а не в router.beforeEach: на момент першої навігації
// сесія ще не завантажена, і менеджер із прямого посилання полетів би на «/».
const allowed = computed(() => {
    const name = String(route.name)

    if (ADMIN_ROUTES.includes(name)) return session.isAdmin()

    return session.isManager() || !MANAGER_ROUTES.includes(name)
})

onMounted(() => session.load())
</script>

<template>
    <div class="layout">
        <div v-if="session.expired" class="center">
            <p>Сесія завершилась.</p>
            <p class="muted">Відкрийте бота, натисніть «🔐 Вхід у CRM» і перейдіть за новим посиланням.</p>
        </div>

        <div v-else-if="session.loading" class="center">Завантаження…</div>

        <template v-else>
            <header class="topbar">
                <span class="brand">📦 Постачання</span>
                <nav>
                    <router-link :to="{ name: 'requests' }">Заявки</router-link>
                    <template v-if="session.isManager()">
                        <router-link :to="{ name: 'suppliers' }">Постачальники</router-link>
                        <router-link :to="{ name: 'reports' }">Звіти</router-link>
                        <router-link :to="{ name: 'users' }">Люди</router-link>
                    </template>
                    <router-link v-if="session.isAdmin()" :to="{ name: 'fleet' }">Автопарк</router-link>
                </nav>
                <span class="who">
                    {{ session.user?.name }} · {{ session.user?.roleLabel }}
                    · <a href="/crm/logout">вийти</a>
                </span>
            </header>

            <router-view v-if="allowed" />
            <div v-else class="center">Цей розділ ведуть менеджери з постачання.</div>
        </template>
    </div>
</template>
