<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from './Icon.vue'
import { chronicleTarget, CHRONICLE_ROUTE, searchTarget, SEARCH_ROUTE } from './nav'
import { useSession } from '../../../app/store'
import { useChronicleCounts } from '../chronicle'
import { typing } from '../../../plan/layers'

/**
 * Оболонка «Плану» — один продукт: задачі, заявки, склад, люди.
 * Десктоп: вузька ліва панель іконок і верхня смуга 48 px.
 * Телефон: компактна шапка й нижня панель вкладок (Хроніка · Задачі · Заявки · ＋ · Ще).
 * Коли щось відкрито на телефоні (задача, заявка, форма, файл), обидві панелі
 * ховаються — сторінка займає весь екран, «Назад» повертає до списку.
 */
const session = useSession()
const route = useRoute()
const router = useRouter()
const q = ref('')
const searchBox = ref<HTMLInputElement | null>(null)

const title = computed(() => route.meta.title ?? 'План')

/** Скільки задач з новим — бейдж на «Хроніці» (рейка й нижня вкладка) і «(N) План» у вкладці браузера. */
const { counts } = useChronicleCounts()
const unread = computed(() => counts.value.tasks)
const badge = computed(() => (unread.value > 99 ? '99+' : String(unread.value)))
const pageTitle = document.title
watchEffect(() => {
    document.title = (unread.value ? `(${badge.value}) ` : '') + 'План'
})
onBeforeUnmount(() => {
    document.title = pageTitle
})

const me = computed(() => session.user)
const initials = computed(() => ((me.value?.firstName ?? '').charAt(0) + (me.value?.lastName ?? '').charAt(0)).toUpperCase() || '?')
/** Посада або роль у Плані. Роль заявок («Робітник») тут не показуємо. */
const roleLabel = computed(() => me.value?.position || me.value?.workRoleLabel || '')
/** Адмін-розділ (журнал аудиту) — адміністраторам; суперадміна вводить окремий потік. */
const canAdmin = computed(() => me.value?.workRole === 'admin' || me.value?.role === 'admin')

const name = computed(() => String(route.name))
/** Сторінки-вікна (форма, файл) і відкрита праворуч картка — на телефоні на весь екран. */
const FULLSCREEN = ['tasks-new', 'task-edit', 'supply-new', 'staff-new', 'staff-card', 'file', 'task-full', 'supply-full']
const SPLIT = ['tasks-board', CHRONICLE_ROUTE, SEARCH_ROUTE, 'supply-board']
const fullscreen = computed(() => FULLSCREEN.includes(name.value) || (SPLIT.includes(name.value) && !!route.params.id))

const onChronicle = computed(() => name.value === CHRONICLE_ROUTE || (name.value === 'tasks-board' && route.query.view === 'chronicle'))
const onSearch = computed(() => name.value === SEARCH_ROUTE || (name.value === 'tasks-board' && route.query.find === '1'))
const onTasks = computed(() => ['tasks-board', 'task-full', 'tasks-new', 'task-edit'].includes(name.value) && !onChronicle.value && !onSearch.value)
const onSupply = computed(() => name.value.startsWith('supply-'))
const onStaff = computed(() => name.value.startsWith('staff'))
const onMore = computed(() => ['tasks-profile', 'warehouse', 'notifications', 'admin', 'staff', 'staff-new', 'staff-card'].includes(name.value))

/** «＋» створює те, де людина зараз: у заявках — заявку, деінде — задачу. */
const newTarget = computed(() => (onSupply.value ? { name: 'supply-new' } : { name: 'tasks-new' }))
const newLabel = computed(() => (onSupply.value ? 'Нова заявка ( N )' : 'Нова задача ( N )'))

function search() {
    router.push(searchTarget(router, q.value.trim()))
    q.value = ''
    searchBox.value?.blur()
}

/** «/» — до пошуку, «N» — нове (як у ПланФіксі), якщо курсор не в полі вводу. */
function onKey(e: KeyboardEvent) {
    if (e.ctrlKey || e.metaKey || e.altKey || typing(e.target) || route.query.f) return
    if (e.key === '/') {
        const boardFilter = document.querySelector<HTMLInputElement>('[data-board-filter], .f-search-box input')
        ;(boardFilter && boardFilter.offsetParent !== null ? boardFilter : searchBox.value)?.focus()
        e.preventDefault()
    } else if ((e.key === 'n' || e.key === 'т') && !FULLSCREEN.slice(0, 4).includes(name.value)) {
        router.push(newTarget.value)
        e.preventDefault()
    }
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
    <div class="p-shell" :class="{ 'task-open': fullscreen }">
        <nav class="p-rail" aria-label="Розділи">
            <router-link :to="{ name: 'tasks' }" class="p-logo" title="План · Огляд">П</router-link>
            <router-link :to="{ name: 'tasks' }" class="p-rail-btn" :class="{ on: name === 'tasks' }" title="Огляд"><Icon name="home" /><span>Огляд</span></router-link>
            <router-link :to="chronicleTarget(router)" class="p-rail-btn" :class="{ on: onChronicle }" title="Хроніка"><Icon name="activity" /><span>Хроніка</span><b v-if="unread" class="p-badge" :aria-label="unread + ' задач з новим'">{{ badge }}</b></router-link>
            <router-link :to="{ name: 'tasks-board' }" class="p-rail-btn" :class="{ on: onTasks }" title="Задачі"><Icon name="board" /><span>Задачі</span></router-link>
            <router-link :to="{ name: 'supply-board' }" class="p-rail-btn" :class="{ on: onSupply }" title="Заявки на постачання"><Icon name="clipboard" /><span>Заявки</span></router-link>
            <router-link :to="{ name: 'warehouse' }" class="p-rail-btn" :class="{ on: name === 'warehouse' }" title="Склад"><Icon name="warehouse" /><span>Склад</span></router-link>
            <router-link v-if="me?.fleet" :to="{ name: 'schedule' }" class="p-rail-btn" title="Автопарк"><Icon name="truck" /><span>Авто</span></router-link>
            <router-link :to="searchTarget(router)" class="p-rail-btn" :class="{ on: onSearch }" title="Пошук ( / )"><Icon name="search" /><span>Пошук</span></router-link>
            <router-link :to="{ name: 'notifications' }" class="p-rail-btn" :class="{ on: name === 'notifications' }" title="Сповіщення"><Icon name="bell" /><span>Сповіщ.</span></router-link>
            <router-link :to="{ name: 'staff' }" class="p-rail-btn" :class="{ on: onStaff }" title="Співробітники"><Icon name="users" /><span>Люди</span></router-link>
            <router-link v-if="canAdmin" :to="{ name: 'admin' }" class="p-rail-btn" :class="{ on: name === 'admin' }" title="Адмін"><Icon name="shield" /><span>Адмін</span></router-link>
            <span class="p-rail-sp" />
            <router-link :to="newTarget" class="p-rail-btn p-rail-new" :title="newLabel" :aria-label="newLabel"><Icon name="plus" :stroke="2.2" /></router-link>
            <router-link :to="{ name: 'tasks-profile' }" class="p-rail-btn" :class="{ on: name === 'tasks-profile' }" title="Профіль"><Icon name="user" /><span>Профіль</span></router-link>
        </nav>

        <div class="p-main">
            <header class="p-top">
                <h1 class="p-top-title">{{ title }}</h1>
                <form class="p-top-search" role="search" @submit.prevent="search">
                    <Icon name="search" :size="16" />
                    <input ref="searchBox" v-model="q" type="search" placeholder="Пошук по задачах" aria-label="Пошук по задачах" />
                    <kbd>/</kbd>
                </form>
                <router-link :to="{ name: 'tasks-profile' }" class="p-me" title="Профіль">
                    <span class="p-me-ava">{{ initials }}</span>
                    <span class="p-me-text">
                        <b>{{ me?.name }}</b>
                        <small>{{ roleLabel }}</small>
                    </span>
                </router-link>
            </header>

            <header class="p-mtop">
                <template v-if="name === 'tasks' || name === 'tasks-board'">
                    <div class="p-seg" role="tablist">
                        <router-link :to="{ name: 'tasks' }" :class="{ on: name === 'tasks' }">Огляд</router-link>
                        <router-link :to="{ name: 'tasks-board' }" :class="{ on: name === 'tasks-board' && !onChronicle && !onSearch }">Дошка</router-link>
                    </div>
                </template>
                <h1 v-else class="p-top-title">{{ title }}</h1>
                <router-link :to="searchTarget(router)" class="p-icon-btn" title="Пошук" aria-label="Пошук"><Icon name="search" /></router-link>
                <router-link :to="{ name: 'notifications' }" class="p-icon-btn p-mtop-2" title="Сповіщення" aria-label="Сповіщення"><Icon name="bell" /></router-link>
            </header>

            <main class="p-content">
                <slot />
            </main>
        </div>

        <nav class="p-tabbar" aria-label="Вкладки">
            <router-link :to="chronicleTarget(router)" :class="{ on: onChronicle }"><Icon name="activity" /><span>Хроніка</span><b v-if="unread" class="p-badge" :aria-label="unread + ' задач з новим'">{{ badge }}</b></router-link>
            <router-link :to="{ name: 'tasks-board' }" :class="{ on: onTasks }"><Icon name="board" /><span>Задачі</span></router-link>
            <router-link :to="newTarget" class="p-tab-new" :aria-label="newLabel"><Icon name="plus" :size="26" :stroke="2.2" /></router-link>
            <router-link :to="{ name: 'supply-board' }" :class="{ on: onSupply }"><Icon name="clipboard" /><span>Заявки</span></router-link>
            <router-link :to="{ name: 'tasks-profile' }" :class="{ on: onMore }"><Icon name="menu" /><span>Ще</span></router-link>
        </nav>
    </div>
</template>
