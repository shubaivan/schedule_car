<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Avatar from './Avatar.vue'
import TaskView from './TaskView.vue'
import { tasksApi, type ChronicleItem } from './api'
import { setChronicleCounts, useChronicleCounts } from './chronicle'
import { plural } from './format'
import { TASK_SAVED } from './shell/nav'
import { useSplitTask } from './shell/split'
import { useSession } from '../../app/store'
import './feed.css'

/**
 * Хроніка — вхідні «Плану», як у ПланФіксі: одна строка на задачу з
 * останньою подією (коментар, файл, статус, учасники, створення/закриття),
 * «+N» нових подій від інших, групи «Сьогодні / Вчора / Цього тижня…»,
 * перемикач «Непрочитані», ★ «Обране». Відкрили задачу — вона прочитана.
 * embedded — компактний вигляд для колонки дошки.
 */
const props = defineProps<{ embedded?: boolean }>()
const emit = defineEmits<{ open: [taskId: number] }>()

const session = useSession()
const { counts } = useChronicleCounts()
const { openId, open: openTask, close } = useSplitTask('tasks-chronicle')

const items = ref<ChronicleItem[]>([])
const next = ref<string | null>(null)
const loading = ref(false)
const error = ref('')
const unreadOnly = ref(readPref('unread', true))
const favoritesOnly = ref(readPref('favorites', false))
const list = ref<HTMLElement | null>(null)
const sentinel = ref<HTMLElement | null>(null)

function readPref(key: string, fallback: boolean): boolean {
    try {
        const v = localStorage.getItem('plan.chronicle.' + key)
        return v === null ? fallback : v === '1'
    } catch {
        return fallback
    }
}
function savePref(key: string, value: boolean) {
    try {
        localStorage.setItem('plan.chronicle.' + key, value ? '1' : '0')
    } catch {
        /* приватне вікно — без запамʼятовування */
    }
}

function startOfDay(d: Date): number {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
}

/** Групи як у ПланФіксі. */
function groupOf(value: string): string {
    const date = new Date(value)
    const today = startOfDay(new Date())
    const diff = Math.round((today - startOfDay(date)) / 86400000)
    if (diff <= 0) return 'Сьогодні'
    if (diff === 1) return 'Вчора'
    const weekday = (new Date().getDay() + 6) % 7
    if (diff <= weekday) return 'Цього тижня'
    if (diff <= weekday + 7) return 'Минулого тижня'
    return 'Раніше'
}

const groups = computed(() => {
    const out: { label: string; items: ChronicleItem[] }[] = []
    for (const item of items.value) {
        // Групуємо за тим самим часом, за яким сортуємо, — інакше групи повторюються.
        const label = groupOf(item.lastActivityAt)
        const last = out[out.length - 1]
        if (last && last.label === label) last.items.push(item)
        else out.push({ label, items: [item] })
    }
    return out
})

const STAMP = new Intl.DateTimeFormat('uk-UA', { day: '2-digit', month: '2-digit' })
const CLOCK = new Intl.DateTimeFormat('uk-UA', { hour: '2-digit', minute: '2-digit' })
function stamp(value: string): string {
    const d = new Date(value)
    return startOfDay(d) === startOfDay(new Date()) ? CLOCK.format(d) : STAMP.format(d) + ', ' + CLOCK.format(d)
}

function eventLine(item: ChronicleItem): string {
    const e = item.event
    if (!e) return ''
    if (e.type === 'comment') return e.text + (e.filesCount ? ` · 📎 ${e.filesCount}` : '')
    if (e.type === 'file') return '📎 ' + e.files.map((f) => f.name).join(', ') + (e.filesCount > e.files.length ? ` +${e.filesCount - e.files.length}` : '')
    return e.text
}

async function load(append = false) {
    if (loading.value) return
    loading.value = true
    try {
        const page = await tasksApi.chronicle({ unread: unreadOnly.value, favorites: favoritesOnly.value, cursor: append ? next.value : null })
        if (append) {
            const known = new Set(items.value.map((i) => i.task.id))
            items.value.push(...page.items.filter((i) => !known.has(i.task.id)))
        } else {
            items.value = page.items
        }
        next.value = page.next
        setChronicleCounts(page.counts)
        error.value = ''
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        loading.value = false
    }
}

/** Опитування: свіжа перша сторінка зверху, догорнуте нижче лишається. */
async function refreshTop() {
    if (loading.value || document.hidden) return
    try {
        const page = await tasksApi.chronicle({ unread: unreadOnly.value, favorites: favoritesOnly.value })
        const fresh = new Set(page.items.map((i) => i.task.id))
        const edge = page.items[page.items.length - 1]?.lastActivityAt ?? ''
        const tail = page.next ? items.value.filter((i) => !fresh.has(i.task.id) && i.lastActivityAt < edge) : []
        const rows = [...page.items, ...tail]
        // Відкрита праворуч задача стала прочитаною — у «Непрочитаних» вона лишається на місці, поки її читають.
        const open = openId.value === null ? -1 : items.value.findIndex((i) => i.task.id === openId.value)
        if (open >= 0 && !rows.some((i) => i.task.id === openId.value)) {
            rows.splice(Math.min(open, rows.length), 0, { ...items.value[open], unread: 0 })
        }
        items.value = rows
        if (!tail.length) next.value = page.next
        setChronicleCounts(page.counts)
    } catch {
        /* наступна спроба */
    }
}

function open(item: ChronicleItem) {
    item.unread = 0
    if (props.embedded) emit('open', item.task.id)
    else openTask(item.task.id)
}

async function markRead(item: ChronicleItem) {
    try {
        setChronicleCounts(await tasksApi.markRead(item.task.id))
        item.unread = 0
        if (unreadOnly.value) items.value = items.value.filter((i) => i !== item)
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function readAll() {
    try {
        setChronicleCounts(await tasksApi.readAll())
        items.value.forEach((i) => (i.unread = 0))
        if (unreadOnly.value) items.value = []
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function toggleFavorite(item: ChronicleItem) {
    const on = !item.favorite
    item.favorite = on
    try {
        await tasksApi.favorite(item.task.id, on)
        if (favoritesOnly.value && !on) items.value = items.value.filter((i) => i !== item)
    } catch (e) {
        item.favorite = !on
        error.value = session.handle(e)
    }
}

/** ↑/↓ між подіями, Enter — відкрити. */
function onKey(e: KeyboardEvent) {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return
    const nodes = Array.from(list.value?.querySelectorAll<HTMLElement>('.f-item') ?? [])
    const i = nodes.indexOf(document.activeElement as HTMLElement)
    const target = nodes[e.key === 'ArrowDown' ? Math.min(nodes.length - 1, i + 1) : Math.max(0, i - 1)]
    if (target) {
        e.preventDefault()
        target.focus()
    }
}

watch(unreadOnly, (v) => {
    savePref('unread', v)
    load()
})
watch(favoritesOnly, (v) => {
    savePref('favorites', v)
    load()
})
// Лічильник змінився (нова подія деінде) — підтягуємо верх стрічки.
watch(() => counts.value.events, (now, before) => now !== before && refreshTop())

// Окремого таймера немає: лічильник (useChronicleCounts) опитує раз на 15 с,
// і стрічка оновлюється, лише коли там щось змінилось.
let observer: IntersectionObserver | undefined
const onSaved = () => refreshTop()
onMounted(() => {
    load()
    window.addEventListener(TASK_SAVED, onSaved)
    if ('IntersectionObserver' in window) {
        observer = new IntersectionObserver((entries) => entries.some((e) => e.isIntersecting) && next.value && load(true), { rootMargin: '300px' })
        if (sentinel.value) observer.observe(sentinel.value)
    }
})
onBeforeUnmount(() => {
    window.removeEventListener(TASK_SAVED, onSaved)
    observer?.disconnect()
})
</script>

<template>
    <div class="p-split" :class="{ split: !embedded && openId !== null }">
    <div class="p-split-left t-page f-page" :class="{ embedded }">
        <div class="f-head">
            <h1 v-if="!embedded">Хроніка</h1>
            <span v-if="counts.tasks" class="t-sub">{{ counts.tasks }} {{ plural(counts.tasks, 'задача', 'задачі', 'задач') }} з новим</span>
            <span class="sp" />
            <label class="f-toggle"><input v-model="unreadOnly" type="checkbox" /><span class="sw" />Непрочитані</label>
            <label class="f-toggle"><input v-model="favoritesOnly" type="checkbox" /><span class="sw" />★ Обране</label>
            <button v-if="counts.tasks" type="button" class="t-btn small ghost" @click="readAll">✓ Прочитати все</button>
        </div>

        <div v-if="error" class="t-error">{{ error }}</div>

        <div ref="list" class="f-list" @keydown="onKey">
            <template v-for="(g, gi) in groups" :key="gi + g.label">
                <div class="f-group">{{ g.label }}</div>
                <div
                    v-for="item in g.items"
                    :key="item.task.id"
                    class="f-item"
                    :class="{ unread: item.unread > 0, sel: !embedded && item.task.id === openId }"
                    role="link"
                    tabindex="0"
                    @click="open(item)"
                    @keydown.enter="open(item)"
                >
                    <Avatar :person="item.event?.author ?? item.task.creator" :size="36" />
                    <div>
                        <div class="who">{{ item.event?.author?.name ?? 'Система' }}</div>
                        <div class="at">{{ item.event ? stamp(item.event.at) : stamp(item.lastActivityAt) }}</div>
                    </div>
                    <span class="plus" :title="item.unread ? item.unread + ' ' + plural(item.unread, 'нова подія', 'нові події', 'нових подій') : ''">{{ item.unread ? '+' + item.unread : '' }}</span>
                    <div class="task">
                        <span class="num">№ {{ item.task.number }}</span>
                        <span class="title" :class="{ closed: item.task.closed }">{{ item.task.title }}</span>
                        <button type="button" class="star" :class="{ on: item.favorite }" :title="item.favorite ? 'Прибрати з обраного' : 'В обране'" @click.stop="toggleFavorite(item)">{{ item.favorite ? '★' : '☆' }}</button>
                    </div>
                    <div v-if="item.event" class="ev">
                        {{ eventLine(item) }}
                    </div>
                    <div class="meta">
                        {{ item.task.statusLabel }}<template v-if="item.task.client"> · {{ item.task.client }}</template><template v-if="item.task.creator"> · {{ item.task.creator.name }}</template>
                    </div>
                    <button v-if="item.unread" type="button" class="mark" title="Позначити прочитаним" @click.stop="markRead(item)">✓ прочитано</button>
                </div>
            </template>
            <div v-if="!items.length && !loading" class="t-empty">
                {{ unreadOnly ? 'Усе прочитано. 🎉' : favoritesOnly ? 'В обраному поки нічого — натисніть ☆ біля задачі.' : 'Подій ще немає.' }}
            </div>
            <div ref="sentinel" />
            <button v-if="next" type="button" class="t-btn small f-more-btn" :disabled="loading" @click="load(true)">{{ loading ? 'Завантаження…' : 'Показати ще' }}</button>
        </div>
    </div>
    <div v-if="!embedded && openId !== null" class="p-split-right p-board-right">
        <TaskView :key="openId" :id="String(openId)" embedded @close="close" />
    </div>
    </div>
</template>
