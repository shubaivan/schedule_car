<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, type SupplyRequest } from '../../app/api'
import { useSession } from '../../app/store'
import Icon from '../tasks/shell/Icon.vue'
import SupplyView from './SupplyView.vue'
import { ACCENT_COLOR, tone } from './status'
import { formatDate } from '../../shared/format'
import { ago } from '../tasks/format'
import { useSplit } from '../../plan/useSplit'
import { typing } from '../../plan/layers'
import './supply.css'

/**
 * Заявки в «Плані» — як задачі в ПланФіксі: щільний список ліворуч, відкрита заявка
 * праворуч (адреса /supply/board/:id), список із фільтрами й прокруткою лишається на місці.
 * ↑/↓ або J/K — сусідня заявка, Esc — закрити, N — нова заявка.
 * На телефоні заявка відкривається на весь екран, «Назад» повертає до списку.
 *
 * Фільтри живуть в адресі (?v=open&q=цемент&st=approval) — «Назад» відновлює те, що було.
 */
const session = useSession()
const route = useRoute()
const router = useRouter()
const { openId, open, close } = useSplit('supply-board')

type View = 'open' | 'decide' | 'mine' | 'overdue' | 'urgent' | 'all'
const VIEWS: { key: View; label: string }[] = [
    { key: 'open', label: 'Відкриті' },
    { key: 'decide', label: 'Чекають рішення' },
    { key: 'mine', label: 'Мої' },
    { key: 'overdue', label: 'Прострочені' },
    { key: 'urgent', label: 'Термінові' },
    { key: 'all', label: 'Усі' },
]

const str = (v: unknown) => (typeof v === 'string' ? v : '')
const view = computed<View>(() => (VIEWS.some((v) => v.key === route.query.v) ? (route.query.v as View) : 'open'))
const status = computed(() => str(route.query.st))
const department = computed(() => str(route.query.dep))
const q = ref(str(route.query.q))

const items = ref<SupplyRequest[]>([])
const total = ref(0)
const counts = ref<Record<string, number>>({})
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const error = ref('')
const listEl = ref<HTMLElement | null>(null)

const statuses = computed(() => session.meta?.statuses ?? [])
const departments = computed(() => session.meta?.departments ?? [])

function setQuery(patch: Record<string, string>) {
    const query: Record<string, string> = {}
    for (const [k, v] of Object.entries({ ...route.query, ...patch })) if (typeof v === 'string' && v !== '') query[k] = v
    router.replace({ name: 'supply-board', params: route.params, query })
}

function params(p: number) {
    const v = view.value
    return {
        status: status.value ? [status.value] : v === 'decide' ? ['approval', 'waiting'] : undefined,
        open: v === 'open' && !status.value ? true : undefined,
        author: v === 'mine' ? session.user?.id : undefined,
        overdue: v === 'overdue' || undefined,
        urgent: v === 'urgent' || undefined,
        department: department.value || undefined,
        q: q.value.trim() || undefined,
        page: p,
    }
}

// Кожне завантаження має номер: застосовуємо лише найсвіжішу відповідь.
let seq = 0
async function load(more = false) {
    const mine = ++seq
    loading.value = true
    try {
        const want = more ? page.value + 1 : 1
        const r = await api.requests(params(want))
        if (mine !== seq) return
        items.value = more ? [...items.value, ...r.items.filter((i) => !items.value.some((x) => x.id === i.id))] : r.items
        page.value = want
        total.value = r.total
        pages.value = Math.max(1, Math.ceil(r.total / r.pageSize))
        counts.value = r.counts
        error.value = ''
    } catch (e) {
        if (mine === seq) error.value = session.handle(e)
    } finally {
        if (mine === seq) loading.value = false
    }
}

/** Сусідня заявка у списку: ↑/↓ або K/J. */
function step(delta: number) {
    const list = items.value
    if (!list.length) return
    const i = list.findIndex((t) => t.id === openId.value)
    const next = list[Math.min(list.length - 1, Math.max(0, i < 0 ? 0 : i + delta))]
    if (next && next.id !== openId.value) open(next.id)
}

function onKey(e: KeyboardEvent) {
    if (e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented || route.query.f || typing(e.target) || !openId.value) return
    const inList = !!(e.target as HTMLElement | null)?.closest('.p-supply-left')
    if ((e.key === 'ArrowDown' && inList) || e.key === 'j' || e.key === 'о') step(1)
    else if ((e.key === 'ArrowUp' && inList) || e.key === 'k' || e.key === 'л') step(-1)
    else return
    e.preventDefault()
}

function click(e: MouseEvent, id: number) {
    if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return
    e.preventDefault()
    open(id)
}
const href = (id: number) => router.resolve({ name: 'supply-board', params: { id: String(id) }, query: route.query }).href

/** Заявку змінили праворуч (статус, мітка) — рядок ліворуч оновлюємо одразу. */
function changed(r: SupplyRequest) {
    const i = items.value.findIndex((x) => x.id === r.id)
    if (i >= 0) items.value[i] = { ...items.value[i], ...r }
}

let debounce: number | undefined
/** Що ми самі поклали в ?q= — щоб не затерти поле, поки людина друкує далі. */
let sentQ = q.value.trim()
watch(q, (value) => {
    window.clearTimeout(debounce)
    debounce = window.setTimeout(() => {
        sentQ = value.trim()
        setQuery({ q: sentQ })
    }, 300)
})
watch(() => [route.query.v, route.query.st, route.query.dep, route.query.q], () => {
    // ?q= змінився не від поля (Назад, посилання) — показуємо його в полі.
    if (str(route.query.q) !== sentQ) {
        sentQ = str(route.query.q)
        q.value = sentQ
    }
    load()
})
watch(openId, async (now) => {
    if (!now) return
    await nextTick()
    listEl.value?.querySelector<HTMLElement>(`[data-supply="${now}"]`)?.scrollIntoView({ block: 'nearest' })
})

let timer: number | undefined
onMounted(() => {
    load()
    // Тихе оновлення раз на 30 с — лише поки показана перша сторінка (інакше «Показати ще» б злетіло).
    timer = window.setInterval(() => !document.hidden && page.value === 1 && load(), 30000)
    window.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => {
    window.clearInterval(timer)
    window.clearTimeout(debounce)
    window.removeEventListener('keydown', onKey)
})
</script>

<template>
    <div class="p-board p-supply" :class="{ split: openId !== null }">
        <div class="p-board-left p-supply-left">
            <div class="p-board-bar">
                <label class="p-filter">
                    <Icon name="search" :size="16" />
                    <input v-model="q" data-board-filter type="search" placeholder="Номер, матеріал, обʼєкт" aria-label="Пошук заявок" />
                </label>
                <router-link :to="{ name: 'supply-new' }" class="p-btn primary p-new"><Icon name="plus" :size="18" :stroke="2.2" /><span>Нова заявка</span></router-link>
            </div>

            <div class="p-chips wrap" role="tablist" aria-label="Які заявки">
                <button
                    v-for="v in VIEWS"
                    :key="v.key"
                    type="button"
                    role="tab"
                    class="p-chip"
                    :class="{ on: view === v.key && !status }"
                    :aria-selected="view === v.key && !status"
                    @click="setQuery({ v: v.key === 'open' ? '' : v.key, st: '' })"
                >
                    {{ v.label }}<template v-if="v.key === 'decide'"> · {{ (counts.approval ?? 0) + (counts.waiting ?? 0) }}</template>
                </button>
            </div>
            <div class="p-supply-filters">
                <select :value="status" aria-label="Статус" @change="setQuery({ st: ($event.target as HTMLSelectElement).value })">
                    <option value="">Будь-який статус</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.emoji }} {{ s.label }} · {{ counts[s.value] ?? 0 }}</option>
                </select>
                <select :value="department" aria-label="Підрозділ" @change="setQuery({ dep: ($event.target as HTMLSelectElement).value })">
                    <option value="">Усі підрозділи</option>
                    <option v-for="d in departments" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
                </select>
            </div>

            <div v-if="error" class="t-error">{{ error }}</div>

            <div ref="listEl" class="p-col on p-supply-list" :aria-busy="loading">
                <div class="p-col-body">
                    <a
                        v-for="r in items"
                        :key="r.id"
                        :href="href(r.id)"
                        class="p-card-task p-supply-row"
                        :class="{ selected: r.id === openId, closed: r.status === 'rejected' }"
                        :data-supply="r.id"
                        :aria-current="r.id === openId ? 'true' : undefined"
                        @click="click($event, r.id)"
                    >
                        <span v-if="r.accent !== 'none'" class="stripe" :style="{ background: ACCENT_COLOR[r.accent] }" :title="r.accentLabel" />
                        <span class="row top">
                            <span class="num">№ {{ r.number }}</span>
                            <span v-if="r.urgent" class="p-urgent">терміново</span>
                            <span class="when" :title="'Подано: ' + new Date(r.createdAt).toLocaleString('uk-UA')">{{ ago(r.createdAt) }}</span>
                        </span>
                        <span class="title">{{ r.item }} — {{ r.quantityLabel }}</span>
                        <span class="client">{{ r.author.name }}<template v-if="r.department"> · [{{ r.department }}]</template><template v-if="r.site"> · {{ r.site }}</template></span>
                        <span class="row foot">
                            <span class="st" :class="tone(r.status)">{{ r.statusLabel }}</span>
                            <span v-if="r.needBy" class="due" :class="{ over: r.overdue }" :title="'Потрібно до ' + formatDate(r.needBy)">
                                <Icon name="clock" :size="13" :stroke="2" />{{ r.overdue ? 'прострочено · ' : 'до ' }}{{ formatDate(r.needBy) }}
                            </span>
                        </span>
                    </a>
                    <div v-if="!loading && !items.length" class="p-empty">
                        {{ view === 'open' && !status && !q ? 'Відкритих заявок немає. «Нова заявка» — угорі.' : 'За цим фільтром заявок немає.' }}
                    </div>
                    <template v-if="loading && !items.length">
                        <div v-for="i in 4" :key="i" class="p-skel" />
                    </template>
                    <button v-if="page < pages" type="button" class="p-btn ghost p-more-btn" :disabled="loading" @click="load(true)">Показати ще · {{ total - items.length }}</button>
                </div>
            </div>
        </div>

        <div v-if="openId !== null" class="p-board-right">
            <SupplyView :key="openId" :id="String(openId)" embedded @close="close" @changed="changed" />
        </div>
    </div>
</template>
