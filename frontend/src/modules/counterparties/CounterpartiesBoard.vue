<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSession } from '../../app/store'
import Icon from '../tasks/shell/Icon.vue'
import CounterpartyView from './CounterpartyView.vue'
import { cpApi, hryvnia, type CpCounts, type CpMeta, type CpRow } from './api'
import { accessError } from './access'
import { useSplit } from '../../plan/useSplit'
import { typing } from '../../plan/layers'
import '../supply/supply.css'
import '../warehouse/warehouse.css'
import './counterparties.css'

/**
 * Контрагенти в «Плані»: щільний список ліворуч, картка праворуч (/counterparties/:id),
 * як задачі, заявки й склад. Фільтри — в адресі (?q=житлобуд&kind=client&vat=payer),
 * тож «Назад» повертає той самий список. ↑/↓ або J/K — сусідній, Esc — закрити.
 */
const session = useSession()
const route = useRoute()
const router = useRouter()
const { openId, open, close } = useSplit('counterparties')

type View = 'all' | 'client' | 'supplier' | 'archived'
const VIEWS: { key: View; label: string }[] = [
    { key: 'all', label: 'Усі' },
    { key: 'client', label: 'Клієнти' },
    { key: 'supplier', label: 'Постачальники' },
    { key: 'archived', label: 'Архів' },
]

const str = (v: unknown) => (typeof v === 'string' ? v : '')
const view = computed<View>(() =>
    route.query.archived === '1' ? 'archived' : route.query.kind === 'client' || route.query.kind === 'supplier' ? (route.query.kind as View) : 'all',
)
const q = ref(str(route.query.q))

const meta = ref<CpMeta | null>(null)
const items = ref<CpRow[]>([])
const counts = ref<CpCounts>({ all: 0, client: 0, supplier: 0, archived: 0 })
const limit = ref(200)
const loading = ref(false)
const error = ref('')
const denied = ref('')
const listEl = ref<HTMLElement | null>(null)

const filtered = computed(() => !!(q.value || route.query.form || route.query.vat || route.query.terms || route.query.manager || view.value !== 'all'))

function setQuery(patch: Record<string, string>) {
    const query: Record<string, string> = {}
    for (const [k, v] of Object.entries({ ...route.query, ...patch })) if (typeof v === 'string' && v !== '') query[k] = v
    router.replace({ name: 'counterparties', params: route.params, query })
}

function setView(v: View) {
    setQuery({ kind: v === 'client' || v === 'supplier' ? v : '', archived: v === 'archived' ? '1' : '' })
}

let seq = 0
async function load() {
    const mine = ++seq
    loading.value = true
    try {
        const r = await cpApi.list({
            q: q.value.trim() || undefined,
            kind: str(route.query.kind) || undefined,
            archived: route.query.archived === '1' ? '1' : undefined,
            form: str(route.query.form) || undefined,
            vat: str(route.query.vat) || undefined,
            terms: str(route.query.terms) || undefined,
            manager: str(route.query.manager) || undefined,
        })
        if (mine !== seq) return
        items.value = r.items
        counts.value = r.counts
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
const href = (id: number) => router.resolve({ name: 'counterparties', params: { id: String(id) }, query: route.query }).href

/** Другий рядок: код, контакт і телефон — те, по що зазвичай шукають. */
function subline(r: CpRow): string {
    const parts: string[] = []
    if (r.code) parts.push(`${r.codeLabel} ${r.code}`)
    if (r.contact) parts.push(r.contact.phone ? `${r.contact.name}, ${r.contact.phone}` : r.contact.name)
    else if (r.phone) parts.push(r.phone)
    return parts.join(' · ') || r.legalFormLabel
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
watch(
    () => [route.query.q, route.query.kind, route.query.archived, route.query.form, route.query.vat, route.query.terms, route.query.manager],
    () => {
        if (str(route.query.q) !== sentQ) {
            sentQ = str(route.query.q)
            q.value = sentQ
        }
        load()
    },
)
watch(openId, async (now) => {
    if (!now) return
    await nextTick()
    listEl.value?.querySelector<HTMLElement>(`[data-item="${now}"]`)?.scrollIntoView({ block: 'nearest' })
})

onMounted(async () => {
    load()
    try {
        meta.value = await cpApi.meta()
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
            <div class="p-placeholder-ico"><Icon name="building" :size="28" /></div>
            <h2>Контрагенти</h2>
            <p class="p-muted">{{ denied }}</p>
        </section>
    </div>
    <div v-else class="p-board p-supply w-board c-board" :class="{ split: openId !== null }">
        <div class="p-board-left p-supply-left w-left">
            <div class="p-board-bar">
                <label class="p-filter">
                    <Icon name="search" :size="16" />
                    <input v-model="q" data-board-filter type="search" placeholder="Назва, ЄДРПОУ, телефон, контакт" aria-label="Пошук контрагентів" />
                </label>
                <router-link v-if="meta?.can.edit" :to="{ name: 'counterparty-new' }" class="p-btn primary p-new" title="Новий контрагент ( N )">
                    <Icon name="plus" :size="18" :stroke="2.2" />
                    <span>Контрагент</span>
                </router-link>
            </div>

            <div class="p-chips wrap" role="tablist" aria-label="Які контрагенти">
                <button
                    v-for="v in VIEWS"
                    :key="v.key"
                    type="button"
                    role="tab"
                    class="p-chip"
                    :class="{ on: view === v.key }"
                    :aria-selected="view === v.key"
                    @click="setView(v.key)"
                >
                    {{ v.label }}
                    <template v-if="v.key !== 'archived' || counts.archived">· {{ counts[v.key] }}</template>
                </button>
            </div>
            <div class="p-supply-filters c-filters">
                <select :value="str(route.query.form)" aria-label="Форма" @change="setQuery({ form: ($event.target as HTMLSelectElement).value })">
                    <option value="">Усі форми</option>
                    <option v-for="f in meta?.legalForms ?? []" :key="f.value" :value="f.value">{{ f.label }}</option>
                </select>
                <select :value="str(route.query.vat)" aria-label="ПДВ" @change="setQuery({ vat: ($event.target as HTMLSelectElement).value })">
                    <option value="">ПДВ: усі</option>
                    <option v-for="v in meta?.vatStatuses ?? []" :key="v.value" :value="v.value">{{ v.label }}</option>
                </select>
                <select :value="str(route.query.terms)" aria-label="Умови оплати" @change="setQuery({ terms: ($event.target as HTMLSelectElement).value })">
                    <option value="">Будь-які умови оплати</option>
                    <option v-for="t in meta?.paymentTerms ?? []" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
                <select :value="str(route.query.manager)" aria-label="Менеджер" @change="setQuery({ manager: ($event.target as HTMLSelectElement).value })">
                    <option value="">Усі менеджери</option>
                    <option value="me">Мої</option>
                    <option value="none">Без менеджера</option>
                    <option v-for="m in meta?.managers ?? []" :key="m.id" :value="String(m.id)">{{ m.name }}</option>
                </select>
            </div>

            <div v-if="error" class="t-error">{{ error }}</div>

            <div ref="listEl" class="p-col on p-supply-list" :aria-busy="loading">
                <div class="p-col-body">
                    <a
                        v-for="r in items"
                        :key="r.id"
                        :href="href(r.id)"
                        class="p-card-task w-row c-row"
                        :class="{ selected: r.id === openId, closed: r.archived }"
                        :data-item="r.id"
                        :aria-current="r.id === openId ? 'true' : undefined"
                        @click="click($event, r.id)"
                    >
                        <span class="w-row-main">
                            <span class="row top">
                                <span class="c-kind" :class="'c-kind-' + r.kind">{{ r.kindLabel }}</span>
                                <span v-if="r.vatLabel" class="p-muted">{{ r.vatLabel }}</span>
                            </span>
                            <span class="title">{{ r.displayName }}</span>
                            <span class="client">{{ subline(r) }}</span>
                            <span class="row foot">
                                <span class="p-muted">{{ r.manager?.name ?? 'без менеджера' }}</span>
                                <span v-if="r.termsLabel" class="c-terms">
                                    {{ r.termsLabel }}
                                    <template v-if="r.creditLimit">· ліміт {{ hryvnia(r.creditLimit) }}</template>
                                </span>
                            </span>
                        </span>
                    </a>
                    <div v-if="!loading && !items.length" class="p-empty">
                        {{ filtered ? 'За цим фільтром контрагентів немає.' : 'Контрагентів ще немає. «Контрагент» — угорі.' }}
                    </div>
                    <div v-if="items.length >= limit" class="p-empty">Показано перші {{ limit }} — уточніть пошук.</div>
                    <template v-if="loading && !items.length">
                        <div v-for="i in 5" :key="i" class="p-skel" />
                    </template>
                </div>
            </div>
        </div>

        <div v-if="openId !== null" class="p-board-right">
            <CounterpartyView :id="String(openId)" :key="openId" embedded @close="close" @changed="load" />
        </div>
    </div>
</template>
