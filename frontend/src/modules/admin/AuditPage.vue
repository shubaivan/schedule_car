<script setup lang="ts">
import { plural, sortByName } from '../../plan/uk'
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter, type LocationQuery } from 'vue-router'
import { useSession } from '../../app/store'
import { canSeeAdmin, canSeeJoinRequests } from './access'
import { auditApi, FILTER_KEYS, type AuditFilters, type AuditItem, type AuditMeta } from './api'
import AuditEventView from './AuditEventView.vue'
import { auditDay, auditStamp, CHANNELS, COMMENT_TYPES, GROUP_TONE } from './format'
import './audit.css'

/**
 * Журнал аудиту суперадміна — щільний список усіх подій «Плану» (задачі, заявки,
 * склад, входи, скачування) з фільтрами й сторінками.
 *
 * Без модалок: фільтри й сторінка — у ?query (Back повертає той самий зріз,
 * посилання можна переслати), подія — окремий маршрут /admin/audit/:id
 * (десктоп — праворуч від списку, телефон — на весь екран).
 */
const session = useSession()
const route = useRoute()
const router = useRouter()

const allowed = computed(() => canSeeAdmin(session.user))

const meta = ref<AuditMeta | null>(null)
const items = ref<AuditItem[]>([])
const older = ref<number | null>(null)
const newer = ref<number | null>(null)
const total = ref(0)
const totalCapped = ref(false)
const loading = ref(false)
const error = ref('')
const filtersOpen = ref(false)
const list = ref<HTMLElement | null>(null)

const openId = computed(() => (route.params.id ? Number(route.params.id) : null))

/** Телефон: одна колонка — подія на весь екран, список схований під нею. */
const mq = window.matchMedia('(max-width: 760px)')
const mobile = ref(mq.matches)
const onMq = (e: MediaQueryListEvent) => (mobile.value = e.matches)

function str(v: unknown): string {
    return typeof v === 'string' ? v : ''
}

/** Фільтри з адреси — єдине джерело правди. */
const filters = computed<AuditFilters>(() => {
    const out: AuditFilters = {}
    for (const key of FILTER_KEYS) {
        const value = str(route.query[key])
        if (value) out[key] = value
    }
    return out
})
const activeCount = computed(() => Object.keys(filters.value).length)
const groups = computed(() => new Set((filters.value.group ?? '').split(',').filter(Boolean)))

/** Чернетка полів, які набирають (текст, IP, суб'єкт): у адресу — з паузою або по Enter. */
const draft = reactive({ q: '', ip: '', subject: '' })
watch(filters, (f) => {
    draft.q = f.q ?? ''
    draft.ip = f.ip ?? ''
    draft.subject = f.subject ?? ''
}, { immediate: true })

function listQuery(query: LocationQuery): Record<string, string> {
    const out: Record<string, string> = {}
    for (const [k, v] of Object.entries(query)) if (typeof v === 'string' && v !== '') out[k] = v
    return out
}

/** Зміна фільтра — з першої сторінки; подію праворуч закриваємо (вона могла випасти зі зрізу). */
function setFilter(patch: AuditFilters) {
    const query: Record<string, string> = {}
    for (const key of FILTER_KEYS) {
        const value = key in patch ? patch[key] : filters.value[key]
        if (value) query[key] = value
    }
    router.replace({ name: 'admin-audit', query })
}

let typingTimer: number | undefined
function typed(key: 'q' | 'ip' | 'subject', now = false) {
    window.clearTimeout(typingTimer)
    const apply = () => {
        const value = draft[key].trim()
        if ((filters.value[key] ?? '') !== value) setFilter({ [key]: value })
    }
    if (now) apply()
    else typingTimer = window.setTimeout(apply, 400)
}

function toggleGroup(value: string) {
    const next = new Set(groups.value)
    if (next.has(value)) next.delete(value)
    else next.add(value)
    setFilter({ group: [...next].join(',') })
}

function resetFilters() {
    router.replace({ name: 'admin-audit' })
}

function page(cursor: { before?: number; after?: number }) {
    const query = { ...listQuery(route.query) }
    delete query.before
    delete query.after
    if (cursor.before) query.before = String(cursor.before)
    if (cursor.after) query.after = String(cursor.after)
    router.push({ name: 'admin-audit', query })
}

function open(item: AuditItem) {
    const to = { name: 'admin-audit', params: { id: String(item.id) }, query: listQuery(route.query) }
    // Телефон: «Назад» повертає до списку. Десктоп: гортаємо події, не засмічуючи історію.
    if (mobile.value || openId.value === null) router.push(to)
    else router.replace(to)
}

function close() {
    const back = (window.history.state as { back?: string } | null)?.back
    if (back && back.split('?')[0] === router.resolve({ name: 'admin-audit' }).path) router.back()
    else router.replace({ name: 'admin-audit', query: listQuery(route.query) })
}

/** З картки події: «усі події цього суб'єкта / цієї людини». */
function applyFromDetail(patch: AuditFilters) {
    const query: Record<string, string> = {}
    for (const [k, v] of Object.entries(patch)) if (v) query[k] = v
    router.push({ name: 'admin-audit', query })
}

let seq = 0
async function load() {
    if (!allowed.value) return
    const mine = ++seq
    loading.value = true
    try {
        const cursor = { before: str(route.query.before) || undefined, after: str(route.query.after) || undefined }
        const result = await auditApi.page(filters.value, cursor)
        if (mine !== seq) return
        items.value = result.items
        older.value = result.older
        newer.value = result.newer
        total.value = result.total
        totalCapped.value = result.totalCapped
        error.value = ''
    } catch (e) {
        if (mine === seq) error.value = session.handle(e)
    } finally {
        if (mine === seq) loading.value = false
    }
}

const listKey = computed(() => JSON.stringify([filters.value, route.query.before ?? '', route.query.after ?? '']))
watch(listKey, async (now, before) => {
    if (now === before) return
    await load()
    await nextTick()
    list.value?.scrollTo?.({ top: 0 })
})

watch(allowed, (ok) => {
    if (ok && !meta.value) init()
})

async function init() {
    if (!allowed.value) return
    load()
    try {
        const m = await auditApi.meta()
        meta.value = { ...m, actors: sortByName(m.actors) }
    } catch (e) {
        error.value = session.handle(e)
    }
}

/** Рядки згруповано за днем — як Хроніка, щоб довгий журнал читався. */
const days = computed(() => {
    const out: { day: string; label: string; items: AuditItem[] }[] = []
    for (const item of items.value) {
        const day = auditDay(item.at)
        const last = out[out.length - 1]
        if (last && last.day === day) last.items.push(item)
        else out.push({ day, label: dayLabel(day), items: [item] })
    }
    return out
})

function dayLabel(day: string): string {
    const [y, m, d] = day.split('-').map(Number)
    const date = new Date(y, m - 1, d)
    const today = new Date()
    const diff = Math.round((new Date(today.getFullYear(), today.getMonth(), today.getDate()).getTime() - date.getTime()) / 86400000)
    if (diff === 0) return 'Сьогодні'
    if (diff === 1) return 'Вчора'
    return new Intl.DateTimeFormat('uk-UA', { weekday: 'short', day: 'numeric', month: 'long', year: date.getFullYear() === today.getFullYear() ? undefined : 'numeric' }).format(date)
}

const totalLabel = computed(() => (totalCapped.value ? `понад ${total.value.toLocaleString('uk-UA')}` : total.value.toLocaleString('uk-UA')))
const csvHref = computed(() => auditApi.csvUrl(filters.value))
const actorName = computed(() => meta.value?.actors.find((a) => String(a.id) === filters.value.actor)?.name)

/** ↑/↓ між рядками, Enter — відкрити. */
function onKey(e: KeyboardEvent) {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return
    const nodes = Array.from(list.value?.querySelectorAll<HTMLElement>('.a-row') ?? [])
    const i = nodes.indexOf(document.activeElement as HTMLElement)
    const target = nodes[e.key === 'ArrowDown' ? Math.min(nodes.length - 1, i + 1) : Math.max(0, i - 1)]
    if (target) {
        e.preventDefault()
        target.focus()
    }
}

function onEsc(e: KeyboardEvent) {
    if (e.key !== 'Escape' || openId.value === null) return
    const el = e.target as HTMLElement | null
    if (el && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) return
    e.preventDefault()
    close()
}

onMounted(() => {
    mq.addEventListener('change', onMq)
    window.addEventListener('keydown', onEsc)
    if (session.ready) init()
})
onBeforeUnmount(() => {
    mq.removeEventListener('change', onMq)
    window.removeEventListener('keydown', onEsc)
    window.clearTimeout(typingTimer)
})
</script>

<template>
    <div v-if="!allowed" class="t-page a-denied">
        <h1>Журнал аудиту</h1>
        <p class="muted">Цей розділ бачить лише суперадмін.</p>
    </div>

    <div v-else class="p-split a-audit" :class="{ split: openId !== null }">
        <div class="p-split-left t-page a-list-pane">
            <div class="a-head">
                <h1>Журнал аудиту</h1>
                <span class="t-sub" aria-live="polite">{{ loading && !items.length ? 'Завантаження…' : totalLabel + ' ' + plural(total, 'подія', 'події', 'подій') }}</span>
                <span class="sp" />
                <button type="button" class="t-btn small ghost a-filters-btn" :aria-expanded="filtersOpen" @click="filtersOpen = !filtersOpen">
                    Фільтри<b v-if="activeCount" class="a-count">{{ activeCount }}</b>
                </button>
                <router-link v-if="canSeeJoinRequests(session.user)" class="t-btn small ghost" :to="{ name: 'admin-join' }" title="Хто просить доступ через бота">Заявки на приєднання</router-link>
                <router-link class="t-btn small ghost" :to="{ name: 'drive-status' }" title="Стан копіювання файлів на Google Диск">Google Диск</router-link>
                <a class="t-btn small ghost" :href="csvHref" download :title="'CSV, до ' + (meta?.csvMax ?? 50000).toLocaleString('uk-UA') + ' рядків за поточними фільтрами'">Експорт CSV</a>
            </div>

            <form class="a-filters" :class="{ open: filtersOpen }" role="search" @submit.prevent="typed('q', true)">
                <label class="a-field a-wide">
                    <span>Текст</span>
                    <input v-model="draft.q" type="search" placeholder="Назва, коментар, файл, логін…" @input="typed('q')" @keydown.enter.prevent="typed('q', true)" />
                </label>
                <label class="a-field">
                    <span>Хто</span>
                    <select :value="filters.actor ?? ''" @change="setFilter({ actor: ($event.target as HTMLSelectElement).value })">
                        <option value="">Усі</option>
                        <option v-for="a in meta?.actors ?? []" :key="a.id" :value="String(a.id)">{{ a.name }}</option>
                        <option v-if="filters.actor && !actorName" :value="filters.actor">#{{ filters.actor }}</option>
                    </select>
                </label>
                <label class="a-field">
                    <span>Що</span>
                    <select :value="filters.kind ?? ''" @change="setFilter({ kind: ($event.target as HTMLSelectElement).value })">
                        <option value="">Усе</option>
                        <option v-for="s in meta?.subjects ?? []" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </label>
                <label class="a-field">
                    <span>Суб'єкт</span>
                    <input v-model="draft.subject" placeholder="task-12" spellcheck="false" autocapitalize="off" @input="typed('subject')" @keydown.enter.prevent="typed('subject', true)" />
                </label>
                <label class="a-field">
                    <span>З</span>
                    <input type="date" :value="filters.from ?? ''" @change="setFilter({ from: ($event.target as HTMLInputElement).value })" />
                </label>
                <label class="a-field">
                    <span>По</span>
                    <input type="date" :value="filters.to ?? ''" @change="setFilter({ to: ($event.target as HTMLInputElement).value })" />
                </label>
                <label class="a-field">
                    <span>IP</span>
                    <input v-model="draft.ip" placeholder="10.0." inputmode="decimal" spellcheck="false" @input="typed('ip')" @keydown.enter.prevent="typed('ip', true)" />
                </label>
                <button v-if="activeCount" type="button" class="p-link a-reset" @click="resetFilters">Скинути все</button>
            </form>

            <div class="a-chips" role="group" aria-label="Тип події">
                <button
                    v-for="g in meta?.groups ?? []"
                    :key="g.value"
                    type="button"
                    class="a-chip"
                    :class="['tone-' + (GROUP_TONE[g.value] ?? 'slate'), { on: groups.has(g.value) }]"
                    :aria-pressed="groups.has(g.value)"
                    :title="g.types.map((t) => t.label).join(', ')"
                    @click="toggleGroup(g.value)"
                >{{ g.label }}</button>
            </div>

            <div v-if="error" class="t-error">{{ error }}</div>

            <div ref="list" class="a-list" :class="{ busy: loading }" @keydown="onKey">
                <div class="a-row a-row-head" aria-hidden="true">
                    <span>Час</span><span>Хто</span><span>Подія</span><span>Що</span><span>Деталі</span><span>Звідки</span>
                </div>
                <template v-for="d in days" :key="d.day">
                    <div class="a-day">{{ d.label }}</div>
                    <div
                        v-for="item in d.items"
                        :key="item.id"
                        class="a-row"
                        :class="{ sel: item.id === openId }"
                        role="link"
                        tabindex="0"
                        :aria-label="`${item.typeLabel}: ${item.subject.title ?? item.subject.key}`"
                        @click="open(item)"
                        @keydown.enter="open(item)"
                    >
                        <span class="a-at">{{ auditStamp(item.at).slice(-5) }}</span>
                        <span class="a-who">{{ item.actor?.name ?? (item.channel === 'cli' ? 'Система' : '—') }}</span>
                        <span class="a-type"><i class="a-badge" :class="'tone-' + (GROUP_TONE[item.group ?? ''] ?? 'slate')">{{ item.typeLabel }}</i></span>
                        <span class="a-subj">
                            <small>{{ item.subject.kindLabel }}</small>
                            {{ item.subject.title ?? item.subject.key }}
                        </span>
                        <span class="a-sum">
                            <!-- Коментар — однаково, чи він окрема подія (історія), чи йде разом зі статусом (живий журнал). -->
                            <span v-if="COMMENT_TYPES.includes(item.type)" class="a-comment">💬 {{ item.summary }}</span>
                            <template v-else>
                                {{ item.summary }}
                                <span v-if="item.comment" class="a-comment">💬 {{ item.comment }}</span>
                            </template>
                        </span>
                        <span class="a-from">
                            {{ CHANNELS[item.channel] ?? item.channel }}<template v-if="item.ip"> · {{ item.ip }}</template>
                            <i v-if="item.backfill" class="a-hist" title="Перенесено з історії (plan:events:backfill)">іст.</i>
                        </span>
                    </div>
                </template>
                <div v-if="!items.length && !loading" class="t-empty">
                    {{ activeCount ? 'Під ці фільтри подій немає.' : 'Журнал порожній.' }}
                </div>
            </div>

            <nav v-if="newer || older" class="a-pager" aria-label="Сторінки журналу">
                <button type="button" class="t-btn small ghost" :disabled="!newer || loading" @click="newer && page({ after: newer })">← Новіші</button>
                <button type="button" class="t-btn small ghost" :disabled="!older || loading" @click="older && page({ before: older })">Старіші →</button>
            </nav>

            <p v-if="meta" class="a-note">
                Входи, перегляди, скачування й поширення зберігаються {{ meta.retention.days }} дн.; дії із задачами, заявками й складом — без обмеження.
                Перегляд картки й скачування того самого файлу однією людиною — не частіше одного рядка за 10 хв.
            </p>
        </div>

        <div v-if="openId !== null" class="p-split-right a-detail-pane">
            <AuditEventView :key="openId" :id="openId" @close="close" @filter="applyFromDetail" />
        </div>
    </div>
</template>
