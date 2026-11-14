<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSession } from '../../app/store'
import Icon from '../tasks/shell/Icon.vue'
import ItemView from './ItemView.vue'
import WarehouseNav from './WarehouseNav.vue'
import { qty, whApi, type WhItemRow, type WhMeta } from './api'
import { useSplit } from '../../plan/useSplit'
import { typing } from '../../plan/layers'
import { accessError } from './access'
import '../supply/supply.css'
import './warehouse.css'

/**
 * Склад у «Плані»: щільний список позицій ліворуч, картка праворуч (/sklad/items/:id),
 * як задачі й заявки. Фільтри — в адресі (?q=кільце&cat=3&place=5&v=bulk), тож
 * «Назад» повертає той самий список. ↑/↓ або J/K — сусідня позиція, Esc — закрити.
 * На телефоні картка — на весь екран, «Назад» — до списку з тією ж прокруткою.
 */
const session = useSession()
const route = useRoute()
const router = useRouter()
const { openId, open, close } = useSplit('warehouse-items')

type View = 'all' | 'bulk' | 'unit' | 'repair' | 'none' | 'off'
const VIEWS: { key: View; label: string }[] = [
    { key: 'all', label: 'Усі' },
    { key: 'bulk', label: 'Кількістю' },
    { key: 'unit', label: 'Поштучні' },
    { key: 'repair', label: 'У ремонті' },
    { key: 'none', label: 'Немає в наявності' },
    { key: 'off', label: 'Списані' },
]

const str = (v: unknown) => (typeof v === 'string' ? v : '')
const view = computed<View>(() => (VIEWS.some((v) => v.key === route.query.v) ? (route.query.v as View) : 'all'))
const category = computed(() => str(route.query.cat))
const place = computed(() => str(route.query.place))
const q = ref(str(route.query.q))

const meta = ref<WhMeta | null>(null)
const items = ref<WhItemRow[]>([])
const counts = ref({ all: 0, unit: 0, bulk: 0, repair: 0 })
const loading = ref(false)
const error = ref('')
const denied = ref('')
const listEl = ref<HTMLElement | null>(null)

function setQuery(patch: Record<string, string>) {
    const query: Record<string, string> = {}
    for (const [k, v] of Object.entries({ ...route.query, ...patch })) if (typeof v === 'string' && v !== '') query[k] = v
    router.replace({ name: 'warehouse-items', params: route.params, query })
}

let seq = 0
async function load() {
    const mine = ++seq
    loading.value = true
    try {
        const v = view.value
        const r = await whApi.items({
            q: q.value.trim() || undefined,
            category: category.value || undefined,
            place: v === 'none' ? 'none' : place.value || undefined,
            tracking: v === 'bulk' || v === 'unit' ? v : undefined,
            state: v === 'repair' ? 'repair' : v === 'off' ? 'written_off' : undefined,
        })
        if (mine !== seq) return
        items.value = r.items
        counts.value = r.counts
        error.value = ''
    } catch (e) {
        if (mine !== seq) return
        denied.value = accessError(e)
        if (!denied.value) error.value = session.handle(e)
    } finally {
        if (mine === seq) loading.value = false
    }
}

function step(delta: number) {
    const list = items.value
    if (!list.length) return
    const i = list.findIndex((t) => t.id === openId.value)
    const next = list[Math.min(list.length - 1, Math.max(0, i < 0 ? 0 : i + delta))]
    if (next && next.id !== openId.value) open(next.id)
}

function onKey(e: KeyboardEvent) {
    if (e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented || typing(e.target) || !openId.value) return
    const inList = !!(e.target as HTMLElement | null)?.closest('.w-left')
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
const href = (id: number) => router.resolve({ name: 'warehouse-items', params: { id: String(id) }, query: route.query }).href

/** Короткий підсумок «де є»: перші два місця й «+N». */
function placesLine(r: WhItemRow): string {
    if (!r.places.length) return r.tracking === 'unit' ? 'ще не надійшло або списано' : 'немає в наявності'
    const shown = r.places.slice(0, 2).map((p) => (r.tracking === 'unit' ? p.site : `${p.site} — ${qty(p.quantity, r.unit)}`))
    return shown.join(' · ') + (r.places.length > 2 ? ` · +${r.places.length - 2}` : '')
}

let debounce: number | undefined
let sentQ = q.value.trim()
watch(q, (value) => {
    window.clearTimeout(debounce)
    debounce = window.setTimeout(() => {
        sentQ = value.trim()
        setQuery({ q: sentQ })
    }, 300)
})
watch(() => [route.query.v, route.query.cat, route.query.place, route.query.q], () => {
    if (str(route.query.q) !== sentQ) {
        sentQ = str(route.query.q)
        q.value = sentQ
    }
    load()
})
watch(openId, async (now) => {
    if (!now) return
    await nextTick()
    listEl.value?.querySelector<HTMLElement>(`[data-item="${now}"]`)?.scrollIntoView({ block: 'nearest' })
})

/** Позицію змінили праворуч (рух, правка) — перечитуємо список: залишки змінились. */
const changed = () => load()

let timer: number | undefined
onMounted(async () => {
    load()
    try {
        meta.value = await whApi.meta()
    } catch (e) {
        denied.value = accessError(e)
    }
    timer = window.setInterval(() => !document.hidden && load(), 60000)
    window.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => {
    window.clearInterval(timer)
    window.clearTimeout(debounce)
    window.removeEventListener('keydown', onKey)
})
</script>

<template>
    <div v-if="denied" class="p-profile p-placeholder">
        <section class="p-card">
            <div class="p-placeholder-ico"><Icon name="warehouse" :size="28" /></div>
            <h2>Склад</h2>
            <p class="p-muted">{{ denied }}</p>
        </section>
    </div>
    <div v-else class="p-board p-supply w-board" :class="{ split: openId !== null }">
        <div class="p-board-left p-supply-left w-left">
            <WarehouseNav />
            <div class="p-board-bar">
                <label class="p-filter">
                    <Icon name="search" :size="16" />
                    <input v-model="q" data-board-filter type="search" placeholder="Назва, інв. №, марка, постачальник" aria-label="Пошук позицій" />
                </label>
                <router-link :to="{ name: 'warehouse-movement-new' }" class="p-btn primary p-new" title="Новий рух"><Icon name="plus" :size="18" :stroke="2.2" /><span>Рух</span></router-link>
                <router-link v-if="meta?.can.edit" :to="{ name: 'warehouse-item-new' }" class="p-btn p-new" title="Нова позиція"><Icon name="plus" :size="18" /><span>Позиція</span></router-link>
            </div>

            <div class="p-chips wrap" role="tablist" aria-label="Які позиції">
                <button
                    v-for="v in VIEWS"
                    :key="v.key"
                    type="button"
                    role="tab"
                    class="p-chip"
                    :class="{ on: view === v.key }"
                    :aria-selected="view === v.key"
                    @click="setQuery({ v: v.key === 'all' ? '' : v.key })"
                >
                    {{ v.label }}<template v-if="v.key === 'all'"> · {{ counts.all }}</template><template v-else-if="v.key === 'bulk'"> · {{ counts.bulk }}</template><template v-else-if="v.key === 'unit'"> · {{ counts.unit }}</template><template v-else-if="v.key === 'repair' && counts.repair"> · {{ counts.repair }}</template>
                </button>
            </div>
            <div class="p-supply-filters">
                <select :value="category" aria-label="Категорія" @change="setQuery({ cat: ($event.target as HTMLSelectElement).value })">
                    <option value="">Усі категорії</option>
                    <option v-for="c in meta?.categories ?? []" :key="c.id" :value="String(c.id)">{{ c.emoji }} {{ c.name }}</option>
                </select>
                <select :value="place" aria-label="Де" :disabled="view === 'none'" @change="setQuery({ place: ($event.target as HTMLSelectElement).value })">
                    <option value="">Усі місця</option>
                    <option v-for="s in meta?.sites ?? []" :key="s.id" :value="String(s.id)">{{ s.label }}{{ s.active ? '' : ' · закрите' }}</option>
                </select>
            </div>

            <div v-if="error" class="t-error">{{ error }}</div>

            <div ref="listEl" class="p-col on p-supply-list" :aria-busy="loading">
                <div class="p-col-body">
                    <a
                        v-for="r in items"
                        :key="r.id"
                        :href="href(r.id)"
                        class="p-card-task w-row"
                        :class="{ selected: r.id === openId, closed: r.state === 'written_off' }"
                        :data-item="r.id"
                        :aria-current="r.id === openId ? 'true' : undefined"
                        @click="click($event, r.id)"
                    >
                        <span class="w-row-main">
                            <span class="row top">
                                <span class="num">{{ r.inventoryNumber }}</span>
                                <span>{{ r.category.emoji }} {{ r.category.name }}</span>
                            </span>
                            <span class="title">{{ r.name }}</span>
                            <span class="client">{{ placesLine(r) }}</span>
                            <span class="row foot">
                                <span class="st" :class="'w-st-' + r.state">{{ r.stateLabel }}</span>
                                <span class="w-total" :class="{ zero: !r.total }">{{ r.tracking === 'unit' ? (r.total ? 'на місці' : '—') : qty(r.total, r.unit) }}</span>
                            </span>
                        </span>
                        <img v-if="r.cover" :src="r.cover" alt="" class="w-cover" loading="lazy" />
                    </a>
                    <div v-if="!loading && !items.length" class="p-empty">
                        {{ q || category || place || view !== 'all' ? 'За цим фільтром позицій немає.' : 'Позицій ще немає. «Позиція» — угорі.' }}
                    </div>
                    <template v-if="loading && !items.length">
                        <div v-for="i in 5" :key="i" class="p-skel" />
                    </template>
                </div>
            </div>
        </div>

        <div v-if="openId !== null" class="p-board-right">
            <ItemView :key="openId" :id="String(openId)" embedded @close="close" @changed="changed" />
        </div>
    </div>
</template>
