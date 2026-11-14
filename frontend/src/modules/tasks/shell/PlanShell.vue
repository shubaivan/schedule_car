<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from './Icon.vue'
import TaskForm from '../TaskForm.vue'
import { chronicleTarget, CHRONICLE_ROUTE, searchTarget, SEARCH_ROUTE } from './nav'
import { useSession } from '../../../app/store'
import { useChronicleCounts } from '../chronicle'

/**
 * Оболонка «Плану»: на десктопі вузька ліва панель іконок і верхня смуга 48 px,
 * на телефоні — компактна шапка й нижня панель вкладок
 * (Хроніка · Задачі · Пошук · ＋ · Профіль). Коли задача відкрита на телефоні,
 * обидві панелі ховаються — задача займає весь екран.
 */
const session = useSession()
const route = useRoute()
const router = useRouter()
const creating = ref(false)
const q = ref('')
const searchBox = ref<HTMLInputElement | null>(null)

const TITLES: Record<string, string> = {
    tasks: 'Огляд',
    'tasks-board': 'Угоди й задачі',
    staff: 'Співробітники',
    'tasks-profile': 'Профіль',
    [CHRONICLE_ROUTE]: 'Хроніка',
    [SEARCH_ROUTE]: 'Пошук',
}
const title = computed(() => TITLES[String(route.name)] ?? 'План')

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

const taskOpen = computed(() => !!route.params.id)
const onChronicle = computed(() => route.name === CHRONICLE_ROUTE || (route.name === 'tasks-board' && route.query.view === 'chronicle'))
const onSearch = computed(() => route.name === SEARCH_ROUTE || (route.name === 'tasks-board' && route.query.find === '1'))
const onTasks = computed(() => (route.name === 'tasks' || route.name === 'tasks-board') && !onChronicle.value && !onSearch.value)

function search() {
    router.push(searchTarget(router, q.value.trim()))
    q.value = ''
    searchBox.value?.blur()
}

/** «/» — до пошуку, «N» — нова задача (як у ПланФіксі), якщо курсор не в полі вводу. */
function onKey(e: KeyboardEvent) {
    const el = e.target as HTMLElement | null
    if (e.ctrlKey || e.metaKey || e.altKey || creating.value) return
    if (el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName))) return
    if (document.querySelector('.t-modal-wrap, .t-viewer')) return
    if (e.key === '/') {
        const boardFilter = document.querySelector<HTMLInputElement>('[data-board-filter], .f-search-box input')
        ;(boardFilter && boardFilter.offsetParent !== null ? boardFilter : searchBox.value)?.focus()
        e.preventDefault()
    } else if (e.key === 'n' || e.key === 'т') {
        creating.value = true
        e.preventDefault()
    }
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
    <div class="p-shell" :class="{ 'task-open': taskOpen }">
        <nav class="p-rail" aria-label="Розділи">
            <router-link :to="{ name: 'tasks' }" class="p-logo" title="План · Огляд">П</router-link>
            <router-link :to="{ name: 'tasks' }" class="p-rail-btn" :class="{ on: route.name === 'tasks' }" title="Огляд"><Icon name="home" /><span>Огляд</span></router-link>
            <router-link :to="{ name: 'tasks-board' }" class="p-rail-btn" :class="{ on: route.name === 'tasks-board' && !onChronicle && !onSearch }" title="Дошка"><Icon name="board" /><span>Дошка</span></router-link>
            <router-link :to="chronicleTarget(router)" class="p-rail-btn" :class="{ on: onChronicle }" title="Хроніка"><Icon name="activity" /><span>Хроніка</span><b v-if="unread" class="p-badge" :aria-label="unread + ' задач з новим'">{{ badge }}</b></router-link>
            <router-link :to="searchTarget(router)" class="p-rail-btn" :class="{ on: onSearch }" title="Пошук ( / )"><Icon name="search" /><span>Пошук</span></router-link>
            <router-link :to="{ name: 'staff' }" class="p-rail-btn" :class="{ on: route.name === 'staff' }" title="Співробітники"><Icon name="users" /><span>Люди</span></router-link>
            <span class="p-rail-sp" />
            <button type="button" class="p-rail-btn p-rail-new" title="Нова задача ( N )" @click="creating = true"><Icon name="plus" :stroke="2.2" /></button>
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
                <template v-if="route.name === 'tasks' || route.name === 'tasks-board'">
                    <div class="p-seg" role="tablist">
                        <router-link :to="{ name: 'tasks' }" :class="{ on: route.name === 'tasks' }">Огляд</router-link>
                        <router-link :to="{ name: 'tasks-board' }" :class="{ on: route.name === 'tasks-board' && !onChronicle && !onSearch }">Дошка</router-link>
                    </div>
                </template>
                <h1 v-else class="p-top-title">{{ title }}</h1>
                <router-link :to="{ name: 'staff' }" class="p-icon-btn" title="Співробітники" aria-label="Співробітники"><Icon name="users" /></router-link>
            </header>

            <main class="p-content">
                <slot />
            </main>
        </div>

        <nav class="p-tabbar" aria-label="Вкладки">
            <router-link :to="chronicleTarget(router)" :class="{ on: onChronicle }"><Icon name="activity" /><span>Хроніка</span><b v-if="unread" class="p-badge" :aria-label="unread + ' задач з новим'">{{ badge }}</b></router-link>
            <router-link :to="{ name: 'tasks-board' }" :class="{ on: onTasks }"><Icon name="board" /><span>Задачі</span></router-link>
            <router-link :to="searchTarget(router)" :class="{ on: onSearch }"><Icon name="search" /><span>Пошук</span></router-link>
            <button type="button" class="p-tab-new" aria-label="Нова задача" @click="creating = true"><Icon name="plus" :size="26" :stroke="2.2" /></button>
            <router-link :to="{ name: 'tasks-profile' }" :class="{ on: route.name === 'tasks-profile' }"><Icon name="user" /><span>Профіль</span></router-link>
        </nav>

        <TaskForm v-if="creating" @close="creating = false" />
    </div>
</template>
