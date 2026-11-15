<script setup lang="ts">
import PersonName from '../../plan/PersonName.vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router'
import Avatar from '../tasks/Avatar.vue'
import TaskView from '../tasks/TaskView.vue'
import { tasksApi } from '../tasks/api'
import { setChronicleCounts, useChronicleCounts } from '../tasks/chronicle'
import { plural } from '../tasks/format'
import { TASK_SAVED } from '../tasks/shell/nav'
import { useSplitTask } from '../tasks/shell/split'
import { useSession } from '../../app/store'
import { planApi, type ChronicleEventFilter, type ChronicleModule, type ChronicleRow } from './api'
import { go, hrefOf } from './links'
import '../tasks/feed.css'
import './plan-search.css'

/**
 * Хроніка — «вхідні» всього «Плану», як у ПланФіксі: одна строка на задачу,
 * заявку чи позицію/рух складу з останньою подією, «+N» нових подій від інших,
 * групи «Сьогодні / Вчора / …», «Непрочитані», ★, фільтри за модулем і типом
 * події. Директор і адмін бачать усе, решта — де беруть участь.
 *
 * Фільтри — в адресі (?module=supply&event=comment&unread=0), тож «Назад» і
 * посилання працюють; без ?unread береться збережене в браузері.
 */
const route = useRoute()
const router = useRouter()
const session = useSession()
const { counts } = useChronicleCounts()
const { openId, open: openTask, close } = useSplitTask('tasks-chronicle')

const MODULES: { key: ChronicleModule; label: string }[] = [
    { key: 'task', label: 'Задачі' },
    { key: 'supply', label: 'Заявки' },
    { key: 'warehouse', label: 'Склад' },
]
const EVENTS: { key: ChronicleEventFilter; label: string }[] = [
    { key: 'comment', label: 'Коментарі' },
    { key: 'status', label: 'Статуси' },
    { key: 'file', label: 'Файли' },
    { key: 'created', label: 'Нове' },
    { key: 'stock', label: 'Рухи й закупівлі' },
]

const items = ref<ChronicleRow[]>([])
const next = ref<string | null>(null)
const modules = ref<ChronicleModule[]>(['task', 'supply'])
const loading = ref(false)
const error = ref('')
const listEl = ref<HTMLElement | null>(null)
const sentinel = ref<HTMLElement | null>(null)

function readPref(): boolean {
    try {
        return localStorage.getItem('plan.chronicle.unread') !== '0'
    } catch {
        return true
    }
}
function savePref(value: boolean) {
    try {
        localStorage.setItem('plan.chronicle.unread', value ? '1' : '0')
    } catch {
        /* приватне вікно */
    }
}

const csv = (v: unknown) => (typeof v === 'string' ? v.split(',').filter(Boolean) : [])
const filters = computed(() => ({
    modules: csv(route.query.module).filter((m): m is ChronicleModule => MODULES.some((x) => x.key === m)),
    events: csv(route.query.event).filter((e): e is ChronicleEventFilter => EVENTS.some((x) => x.key === e)),
    unread: route.query.unread === undefined ? readPref() : route.query.unread === '1',
    favorites: route.query.fav === '1',
}))

/** Фільтр змінився — новий запис історії (Назад повертає попередній). */
function setFilter(patch: Partial<{ modules: ChronicleModule[]; events: ChronicleEventFilter[]; unread: boolean; favorites: boolean }>) {
    const f = { ...filters.value, ...patch }
    if (patch.unread !== undefined) savePref(patch.unread)
    const query: LocationQueryRaw = {}
    if (f.modules.length) query.module = f.modules.join(',')
    if (f.events.length) query.event = f.events.join(',')
    query.unread = f.unread ? '1' : '0'
    if (f.favorites) query.fav = '1'
    router.push({ name: 'tasks-chronicle', params: route.params, query })
}

function toggle<T>(arr: T[], v: T): T[] {
    return arr.includes(v) ? arr.filter((x) => x !== v) : [...arr, v]
}

function startOfDay(d: Date): number {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
}

function groupOf(value: string): string {
    const today = startOfDay(new Date())
    const diff = Math.round((today - startOfDay(new Date(value))) / 86400000)
    if (diff <= 0) return 'Сьогодні'
    if (diff === 1) return 'Вчора'
    const weekday = (new Date().getDay() + 6) % 7
    if (diff <= weekday) return 'Цього тижня'
    if (diff <= weekday + 7) return 'Минулого тижня'
    return 'Раніше'
}

const groups = computed(() => {
    const out: { label: string; items: ChronicleRow[] }[] = []
    for (const item of items.value) {
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

const ICON: Record<string, string> = { comment: '💬', file: '📎', status: '⇄', closed: '✓', approval: '⚖', created: '＋', members: '👥', purchase: '₴', stock: '⇆', updated: '✎' }

let seq = 0
async function load(append = false) {
    const my = ++seq
    loading.value = true
    try {
        const f = filters.value
        const page = await planApi.chronicle({ ...f, cursor: append ? next.value : null })
        if (my !== seq) return
        if (append) {
            const known = new Set(items.value.map((i) => i.key))
            items.value.push(...page.items.filter((i) => !known.has(i.key)))
        } else {
            items.value = page.items
        }
        next.value = page.next
        modules.value = page.modules.map((m) => m.key)
        setChronicleCounts(page.counts)
        error.value = ''
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        if (my === seq) loading.value = false
    }
}

/** Опитування: свіжа перша сторінка зверху, догорнуте нижче лишається. */
async function refreshTop() {
    if (loading.value || document.hidden) return
    const my = seq
    try {
        const page = await planApi.chronicle(filters.value)
        // Поки чекали — змінили фільтр (почалося нове завантаження): ця відповідь застаріла.
        if (my !== seq || loading.value) return
        const fresh = new Set(page.items.map((i) => i.key))
        const edge = page.items[page.items.length - 1]?.lastActivityAt ?? ''
        const edgeAt = Date.parse(edge)
        const tail = page.next ? items.value.filter((i) => !fresh.has(i.key) && Date.parse(i.lastActivityAt) < edgeAt) : []
        const rows = [...page.items, ...tail]
        // Відкрита праворуч задача стала прочитаною — у «Непрочитаних» вона лишається на місці, поки її читають.
        const openKey = openId.value === null ? null : 'task-' + openId.value
        const at = openKey === null ? -1 : items.value.findIndex((i) => i.key === openKey)
        if (at >= 0 && !rows.some((i) => i.key === openKey)) rows.splice(Math.min(at, rows.length), 0, { ...items.value[at], unread: 0 })
        items.value = rows
        if (!tail.length) next.value = page.next
        setChronicleCounts(page.counts)
    } catch {
        /* наступна спроба */
    }
}

function href(item: ChronicleRow): string {
    return item.subject.type === 'task'
        ? router.resolve({ name: 'tasks-chronicle', params: { id: String(item.subject.id) }, query: route.query }).href
        : hrefOf(router, item.subject, item.url)
}

async function open(item: ChronicleRow, e?: MouseEvent) {
    if (e && (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1)) return
    e?.preventDefault()
    item.unread = 0
    if (item.subject.type === 'task') {
        openTask(item.subject.id)
        return
    }
    // Заявку / склад відкриваємо на їхній сторінці — «прочитано» ставимо тут.
    try {
        setChronicleCounts(await planApi.markRead(item.key))
    } catch {
        /* не критично */
    }
    go(router, item.subject, item.url)
}

async function markRead(item: ChronicleRow) {
    try {
        setChronicleCounts(await planApi.markRead(item.key))
        item.unread = 0
        if (filters.value.unread) items.value = items.value.filter((i) => i !== item)
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function readAll() {
    try {
        setChronicleCounts(await planApi.readAll())
        items.value.forEach((i) => (i.unread = 0))
        if (filters.value.unread) items.value = []
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function toggleFavorite(item: ChronicleRow) {
    if (item.subject.type !== 'task') return
    const on = !item.favorite
    item.favorite = on
    try {
        await tasksApi.favorite(item.subject.id, on)
        if (filters.value.favorites && !on) items.value = items.value.filter((i) => i !== item)
    } catch (e) {
        item.favorite = !on
        error.value = session.handle(e)
    }
}

/** ↑/↓ між подіями, Enter — відкрити. */
function onKey(e: KeyboardEvent) {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return
    const nodes = Array.from(listEl.value?.querySelectorAll<HTMLElement>('.f-item') ?? [])
    const i = nodes.indexOf(document.activeElement as HTMLElement)
    const target = nodes[e.key === 'ArrowDown' ? Math.min(nodes.length - 1, i + 1) : Math.max(0, i - 1)]
    if (target) {
        e.preventDefault()
        target.focus()
    }
}

const filterKey = computed(() => JSON.stringify(filters.value))
watch(filterKey, () => load())
// Лічильник змінився (нова подія деінде) — підтягуємо верх стрічки.
watch(
    () => counts.value.tasks + ':' + counts.value.events,
    (now, before) => now !== before && refreshTop(),
)

const byModule = computed(() => (counts.value as { byModule?: Partial<Record<ChronicleModule, number>> }).byModule ?? {})

let observer: IntersectionObserver | undefined
const onSaved = () => refreshTop()
onMounted(() => {
    load()
    window.addEventListener(TASK_SAVED, onSaved)
    if ('IntersectionObserver' in window) {
        observer = new IntersectionObserver((entries) => entries.some((x) => x.isIntersecting) && next.value && !loading.value && load(true), { rootMargin: '300px' })
        if (sentinel.value) observer.observe(sentinel.value)
    }
})
onBeforeUnmount(() => {
    window.removeEventListener(TASK_SAVED, onSaved)
    observer?.disconnect()
})
</script>

<template>
    <div class="p-split" :class="{ split: openId !== null }">
        <div class="p-split-left t-page f-page">
            <div class="f-head">
                <h1>Хроніка</h1>
                <span v-if="counts.tasks" class="t-sub">{{ counts.tasks }} {{ plural(counts.tasks, 'запис', 'записи', 'записів') }} з новим</span>
                <span class="sp" />
                <label class="f-toggle">
                    <input type="checkbox" :checked="filters.unread" @change="setFilter({ unread: !filters.unread })" />
                    <span class="sw" />
                    Непрочитані
                </label>
                <label class="f-toggle">
                    <input type="checkbox" :checked="filters.favorites" @change="setFilter({ favorites: !filters.favorites })" />
                    <span class="sw" />
                    ★ Обране
                </label>
                <button v-if="counts.tasks" type="button" class="t-btn small ghost" @click="readAll">✓ Прочитати все</button>
            </div>

            <div class="s-types c-filters" role="group" aria-label="Модуль">
                <button type="button" :class="{ on: !filters.modules.length }" :aria-pressed="!filters.modules.length" @click="setFilter({ modules: [] })">Усе</button>
                <button
                    v-for="m in MODULES.filter((x) => modules.includes(x.key))"
                    :key="m.key"
                    type="button"
                    :class="{ on: filters.modules.includes(m.key) }"
                    :aria-pressed="filters.modules.includes(m.key)"
                    @click="setFilter({ modules: toggle(filters.modules, m.key) })"
                >
                    {{ m.label }}
                    <span v-if="byModule[m.key]" class="n plus">+{{ byModule[m.key] }}</span>
                </button>
                <span class="c-sep" aria-hidden="true" />
                <button
                    v-for="ev in EVENTS"
                    :key="ev.key"
                    type="button"
                    class="ev"
                    :class="{ on: filters.events.includes(ev.key) }"
                    :aria-pressed="filters.events.includes(ev.key)"
                    @click="setFilter({ events: toggle(filters.events, ev.key) })"
                >
                    {{ ev.label }}
                </button>
            </div>

            <div v-if="error" class="t-error">{{ error }}</div>

            <div ref="listEl" class="f-list" @keydown="onKey">
                <template v-for="(g, gi) in groups" :key="gi + g.label">
                    <div class="f-group">{{ g.label }}</div>
                    <a
                        v-for="item in g.items"
                        :key="item.key"
                        class="f-item"
                        :class="['m-' + item.module, { unread: item.unread > 0, sel: item.subject.type === 'task' && item.subject.id === openId }]"
                        :href="href(item)"
                        @click="open(item, $event)"
                    >
                        <Avatar :person="item.event.author ?? item.owner" :size="36" />
                        <div>
                            <div class="who">
                                <PersonName v-if="item.event.author" :person="item.event.author" :link="false" />
                                <template v-else>Система</template>
                            </div>
                            <div class="at">{{ stamp(item.event.at) }}</div>
                        </div>
                        <span class="plus" :title="item.unread ? item.unread + ' ' + plural(item.unread, 'нова подія', 'нові події', 'нових подій') : ''">
                            {{ item.unread ? '+' + item.unread : '' }}
                        </span>
                        <div class="task">
                            <span class="c-mod">{{ item.moduleLabel }}</span>
                            <span v-if="item.number" class="num">№ {{ item.number }}</span>
                            <span class="title" :class="{ closed: item.closed }">{{ item.title }}</span>
                            <button
                                v-if="item.subject.type === 'task'"
                                type="button"
                                class="star"
                                :class="{ on: item.favorite }"
                                :title="item.favorite ? 'Прибрати з обраного' : 'В обране'"
                                @click.stop.prevent="toggleFavorite(item)"
                            >
                                {{ item.favorite ? '★' : '☆' }}
                            </button>
                        </div>
                        <div class="ev">
                            <span class="c-ico" aria-hidden="true">{{ ICON[item.event.group] ?? '•' }}</span>
                            {{ item.event.text }}
                        </div>
                        <div class="meta">
                            {{ item.statusLabel }}
                            <template v-if="item.client">· {{ item.client }}</template>
                            <template v-if="item.owner">
                                ·
                                <PersonName :person="item.owner" :link="false" />
                            </template>
                        </div>
                        <button v-if="item.unread" type="button" class="mark" title="Позначити прочитаним" @click.stop.prevent="markRead(item)">✓ прочитано</button>
                    </a>
                </template>
                <div v-if="!items.length && !loading" class="t-empty">
                    {{ filters.unread ? 'Усе прочитано. 🎉' : filters.favorites ? 'В обраному поки нічого — натисніть ☆ біля задачі.' : 'Подій ще немає.' }}
                </div>
                <div ref="sentinel" />
                <button v-if="next" type="button" class="t-btn small f-more-btn" :disabled="loading" @click="load(true)">{{ loading ? 'Завантаження…' : 'Показати ще' }}</button>
            </div>
        </div>
        <div v-if="openId !== null" class="p-split-right p-board-right">
            <TaskView :id="String(openId)" :key="openId" embedded @close="close" />
        </div>
    </div>
</template>

<style>
.c-filters {
    margin: -0.2rem 0 0.7rem;
}
.c-filters .c-sep {
    width: 1px;
    height: 22px;
    background: var(--border);
    margin: 0 0.2rem;
    flex: none;
}
.c-filters .ev {
    font-weight: 500;
}
.c-filters .plus {
    color: #ea580c;
    opacity: 1;
    font-weight: 800;
}
.f-item .c-mod {
    font-size: 0.68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0.05rem 0.35rem;
    border-radius: 5px;
    background: var(--surface-2);
    color: var(--text-muted);
    border: 1px solid var(--border);
    flex: none;
}
.f-item.m-supply .c-mod {
    color: #c2410c;
    border-color: #fdba74;
}
.f-item.m-warehouse .c-mod {
    color: #0f766e;
    border-color: #5eead4;
}
.f-item .c-ico {
    display: inline-block;
    min-width: 1.1em;
    opacity: 0.75;
}
a.f-item {
    text-decoration: none;
}
</style>
