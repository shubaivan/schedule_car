<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSession } from './app/store'
import PlanShell from './modules/tasks/shell/PlanShell.vue'
import UndoToast from './plan/UndoToast.vue'
import './modules/tasks/tasks.css'
import './modules/tasks/shell/plan.css'

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

/** «План» відкривається з задач (CRM_HOME=tasks) — тоді й заявки живуть у його оболонці. */
const planHome = computed(() => session.user?.home === 'tasks')

/**
 * Розділ, у якому людина зараз: оболонка «Плану» (meta.shell у маршрутах модулів)
 * або стара шапка заявок / автопарку, як на стендах заявок.
 */
const FLEET_SECTION = ['schedule', 'fleet']
const section = computed(() => {
    const shell = route.meta.shell
    if (shell === 'plan' || (shell === 'auto' && planHome.value)) return 'tasks'
    return FLEET_SECTION.includes(String(route.name)) ? 'fleet' : 'requests'
})
const wide = computed(() => section.value === 'tasks')

// Обірваний запит /api/meta (пішли зі сторінки, мережа зникла) — не необроблений reject у консолі;
// сесію, що скінчилась, store обробляє сам (expired).
onMounted(() => session.load().catch((e) => console.warn('session.load', e)))

// CRM_HOME=tasks: стенд «робочого місця» відкривається з задач, а не з заявок;
// старе посилання на заявку (з бота, з групи) відкриває її в «Плані» праворуч від списку.
watch(
    () => [session.ready, route.name] as const,
    ([ready]) => {
        if (!ready) return
        // Стенд заявок (розділ «Задачі» вимкнено): сторінок оболонки «Плану» там немає — на заявки.
        if (session.user?.tasks === false && route.meta.shell === 'plan') {
            router.replace({ name: 'requests' })
            return
        }
        if (!planHome.value) return
        if (route.name === 'requests' && route.path === '/') router.replace({ name: 'tasks' })
        else if (route.name === 'request' && /^\d+$/.test(String(route.params.id))) {
            router.replace({ name: 'supply-board', params: { id: String(route.params.id) } })
        }
    },
    { immediate: true },
)
</script>

<template>
    <div class="layout" :class="{ wide, plan: section === 'tasks' && session.ready && !session.expired }">
        <div v-if="session.expired" class="center">
            <p>Сесія завершилась.</p>
            <p class="muted">Відкрийте бота, натисніть «🔐 Вхід у CRM» і перейдіть за новим посиланням.</p>
            <p><a href="/vhid">Увійти знову</a></p>
        </div>

        <div v-else-if="!session.ready" class="center">Завантаження…</div>

        <!-- «План» (задачі) — своя оболонка: ліва панель / нижні вкладки. -->
        <PlanShell v-else-if="section === 'tasks'">
            <router-view />
        </PlanShell>

        <!-- «План»: старе посилання на заявку зараз переадресується — стару картку не показуємо навіть на мить. -->
        <div v-else-if="planHome && route.name === 'request'" class="center">Завантаження…</div>

        <template v-else>
            <!--
                Одна адмінка на всі розділи: верхній ряд — розділи (той самий у заявках,
                на складі й у магазині), нижній — сторінки поточного розділу. Склад і
                магазин — окремі сторінки сервера, тому там звичайні посилання.
            -->
            <header class="topbar">
                <nav class="sections">
                    <router-link v-if="session.user?.tasks" :to="{ name: 'tasks' }">✅ Задачі</router-link>
                    <router-link :to="{ name: 'requests' }" :class="{ on: section === 'requests' }">📦 Заявки</router-link>
                    <router-link v-if="session.user?.fleet !== false" :to="{ name: 'schedule' }" :class="{ on: section === 'fleet' }">🚗 Автопарк</router-link>
                    <a v-if="session.user?.warehouse" href="/sklad">🏗 Склад</a>
                    <a v-if="session.user?.shop" href="/shop">🛒 Магазин</a>
                </nav>
                <span class="who">
                    {{ session.user?.name }} · {{ session.user?.roleLabel }} ·
                    <a href="/crm/logout">вийти</a>
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

        <UndoToast />
    </div>
</template>
