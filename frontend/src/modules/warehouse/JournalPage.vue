<script setup lang="ts">
import PersonName from '../../plan/PersonName.vue'
import { usePhoneBook, withPhone } from '../../plan/people'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSession } from '../../app/store'
import Icon from '../tasks/shell/Icon.vue'
import WarehouseNav from './WarehouseNav.vue'
import { whApi, type WhActivity, type WhMeta } from './api'
import type { Person } from '../tasks/api'
import { accessError } from './access'
import { formatDateTime } from '../../shared/format'
import '../supply/supply.css'
import './warehouse.css'

/** Журнал складу — хто що сканував, відкривав і міняв. Той самий wh_activity, що й /sklad/journal. */
const phones = usePhoneBook()
const session = useSession()
const route = useRoute()
const router = useRouter()

const str = (v: unknown) => (typeof v === 'string' ? v : '')
const filters = computed(() => ({
    user: str(route.query.user),
    action: str(route.query.action),
    type: str(route.query.type),
    from: str(route.query.from),
    to: str(route.query.to),
}))
const meta = ref<WhMeta | null>(null)
const entries = ref<WhActivity[]>([])
const people = ref<Person[]>([])
const loading = ref(false)
const error = ref('')
const denied = ref('')

function setQuery(patch: Record<string, string>) {
    const query: Record<string, string> = {}
    for (const [k, v] of Object.entries({ ...route.query, ...patch })) if (typeof v === 'string' && v !== '') query[k] = v
    router.replace({ name: 'warehouse-journal', query })
}

/** Куди веде рядок: позиція чи рух — у «Плані», решта (клієнт, об'єкт, категорія) — нікуди. */
function link(e: WhActivity) {
    if (e.subjectType === 'item') return { name: 'warehouse-items', params: { id: String(e.subjectId) } }
    if (e.subjectType === 'movement') return { name: 'warehouse-movements', params: { id: String(e.subjectId) } }
    return null
}

let seq = 0
async function load() {
    const mine = ++seq
    loading.value = true
    try {
        const r = await whApi.journal(filters.value)
        if (mine !== seq) return
        entries.value = r.items
        people.value = r.people
        error.value = ''
    } catch (e) {
        if (mine !== seq) return
        denied.value = accessError(e)
        if (!denied.value) error.value = session.handle(e)
    } finally {
        if (mine === seq) loading.value = false
    }
}

watch(filters, load)
onMounted(async () => {
    load()
    meta.value = await whApi.meta().catch(() => null)
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
    <div v-else class="w-journal">
        <WarehouseNav />
        <div class="p-supply-filters">
            <select :value="filters.user" aria-label="Хто" @change="setQuery({ user: ($event.target as HTMLSelectElement).value })">
                <option value="">Усі люди</option>
                <option v-for="p in people" :key="p.id" :value="String(p.id)">{{ withPhone(p.name, phones.lookup(p.id, p.name)?.phone) }}</option>
            </select>
            <select :value="filters.action" aria-label="Дія" @change="setQuery({ action: ($event.target as HTMLSelectElement).value })">
                <option value="">Усі дії</option>
                <option v-for="a in meta?.actions ?? []" :key="a.value" :value="a.value">{{ a.emoji }} {{ a.label }}</option>
            </select>
            <select :value="filters.type" aria-label="Що" @change="setQuery({ type: ($event.target as HTMLSelectElement).value })">
                <option value="">Усе</option>
                <option v-for="(label, key) in meta?.subjectTypes ?? {}" :key="key" :value="key">{{ label }}</option>
            </select>
            <input type="date" :value="filters.from" aria-label="З дати" @change="setQuery({ from: ($event.target as HTMLInputElement).value })" />
            <input type="date" :value="filters.to" aria-label="По дату" @change="setQuery({ to: ($event.target as HTMLInputElement).value })" />
        </div>
        <div v-if="error" class="t-error">{{ error }}</div>
        <div class="t-panel w-journal-list" :aria-busy="loading">
            <div v-for="e in entries" :key="e.id" class="w-journal-row">
                <span>
                    {{ e.emoji }}
                    <PersonName v-if="e.user" :person="e.user" bold />
                    <b v-else>Система</b>
                    {{ e.actionLabel.toLowerCase() }} ·
                    <router-link v-if="link(e)" :to="link(e)!">{{ e.subjectLabel }}</router-link>
                    <template v-else>{{ e.subjectLabel }}</template>
                    <template v-if="e.details">
                        ·
                        <span class="p-muted">{{ e.details }}</span>
                    </template>
                </span>
                <small class="p-muted">{{ formatDateTime(e.at) }} · {{ e.channelLabel }}</small>
            </div>
            <div v-if="!loading && !entries.length" class="p-empty">За цим фільтром записів немає.</div>
        </div>
    </div>
</template>
