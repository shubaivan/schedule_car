<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router'
import Avatar from '../tasks/Avatar.vue'
import TaskView from '../tasks/TaskView.vue'
import { useSplitTask } from '../tasks/shell/split'
import { useMobile } from '../tasks/shell/nav'
import type { Person } from '../tasks/api'
import { useSession } from '../../app/store'
import { planApi, type SearchHit, type SearchMeta, type SearchResponse, type SearchGroup, type SavedSearch } from './api'
import { go, hrefOf } from './links'
import { forget, hasFilters, isEmpty, isoDay, parse, recent, remember, ROLES, SEARCH_KEYS, toQuery, toString, type PersonRole, type SearchState } from './searchQuery'
import '../tasks/feed.css'
import './plan-search.css'

/**
 * Розумний пошук «Плану»: одне поле по всьому (задачі, заявки, склад, коментарі,
 * файли, люди) + фасети з лічильниками (тип, статус, людина за роллю, відділ,
 * контрагент, період, ★). Порожній запит + фільтр = «усі прецеденти менеджера X».
 *
 * Увесь стан — в адресі: посилання можна переслати, «Назад» повертає попередні
 * фільтри (зміна фільтра — новий запис історії, набір тексту — заміна). Жодних
 * модалок: панель фільтрів на телефоні — теж стан адреси (?panel=1).
 * Клавіатура: «/» — у поле, ↑/↓ — результати, Enter — відкрити, Esc — закрити/очистити.
 */
const route = useRoute()
const router = useRouter()
const session = useSession()
const mobile = useMobile()
const { openId, open: openTask, close } = useSplitTask('tasks-search')

const state = computed<SearchState>(() => parse(route.query))
const qInput = ref(state.value.q)
const clientInput = ref(state.value.client)
const result = ref<SearchResponse | null>(null)
const meta = ref<SearchMeta | null>(null)
const saved = ref<SavedSearch[]>([])
const recents = ref<string[]>(recent())
const loading = ref(false)
const error = ref('')
const sel = ref(0)
const input = ref<HTMLInputElement | null>(null)
const list = ref<HTMLElement | null>(null)
const sentinel = ref<HTMLElement | null>(null)
const saving = ref(false)
const saveName = ref('')
const panelOpen = computed(() => route.query.panel === '1')

const DATE = new Intl.DateTimeFormat('uk-UA', { day: '2-digit', month: '2-digit', year: '2-digit' })
const fmt = (v: string) => DATE.format(new Date(v))

const GROUP_ICON: Record<SearchGroup, string> = { task: 'З', supply: 'Зв', warehouse: 'Ск', comment: '💬', file: '📎', person: '👤' }

/** Параметри, які не належать пошуку, — зберігаємо (відкрита задача ?m= ?f=, панель). */
function extra(): Record<string, string> {
    const out: Record<string, string> = {}
    for (const [k, v] of Object.entries(route.query)) if (!SEARCH_KEYS.includes(k) && typeof v === 'string') out[k] = v
    return out
}

/** Новий стан пошуку. push — окремий крок історії (фільтр), replace — набір тексту. */
function apply(next: Partial<SearchState>, mode: 'push' | 'replace' = 'push') {
    const s = { ...state.value, ...next }
    const keep = extra()
    // Відкрита праворуч задача (разом з ?m= / ?f=) лишається відкритою — змінюється лише список.
    const query: LocationQueryRaw = { ...toQuery(s), ...(keep.panel ? { panel: keep.panel } : {}), ...(openId.value !== null ? pick(keep, ['m', 'f']) : {}) }
    const to = { name: 'tasks-search', params: route.params, query }
    if (mode === 'push') router.push(to)
    else router.replace(to)
}

function pick(obj: Record<string, string>, keys: string[]): Record<string, string> {
    return Object.fromEntries(Object.entries(obj).filter(([k]) => keys.includes(k)))
}

function toggle<T>(arr: T[], value: T): T[] {
    return arr.includes(value) ? arr.filter((v) => v !== value) : [...arr, value]
}

function togglePanel() {
    const query = { ...route.query }
    if (panelOpen.value) {
        // Відкрили панель кнопкою — закриваємо «Назад», щоб не плодити кроків історії.
        if ((window.history.state as { back?: string } | null)?.back?.includes('/tasks/search')) router.back()
        else {
            delete query.panel
            router.replace({ query })
        }
    } else router.push({ query: { ...query, panel: '1' } })
}

// --- запит ---
let controller: AbortController | null = null
let seq = 0

async function run(append = false) {
    if (route.name !== 'tasks-search') return
    const s = state.value
    const my = ++seq
    controller?.abort()
    controller = new AbortController()
    loading.value = true
    const params = new URLSearchParams(toQuery(s))
    if (append && result.value) params.set('offset', String(result.value.offset + result.value.hits.length))
    try {
        const r = await planApi.search(params.toString(), controller.signal)
        if (my !== seq) return
        if (append && result.value) {
            result.value = { ...r, offset: result.value.offset, hits: [...result.value.hits, ...r.hits] }
        } else {
            result.value = r
            sel.value = 0
        }
        error.value = ''
        if (!isEmpty(s)) scheduleRemember(toString(s))
    } catch (e) {
        if ((e as Error).name === 'AbortError') return
        error.value = session.handle(e)
    } finally {
        if (my === seq) loading.value = false
    }
}

/** Недавнім вважаємо пошук, на якому людина затрималась (а не кожну літеру). */
let rememberTimer: number | undefined
function scheduleRemember(query: string) {
    window.clearTimeout(rememberTimer)
    rememberTimer = window.setTimeout(() => {
        remember(query)
        recents.value = recent()
    }, 1500)
}

// Адреса змінилась (фільтр, «Назад», посилання) — шукаємо. Відкриття задачі праворуч (:id, ?m=, ?f=) пошук не перезапускає.
const searchKey = computed(() => toString(state.value))
watch(searchKey, () => run())
watch(() => state.value.q, (v) => {
    if (v !== qInput.value.trim()) qInput.value = v
})
watch(() => state.value.client, (v) => {
    if (v !== clientInput.value.trim()) clientInput.value = v
})

let debounce: number | undefined
watch(qInput, (v) => {
    window.clearTimeout(debounce)
    debounce = window.setTimeout(() => {
        if (v.trim() !== state.value.q) apply({ q: v }, 'replace')
    }, 250)
})
let clientDebounce: number | undefined
watch(clientInput, (v) => {
    window.clearTimeout(clientDebounce)
    clientDebounce = window.setTimeout(() => {
        if (v.trim() !== state.value.client) apply({ client: v }, 'replace')
    }, 400)
})

// --- довідники для фільтрів ---
const peopleById = computed(() => {
    const m = new Map<number, Person>()
    meta.value?.people.forEach((p) => m.set(p.id, p))
    result.value?.people.forEach((p) => m.set(p.id, p))
    return m
})
const departmentName = (id: number) => meta.value?.departments.find((d) => d.id === id)?.name ?? `#${id}`
const statusLabel = (key: string) => meta.value?.statuses.find((s) => s.key === key)?.label ?? result.value?.facets.status.find((s) => s.key === key)?.label ?? key
const typeLabel = (key: string) => meta.value?.types.find((t) => t.key === key)?.label ?? key
const roleLabel = (role: PersonRole) => ROLES.find((r) => r.key === role)?.label ?? role

/** Активні фільтри чипами — кожен знімається окремо. */
const chips = computed(() => {
    const s = state.value
    const out: { key: string; label: string; drop: Partial<SearchState> }[] = []
    s.types.forEach((t) => out.push({ key: 'type-' + t, label: typeLabel(t), drop: { types: s.types.filter((x) => x !== t) } }))
    if (s.person) {
        const who = peopleById.value.get(s.person.id)?.name ?? `#${s.person.id}`
        out.push({ key: 'person', label: `${who} · ${roleLabel(s.person.role)}`, drop: { person: null } })
    }
    s.statuses.forEach((st) => out.push({ key: 'status-' + st, label: statusLabel(st), drop: { statuses: s.statuses.filter((x) => x !== st) } }))
    s.departments.forEach((d) => out.push({ key: 'dep-' + d, label: departmentName(d), drop: { departments: s.departments.filter((x) => x !== d) } }))
    if (s.client) out.push({ key: 'client', label: 'Контрагент: ' + s.client, drop: { client: '' } })
    if (s.from || s.to) out.push({ key: 'date', label: `${s.from ? 'з ' + fmt(s.from) : ''} ${s.to ? 'по ' + fmt(s.to) : ''}`.trim(), drop: { from: '', to: '' } })
    if (s.starred) out.push({ key: 'starred', label: '★ Обране', drop: { starred: false } })
    return out
})

function reset() {
    qInput.value = ''
    clientInput.value = ''
    apply({ q: '', types: [], person: null, departments: [], statuses: [], client: '', from: '', to: '', starred: false, sort: 'relevance' })
}

// Фасет «статус»: обрані — завжди в списку (навіть з нулем), згруповані за типом.
const statusGroups = computed(() => {
    const facet = result.value?.facets.status ?? []
    const rows = [...facet]
    for (const key of state.value.statuses) {
        if (!rows.some((r) => r.key === key)) {
            const m = meta.value?.statuses.find((s) => s.key === key)
            rows.push({ key, label: m?.label ?? key, kind: m?.kind ?? key.split(':')[0], kindLabel: m?.kindLabel ?? '', count: 0 })
        }
    }
    const groups = new Map<string, typeof rows>()
    for (const r of rows) groups.set(r.kindLabel || r.kind, [...(groups.get(r.kindLabel || r.kind) ?? []), r])
    return [...groups.entries()]
})

const departmentRows = computed(() => {
    const rows = [...(result.value?.facets.department ?? [])]
    for (const id of state.value.departments) if (!rows.some((r) => r.id === id)) rows.push({ id, name: departmentName(id), count: 0 })
    return rows
})

function setPerson(id: number | null, role: PersonRole = state.value.person?.role ?? 'person') {
    apply({ person: id ? { role, id } : null })
}

function onPersonSelect(e: Event) {
    const id = Number((e.target as HTMLSelectElement).value)
    setPerson(id > 0 ? id : null)
}

function onRoleSelect(e: Event) {
    const role = (e.target as HTMLSelectElement).value as PersonRole
    if (state.value.person) setPerson(state.value.person.id, role)
}

function onDepartmentSelect(e: Event) {
    const id = Number((e.target as HTMLSelectElement).value)
    if (id > 0 && !state.value.departments.includes(id)) apply({ departments: [...state.value.departments, id] })
    ;(e.target as HTMLSelectElement).value = ''
}

function preset(days: number | 'year') {
    const to = new Date()
    const from = days === 'year' ? new Date(to.getFullYear(), 0, 1) : new Date(to.getTime() - days * 86400000)
    apply({ from: isoDay(from), to: isoDay(to) })
}

function onDate(field: 'from' | 'to', e: Event) {
    apply({ [field]: (e.target as HTMLInputElement).value })
}

// --- збережені й недавні ---
async function save() {
    const name = saveName.value.trim()
    if (!name) return
    try {
        const item = await planApi.saveSearch(name, toString(state.value))
        saved.value = [item, ...saved.value]
        saving.value = false
        saveName.value = ''
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function unsave(item: SavedSearch) {
    try {
        saved.value = await planApi.deleteSaved(item.id)
    } catch (e) {
        error.value = session.handle(e)
    }
}

function openQuery(query: string) {
    router.push({ name: 'tasks-search', query: Object.fromEntries(new URLSearchParams(query)) })
}

/** «цемент · Заявки · Ольга Петренко (автор)» — людський підпис рядка запиту. */
function describe(query: string): string {
    const s = parse(Object.fromEntries(new URLSearchParams(query)))
    const parts: string[] = []
    if (s.q) parts.push('«' + s.q + '»')
    s.types.forEach((t) => parts.push(typeLabel(t)))
    if (s.person) parts.push(`${peopleById.value.get(s.person.id)?.name ?? '#' + s.person.id} (${roleLabel(s.person.role)})`)
    s.statuses.forEach((st) => parts.push(statusLabel(st)))
    s.departments.forEach((d) => parts.push(departmentName(d)))
    if (s.client) parts.push(s.client)
    if (s.from || s.to) parts.push([s.from && fmt(s.from), s.to && fmt(s.to)].filter(Boolean).join('–'))
    if (s.starred) parts.push('★')
    return parts.join(' · ') || 'Усе'
}

function dropRecent(query: string) {
    forget(query)
    recents.value = recent()
}

// --- результати й клавіатура ---
const hits = computed(() => result.value?.hits ?? [])

function hitHref(h: SearchHit): string {
    if (h.subject.type === 'task') {
        const q = new URLSearchParams({ ...toQuery(state.value), ...(h.docType === 'comment' ? { m: String(h.refId) } : h.docType === 'file' ? { f: String(h.refId) } : {}) })
        return router.resolve({ name: 'tasks-search', params: { id: String(h.subject.id) } }).href + '?' + q
    }
    return hrefOf(router, h.subject, h.url)
}

function openHit(h: SearchHit, index?: number) {
    if (index !== undefined) sel.value = index
    if (h.subject.type === 'task') {
        openTask(h.subject.id, h.docType === 'comment' ? { m: String(h.refId) } : h.docType === 'file' ? { f: String(h.refId) } : {})
    } else {
        go(router, h.subject, h.url)
    }
}

function onClick(e: MouseEvent, h: SearchHit, i: number) {
    // Ctrl/⌘/середня кнопка — нова вкладка, як у звичайного посилання.
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return
    e.preventDefault()
    openHit(h, i)
}

function move(delta: number) {
    if (!hits.value.length) return
    sel.value = Math.max(0, Math.min(hits.value.length - 1, sel.value + delta))
    nextTick(() => list.value?.querySelectorAll<HTMLElement>('.s-hit')[sel.value]?.scrollIntoView({ block: 'nearest' }))
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'ArrowDown') {
        e.preventDefault()
        move(1)
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        move(-1)
    } else if (e.key === 'Enter') {
        const h = hits.value[sel.value]
        if (h) {
            e.preventDefault()
            // Набраний текст одразу в адресу — щоб «Назад» із задачі повернув саме цей пошук.
            window.clearTimeout(debounce)
            if (qInput.value.trim() !== state.value.q) apply({ q: qInput.value }, 'replace')
            openHit(h)
        }
    } else if (e.key === 'Escape') {
        if (openId.value !== null) close()
        else if (qInput.value) qInput.value = ''
        else (e.target as HTMLElement).blur()
    }
}

const summary = computed(() => {
    const r = result.value
    if (!r) return ''
    const n = r.total
    const word = n % 10 === 1 && n % 100 !== 11 ? 'результат' : [2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? 'результати' : 'результатів'
    return `${n}${r.totalExact ? '' : '+'} ${word}`
})

let observer: IntersectionObserver | undefined
onMounted(async () => {
    if (openId.value === null && !mobile.value) input.value?.focus()
    run()
    try {
        meta.value = await planApi.searchMeta()
        saved.value = meta.value.saved
    } catch (e) {
        error.value = session.handle(e)
    }
    if ('IntersectionObserver' in window) {
        observer = new IntersectionObserver((entries) => {
            if (entries.some((x) => x.isIntersecting) && result.value?.more && !loading.value) run(true)
        }, { rootMargin: '400px' })
        if (sentinel.value) observer.observe(sentinel.value)
    }
})
onBeforeUnmount(() => {
    window.clearTimeout(debounce)
    window.clearTimeout(clientDebounce)
    window.clearTimeout(rememberTimer)
    controller?.abort()
    observer?.disconnect()
})
</script>

<template>
    <div class="p-split" :class="{ split: openId !== null }">
        <div class="p-split-left t-page s-page" :class="{ narrow: openId !== null }">
            <div class="f-search-box">
                <input
                    ref="input"
                    v-model="qInput"
                    class="t-input"
                    type="search"
                    enterkeyhint="search"
                    placeholder="Пошук: задачі, заявки, склад, коментарі, файли, люди"
                    aria-label="Пошук"
                    aria-controls="s-results"
                    autocomplete="off"
                    @keydown="onKey"
                />
                <button v-if="qInput" type="button" class="clear" aria-label="Очистити" @click="qInput = ''; input?.focus()">✕</button>
            </div>

            <div class="s-types" role="group" aria-label="Тип">
                <button type="button" :class="{ on: !state.types.length }" :aria-pressed="!state.types.length" @click="apply({ types: [] })">
                    Усе<span v-if="result" class="n">{{ result.facets.type.reduce((a, t) => a + t.count, 0) }}</span>
                </button>
                <button
                    v-for="t in meta?.types ?? []"
                    :key="t.key"
                    type="button"
                    :class="{ on: state.types.includes(t.key) }"
                    :aria-pressed="state.types.includes(t.key)"
                    @click="apply({ types: toggle(state.types, t.key) })"
                >
                    {{ t.label }}<span v-if="result" class="n">{{ result.facets.type.find((f) => f.key === t.key)?.count ?? 0 }}</span>
                </button>
            </div>

            <div v-if="chips.length || !isEmpty(state)" class="s-active">
                <button v-for="c in chips" :key="c.key" type="button" class="s-chip" :title="'Прибрати: ' + c.label" @click="apply(c.drop)">{{ c.label }} <span aria-hidden="true">✕</span></button>
                <button v-if="chips.length > 1" type="button" class="s-link" @click="reset">Скинути все</button>
                <span class="sp" />
                <button v-if="!saving && !isEmpty(state)" type="button" class="s-link" @click="saving = true; saveName = describe(toString(state)).slice(0, 80)">☆ Зберегти пошук</button>
            </div>
            <form v-if="saving" class="s-save" @submit.prevent="save">
                <input v-model="saveName" class="t-input" maxlength="80" placeholder="Назва, напр. «Заявки Ольги за листопад»" aria-label="Назва збереженого пошуку" @keydown.esc="saving = false" />
                <button type="submit" class="t-btn small primary" :disabled="!saveName.trim()">Зберегти</button>
                <button type="button" class="t-btn small ghost" @click="saving = false">Скасувати</button>
            </form>

            <div v-if="error" class="t-error">{{ error }}</div>

            <div class="s-body">
                <aside class="s-facets" :class="{ open: panelOpen }" aria-label="Фільтри">
                    <section v-if="saved.length" class="s-sec">
                        <h3>Збережені</h3>
                        <div v-for="s in saved" :key="s.id" class="s-saved">
                            <a :href="'/crm/tasks/search?' + s.query" @click.prevent="openQuery(s.query)">{{ s.name }}</a>
                            <button type="button" class="x" :aria-label="'Видалити «' + s.name + '»'" @click="unsave(s)">✕</button>
                        </div>
                    </section>

                    <section class="s-sec">
                        <h3>Людина</h3>
                        <div class="s-person">
                            <select class="t-select" aria-label="Людина" :value="state.person?.id ?? ''" @change="onPersonSelect">
                                <option value="">Будь-хто</option>
                                <option v-for="p in meta?.people ?? []" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                            <select class="t-select" aria-label="Роль" :value="state.person?.role ?? 'person'" :disabled="!state.person" @change="onRoleSelect">
                                <option v-for="r in ROLES" :key="r.key" :value="r.key">{{ r.label }}</option>
                            </select>
                        </div>
                        <template v-if="result?.facets.author.length">
                            <h4>Автори</h4>
                            <button v-for="a in result.facets.author.slice(0, 6)" :key="'a' + a.id" type="button" class="s-row" :class="{ on: state.person?.role === 'author' && state.person.id === a.id }" @click="setPerson(state.person?.role === 'author' && state.person.id === a.id ? null : a.id, 'author')">
                                <Avatar :person="a.person" :size="18" /><span class="l">{{ a.name }}</span><span class="n">{{ a.count }}</span>
                            </button>
                        </template>
                        <template v-if="result?.facets.executor.length">
                            <h4>Виконавці</h4>
                            <button v-for="a in result.facets.executor.slice(0, 6)" :key="'e' + a.id" type="button" class="s-row" :class="{ on: state.person?.role === 'executor' && state.person.id === a.id }" @click="setPerson(state.person?.role === 'executor' && state.person.id === a.id ? null : a.id, 'executor')">
                                <Avatar :person="a.person" :size="18" /><span class="l">{{ a.name }}</span><span class="n">{{ a.count }}</span>
                            </button>
                        </template>
                    </section>

                    <section v-if="statusGroups.length" class="s-sec">
                        <h3>Статус</h3>
                        <template v-for="[kind, rows] in statusGroups" :key="kind">
                            <h4>{{ kind }}</h4>
                            <label v-for="s in rows" :key="s.key" class="s-row check">
                                <input type="checkbox" :checked="state.statuses.includes(s.key)" @change="apply({ statuses: toggle(state.statuses, s.key) })" />
                                <span class="l">{{ s.label }}</span><span class="n">{{ s.count }}</span>
                            </label>
                        </template>
                    </section>

                    <section class="s-sec">
                        <h3>Відділ</h3>
                        <label v-for="d in departmentRows" :key="d.id" class="s-row check">
                            <input type="checkbox" :checked="state.departments.includes(d.id)" @change="apply({ departments: toggle(state.departments, d.id) })" />
                            <span class="l">{{ d.name }}</span><span class="n">{{ d.count }}</span>
                        </label>
                        <select class="t-select" aria-label="Додати відділ" @change="onDepartmentSelect">
                            <option value="">+ інший відділ…</option>
                            <option v-for="d in (meta?.departments ?? []).filter((x) => !departmentRows.some((r) => r.id === x.id))" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                    </section>

                    <section class="s-sec">
                        <h3>Контрагент</h3>
                        <input v-model="clientInput" class="t-input" type="search" placeholder="Клієнт, постачальник, об'єкт" aria-label="Контрагент" />
                        <button v-for="c in (result?.facets.client ?? []).slice(0, 6)" :key="c.name" type="button" class="s-row" :class="{ on: state.client === c.name }" @click="apply({ client: state.client === c.name ? '' : c.name })">
                            <span class="l">{{ c.name }}</span><span class="n">{{ c.count }}</span>
                        </button>
                    </section>

                    <section class="s-sec">
                        <h3>Період</h3>
                        <div class="s-dates">
                            <label>з <input class="t-input" type="date" :value="state.from" :max="state.to || undefined" @change="onDate('from', $event)" /></label>
                            <label>по <input class="t-input" type="date" :value="state.to" :min="state.from || undefined" @change="onDate('to', $event)" /></label>
                        </div>
                        <div class="s-presets">
                            <button type="button" class="s-link" @click="preset(7)">7 днів</button>
                            <button type="button" class="s-link" @click="preset(30)">30 днів</button>
                            <button type="button" class="s-link" @click="preset('year')">Цей рік</button>
                        </div>
                    </section>

                    <section class="s-sec">
                        <label class="f-toggle"><input type="checkbox" :checked="state.starred" @change="apply({ starred: !state.starred })" /><span class="sw" />★ Лише обране</label>
                    </section>
                </aside>

                <section id="s-results" class="s-results" aria-live="polite">
                    <div class="s-summary">
                        <span v-if="result"><b>{{ summary }}</b> <span class="t-sub s-took">· {{ result.tookMs }} мс<template v-if="result.approximate"> · лічильники фільтрів — приблизні, уточніть запит</template></span></span>
                        <span v-if="loading" class="t-sub">…</span>
                        <span class="sp" />
                        <button type="button" class="t-btn small s-panel-btn" :aria-expanded="panelOpen" @click="togglePanel">Фільтри<template v-if="chips.length"> · {{ chips.length }}</template></button>
                        <select class="t-select s-sort" aria-label="Сортування" :value="state.sort" @change="apply({ sort: ($event.target as HTMLSelectElement).value as SearchState['sort'] })">
                            <option value="relevance">За збігом</option>
                            <option value="recent">Новіші</option>
                        </select>
                    </div>

                    <div v-if="isEmpty(state) && recents.length" class="s-recent">
                        <h3>Недавні пошуки</h3>
                        <div v-for="r in recents" :key="r" class="s-saved">
                            <a :href="'/crm/tasks/search?' + r" @click.prevent="openQuery(r)">{{ describe(r) }}</a>
                            <button type="button" class="x" aria-label="Прибрати з недавніх" @click="dropRecent(r)">✕</button>
                        </div>
                    </div>
                    <div v-if="isEmpty(state)" class="f-empty-hint">
                        Шукайте за частиною слова: назви, описи, коментарі, файли, номери заявок і позицій складу. Без слова, лише з фільтром — усі прецеденти людини, відділу чи контрагента.
                    </div>
                    <h3 v-if="isEmpty(state) && hits.length" class="s-latest">Останні зміни</h3>
                    <div v-else-if="result && !result.total && !loading" class="f-empty-hint">Нічого не знайдено<template v-if="state.q"> за «{{ state.q }}»</template>. <button v-if="hasFilters(state)" type="button" class="s-link" @click="reset">Скинути фільтри</button></div>

                    <div ref="list" class="s-list" role="listbox" aria-label="Результати">
                        <a
                            v-for="(h, i) in hits"
                            :key="h.id"
                            class="s-hit"
                            :class="['g-' + h.group, { sel: i === sel, open: h.subject.type === 'task' && h.subject.id === openId }]"
                            role="option"
                            :aria-selected="i === sel"
                            :href="hitHref(h)"
                            @click="onClick($event, h, i)"
                        >
                            <span class="s-ico" :title="h.docLabel" aria-hidden="true"><img v-if="h.thumb" :src="h.thumb" alt="" loading="lazy" /><template v-else>{{ GROUP_ICON[h.group] }}</template></span>
                            <span class="s-main">
                                <span class="s-top"><span class="s-kind">{{ h.docLabel }}</span><span v-if="h.statusLabel" class="s-status" :class="{ closed: h.closed }">{{ h.statusLabel }}</span><span class="date">{{ fmt(h.at) }}</span></span>
                                <span class="s-ttl" :class="{ closed: h.closed && h.group !== 'comment' && h.group !== 'file' }"><template v-for="(p, k) in h.title" :key="k"><mark v-if="p.m">{{ p.t }}</mark><template v-else>{{ p.t }}</template></template></span>
                                <span v-if="h.snippet.length" class="s-snip"><template v-for="(p, k) in h.snippet" :key="k"><mark v-if="p.m">{{ p.t }}</mark><template v-else>{{ p.t }}</template></template></span>
                                <span v-if="h.author || h.client || (h.departments.length && h.group !== 'person')" class="s-meta">
                                    <template v-if="h.author"><Avatar :person="h.author" :size="16" />{{ h.author.name }}</template>
                                    <template v-if="h.client"><span v-if="h.author" class="dot">·</span>{{ h.client }}</template>
                                    <span v-if="h.departments.length && h.group !== 'person'" class="s-deps"><span v-if="h.author || h.client" class="dot">·</span>{{ h.departments.join(', ') }}</span>
                                </span>
                            </span>
                        </a>
                    </div>
                    <div ref="sentinel" />
                    <button v-if="result?.more" type="button" class="t-btn small f-more-btn" :disabled="loading" @click="run(true)">{{ loading ? 'Завантаження…' : 'Показати ще' }}</button>
                </section>
            </div>
        </div>
        <div v-if="openId !== null" class="p-split-right p-board-right">
            <TaskView :key="openId + ':' + (route.query.m ?? '') + ':' + (route.query.f ?? '')" :id="String(openId)" embedded @close="close" />
        </div>
    </div>
</template>
