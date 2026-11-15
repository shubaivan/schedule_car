<script setup lang="ts">
import PersonName from '../../plan/PersonName.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSession } from '../../app/store'
import Icon from '../tasks/shell/Icon.vue'
import MovementView from './MovementView.vue'
import WarehouseNav from './WarehouseNav.vue'
import { whApi, type WhMeta, type WhMovementRow } from './api'
import { accessError } from './access'
import { formatDate } from '../../shared/format'
import { useSplit } from '../../plan/useSplit'
import { typing } from '../../plan/layers'
import '../supply/supply.css'
import './warehouse.css'

/**
 * Рухи складу: список ліворуч, рух праворуч (/sklad/movements/:id).
 * Фільтри в адресі: ?t=shipment&site=4&item=7&q=ВН-1.
 */
const session = useSession()
const route = useRoute()
const router = useRouter()
const { openId, open, close } = useSplit('warehouse-movements')

const str = (v: unknown) => (typeof v === 'string' ? v : '')
const type = computed(() => str(route.query.t))
const site = computed(() => str(route.query.site))
const item = computed(() => str(route.query.item))
const q = ref(str(route.query.q))

const meta = ref<WhMeta | null>(null)
const items = ref<WhMovementRow[]>([])
const limit = ref(200)
const loading = ref(false)
const error = ref('')
const denied = ref('')
const listEl = ref<HTMLElement | null>(null)

function setQuery(patch: Record<string, string>) {
    const query: Record<string, string> = {}
    for (const [k, v] of Object.entries({ ...route.query, ...patch })) if (typeof v === 'string' && v !== '') query[k] = v
    router.replace({ name: 'warehouse-movements', params: route.params, query })
}

let seq = 0
async function load() {
    const mine = ++seq
    loading.value = true
    try {
        const r = await whApi.movements({ type: type.value || undefined, site: site.value || undefined, item: item.value || undefined, q: q.value.trim() || undefined })
        if (mine !== seq) return
        items.value = r.items
        limit.value = r.limit
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
const href = (id: number) => router.resolve({ name: 'warehouse-movements', params: { id: String(id) }, query: route.query }).href

let debounce: number | undefined
let sentQ = q.value.trim()
watch(q, (value) => {
    window.clearTimeout(debounce)
    debounce = window.setTimeout(() => {
        sentQ = value.trim()
        setQuery({ q: sentQ })
    }, 300)
})
watch(() => [route.query.t, route.query.site, route.query.item, route.query.q], () => {
    if (str(route.query.q) !== sentQ) {
        sentQ = str(route.query.q)
        q.value = sentQ
    }
    load()
})
watch(openId, async (now) => {
    if (!now) return
    await nextTick()
    listEl.value?.querySelector<HTMLElement>(`[data-movement="${now}"]`)?.scrollIntoView({ block: 'nearest' })
})

onMounted(async () => {
    load()
    try {
        meta.value = await whApi.meta()
    } catch (e) {
        denied.value = accessError(e)
    }
    window.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => {
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
                    <input v-model="q" data-board-filter type="search" placeholder="№ накладної, місце, постачальник" aria-label="Пошук рухів" />
                </label>
                <router-link :to="{ name: 'warehouse-movement-new', query: type ? { type } : {} }" class="p-btn primary p-new"><Icon name="plus" :size="18" :stroke="2.2" /><span>Новий рух</span></router-link>
            </div>
            <div class="p-chips wrap" role="tablist" aria-label="Тип руху">
                <button type="button" role="tab" class="p-chip" :class="{ on: !type }" :aria-selected="!type" @click="setQuery({ t: '' })">Усі</button>
                <button v-for="t in meta?.movementTypes ?? []" :key="t.value" type="button" role="tab" class="p-chip" :class="{ on: type === t.value }" :aria-selected="type === t.value" @click="setQuery({ t: t.value })">{{ t.emoji }} {{ t.label }}</button>
            </div>
            <div class="p-supply-filters">
                <select :value="site" aria-label="Місце" @change="setQuery({ site: ($event.target as HTMLSelectElement).value })">
                    <option value="">Усі місця</option>
                    <option v-for="s in meta?.sites ?? []" :key="s.id" :value="String(s.id)">{{ s.label }}</option>
                </select>
                <button v-if="item" type="button" class="p-chip on" title="Прибрати фільтр за позицією" @click="setQuery({ item: '' })">Одна позиція ✕</button>
            </div>

            <div v-if="error" class="t-error">{{ error }}</div>

            <div ref="listEl" class="p-col on p-supply-list" :aria-busy="loading">
                <div class="p-col-body">
                    <a
                        v-for="m in items"
                        :key="m.id"
                        :href="href(m.id)"
                        class="p-card-task w-row"
                        :class="{ selected: m.id === openId }"
                        :data-movement="m.id"
                        :aria-current="m.id === openId ? 'true' : undefined"
                        @click="click($event, m.id)"
                    >
                        <span class="w-row-main">
                            <span class="row top">
                                <span class="num">{{ m.emoji }} {{ m.typeLabel }}<template v-if="m.documentNumber"> № {{ m.documentNumber }}</template></span>
                                <span class="when">{{ formatDate(m.occurredAt) }}</span>
                            </span>
                            <span class="title">{{ m.route }}</span>
                            <span class="client">{{ m.client ?? m.supplier ?? '' }}<template v-if="m.createdBy"> · <PersonName :person="m.createdBy" :link="false" /></template></span>
                            <span class="row foot"><span class="w-total">{{ m.lines }} поз. · {{ m.units.toLocaleString('uk-UA') }} од.</span></span>
                        </span>
                    </a>
                    <div v-if="!loading && !items.length" class="p-empty">За цим фільтром рухів немає.</div>
                    <div v-if="items.length >= limit" class="p-empty">Показано останні {{ limit }} — звузьте фільтр.</div>
                    <template v-if="loading && !items.length">
                        <div v-for="i in 5" :key="i" class="p-skel" />
                    </template>
                </div>
            </div>
        </div>

        <div v-if="openId !== null" class="p-board-right">
            <MovementView :id="String(openId)" :key="openId" embedded @close="close" />
        </div>
    </div>
</template>
