<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import TaskCard from './TaskCard.vue'
import TaskForm from './TaskForm.vue'
import TaskView from './TaskView.vue'
import Icon from './shell/Icon.vue'
import { TASK_SAVED, useMobile } from './shell/nav'
import { tasksApi, type Board } from './api'
import { useTasks } from './store'
import { useSession } from '../../app/store'

/**
 * Дошка — колонки за відповіддю клієнта 13.11.2026:
 * Хроніка (що я відкривав) · Мої повідомлення (де я писав останнім або мене згадали)
 * · Нова заявка · В роботі · Виконана.
 *
 * Розділений екран, як у ПланФіксі: клік по картці відкриває задачу праворуч
 * (адреса /tasks/board/:id), дошка лишається на місці — з фільтрами й прокруткою,
 * зліва лишається одна активна колонка. ↑/↓ (або J/K) — сусідня задача, Esc — закрити.
 * На телефоні — вкладки замість колонок, задача відкривається на весь екран.
 */
type Column = keyof Board

const session = useSession()
const store = useTasks()
const route = useRoute()
const router = useRouter()
const mobile = useMobile()

const board = ref<Board | null>(null)
const q = ref(typeof route.query.q === 'string' ? route.query.q : '')
const label = ref<number | null>(null)
const error = ref('')
const creating = ref(false)
const filter = ref<HTMLInputElement | null>(null)

const COLUMNS: { key: Column; title: string; color: string; empty: string; status?: boolean }[] = [
    { key: 'chronicle', title: 'Хроніка', color: '#64748b', empty: 'Тут зʼявляться задачі, які ви відкривали.', status: true },
    { key: 'messages', title: 'Мої повідомлення', color: '#db2777', empty: 'Тут задачі, де ви писали останнім або вас згадали.', status: true },
    { key: 'new', title: 'Нова заявка', color: '#2563eb', empty: 'Нових заявок немає.' },
    { key: 'work', title: 'В роботі', color: '#7c3aed', empty: 'Нічого в роботі.', status: true },
    { key: 'done', title: 'Виконана', color: '#16a34a', empty: 'Ще нічого не закрито.', status: true },
]
const isColumn = (v: unknown): v is Column => typeof v === 'string' && COLUMNS.some((c) => c.key === v)

const openId = computed(() => (route.params.id ? Number(route.params.id) : null))
/** ?view=chronicle — так на дошку веде «Хроніка», поки немає окремого екрана стрічки. */
const fromQuery = (): Column | null => (isColumn(route.query.col) ? route.query.col : route.query.view === 'chronicle' ? 'chronicle' : null)
const active = ref<Column>(fromQuery() ?? 'new')
// Вкладку обрала людина (або посилання) — розумний вибір за замовчуванням уже не потрібен.
let picked = fromQuery() !== null

const unreadIn = (key: Column) => (board.value ? board.value[key].reduce((s, t) => s + t.unread, 0) : 0)
const activeList = computed(() => (board.value ? board.value[active.value] : []))

/** Перша робоча колонка з непрочитаним («Мої повідомлення» першою), інакше перша непорожня. */
function smartColumn(b: Board): Column {
    const order: Column[] = ['messages', 'new', 'work', 'chronicle']
    return order.find((k) => b[k].some((t) => t.unread > 0)) ?? [...order, 'done' as Column].find((k) => b[k].length) ?? 'new'
}

let debounce: number | undefined
// Кожне завантаження має номер: застосовуємо лише найсвіжішу відповідь, жодне не губимо.
let request = 0
async function load() {
    const seq = ++request
    try {
        await store.load()
        const fresh = await tasksApi.board(q.value.trim(), label.value)
        if (seq !== request) return
        board.value = fresh
        error.value = ''
        if (!picked) {
            picked = true
            active.value = smartColumn(fresh)
            router.replace({ name: 'tasks-board', params: route.params, query: keepQuery({ col: active.value }) })
        }
        // Задачу відкрили з посилання: зліва — колонка, де вона є.
        if (openId.value && !fresh[active.value].some((t) => t.id === openId.value)) {
            const home = COLUMNS.find((c) => fresh[c.key].some((t) => t.id === openId.value))
            if (home) active.value = home.key
        }
    } catch (e) {
        if (seq === request) error.value = session.handle(e)
    }
}

function keepQuery(extra: Record<string, string | undefined> = {}) {
    const query: Record<string, string> = {}
    for (const [k, v] of Object.entries({ ...route.query, ...extra })) {
        if (typeof v === 'string' && v !== '' && k !== 'find') query[k] = v
    }
    return query
}

function pick(key: Column) {
    active.value = key
    picked = true
    router.replace({ name: 'tasks-board', params: route.params, query: keepQuery({ col: key, view: undefined }) })
}

function open(id: number, column?: Column) {
    if (column) active.value = column
    picked = true
    const to = { name: 'tasks-board', params: { id }, query: keepQuery() }
    // Телефон: «назад» має повертати на дошку. Десктоп: гортаємо задачі, не засмічуючи історію.
    if (mobile.value || openId.value === null) router.push(to)
    else router.replace(to)
}

function close() {
    // Відкрили з дошки (push) — повертаємось «назад», щоб в історії не лишалось зайвих кроків.
    const back = (window.history.state as { back?: string } | null)?.back
    if (back && back.startsWith('/tasks/board') && !/\/tasks\/board\/\d/.test(back)) router.back()
    else router.replace({ name: 'tasks-board', query: keepQuery() })
}

/** Сусідня задача в активній колонці: ↑/↓ або K/J. */
function step(delta: number) {
    const list = activeList.value
    if (!list.length) return
    const i = list.findIndex((t) => t.id === openId.value)
    const next = list[Math.min(list.length - 1, Math.max(0, i < 0 ? 0 : i + delta))]
    if (next && next.id !== openId.value) open(next.id)
}

function onKey(e: KeyboardEvent) {
    const el = e.target as HTMLElement | null
    if (e.ctrlKey || e.metaKey || e.altKey || creating.value || e.defaultPrevented) return
    if (document.querySelector('.t-modal-wrap, .t-viewer, .p-menu')) return
    if (el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName))) {
        if (e.key === 'Escape' && el === filter.value) el.blur()
        return
    }
    if (!openId.value) return
    // Стрілки — лише коли фокус у списку зліва (інакше ними гортають чат); J/K — будь-де.
    const inList = !!el?.closest('.p-board-left')
    if (e.key === 'Escape') close()
    else if ((e.key === 'ArrowDown' && inList) || e.key === 'j' || e.key === 'о') {
        if (!e.repeat) step(1)
    } else if ((e.key === 'ArrowUp' && inList) || e.key === 'k' || e.key === 'л') {
        if (!e.repeat) step(-1)
    } else return
    e.preventDefault()
}

watch(q, () => {
    window.clearTimeout(debounce)
    debounce = window.setTimeout(load, 300)
})
watch(label, load)
watch(() => [route.query.col, route.query.view], () => {
    const col = fromQuery()
    if (col) {
        active.value = col
        picked = true
    }
})
watch(() => route.query.find, async (find) => {
    if (find !== '1') return
    await nextTick()
    filter.value?.focus()
}, { immediate: true })
watch(() => route.query.q, (value) => {
    if (typeof value === 'string' && value !== q.value) q.value = value
})

// Телефон: дошка під задачею лише схована — повертаємо прокрутку, де була.
let savedY = 0
let reread: number | undefined
watch(openId, async (now, before) => {
    if (mobile.value) {
        if (now && !before) savedY = window.scrollY
        if (!now && before) {
            await nextTick()
            window.scrollTo(0, savedY)
        }
        if (now) window.scrollTo(0, 0)
    } else if (now) {
        await nextTick()
        const card = document.querySelector<HTMLElement>(`.p-board-left .p-col.on [data-task="${now}"]`)
        card?.scrollIntoView({ block: 'nearest' })
        // Фокус іде за виділенням, щоб не світились дві картки (клікнута й поточна).
        if (card && document.activeElement instanceof HTMLElement && document.activeElement.closest('.p-board-left')) card.focus({ preventScroll: true })
    }
    // Відкрита задача стає прочитаною — оновимо «+N» на картках.
    window.clearTimeout(reread)
    if (now) reread = window.setTimeout(load, 1500)
}, { flush: 'pre' })

let timer: number | undefined
onMounted(() => {
    load()
    timer = window.setInterval(() => !document.hidden && load(), 20000)
    window.addEventListener('keydown', onKey)
    window.addEventListener(TASK_SAVED, load)
})
onBeforeUnmount(() => {
    window.clearInterval(timer)
    window.clearTimeout(reread)
    window.clearTimeout(debounce)
    window.removeEventListener('keydown', onKey)
    window.removeEventListener(TASK_SAVED, load)
})
</script>

<template>
    <div class="p-board" :class="{ split: openId !== null }">
        <div class="p-board-left">
            <div class="p-board-bar">
                <label class="p-filter">
                    <Icon name="search" :size="16" />
                    <input ref="filter" v-model="q" data-board-filter type="search" placeholder="Фільтр: назва, клієнт, №" aria-label="Фільтр задач" />
                </label>
                <div v-if="store.meta" class="p-chips">
                    <button
                        v-for="l in store.meta.labels"
                        :key="l.id"
                        type="button"
                        class="p-chip"
                        :class="{ on: label === l.id }"
                        :style="label === l.id ? { background: l.color, borderColor: l.color } : {}"
                        :aria-pressed="label === l.id"
                        @click="label = label === l.id ? null : l.id"
                    >
                        <span class="dot" :style="{ background: label === l.id ? '#fff' : l.color }" />{{ l.name }}
                    </button>
                </div>
                <button type="button" class="p-btn primary p-new" @click="creating = true"><Icon name="plus" :size="18" :stroke="2.2" /><span>Нова задача</span></button>
            </div>

            <div v-if="error" class="t-error">{{ error }}</div>

            <div class="p-coltabs" role="tablist" aria-label="Колонки">
                <button
                    v-for="c in COLUMNS"
                    :key="c.key"
                    type="button"
                    role="tab"
                    :aria-selected="active === c.key"
                    :class="{ on: active === c.key }"
                    :style="{ '--col': c.color }"
                    @click="pick(c.key)"
                >
                    {{ c.title }}<span class="n">{{ board ? board[c.key].length : '·' }}</span><span v-if="unreadIn(c.key)" class="u" :title="unreadIn(c.key) + ' непрочитаних'" />
                </button>
            </div>

            <div class="p-cols">
                <section v-for="c in COLUMNS" :key="c.key" class="p-col" :class="{ on: active === c.key }" :style="{ '--col': c.color }">
                    <header class="p-col-head">
                        <span class="name">{{ c.title }}</span>
                        <span v-if="board && unreadIn(c.key)" class="u">+{{ unreadIn(c.key) }}</span>
                        <span class="n">{{ board ? board[c.key].length : '' }}</span>
                    </header>
                    <div class="p-col-body">
                        <template v-if="board">
                            <TaskCard
                                v-for="t in board[c.key]"
                                :key="t.id"
                                :task="t"
                                :show-status="c.status"
                                :selected="t.id === openId"
                                @open="open($event, c.key)"
                            />
                            <div v-if="!board[c.key].length" class="p-empty">{{ c.empty }}</div>
                        </template>
                        <template v-else>
                            <div v-for="i in 3" :key="i" class="p-skel" />
                        </template>
                    </div>
                </section>
            </div>
        </div>

        <div v-if="openId !== null" class="p-board-right">
            <!-- key: при переході на іншу задачу — чистий стан (поле вводу, опитування, нотатка рішення). -->
            <TaskView :key="openId" :id="String(openId)" embedded @close="close" />
        </div>

        <TaskForm v-if="creating" @close="creating = false" />
    </div>
</template>
