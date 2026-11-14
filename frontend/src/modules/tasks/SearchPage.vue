<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Avatar from './Avatar.vue'
import { tasksApi, type Hit, type SearchResult, type SearchTab } from './api'
import { ago } from './format'
import { useSession } from '../../app/store'
import './feed.css'

/**
 * Пошук по всьому «Плану», як у ПланФіксі [S11]: одне поле, вкладки
 * Задачі / Коментарі / Файли / Люди з лічильниками, ★ — лише обране,
 * збіг «за частиною слова» виділено в сниппеті. Клавіатура: ↑/↓, Enter, Esc.
 */
const route = useRoute()
const router = useRouter()
const session = useSession()

const TABS: { key: SearchTab; title: string }[] = [
    { key: 'all', title: 'Усе' },
    { key: 'tasks', title: 'Задачі' },
    { key: 'comments', title: 'Коментарі' },
    { key: 'files', title: 'Файли' },
    { key: 'people', title: 'Люди' },
]
const GROUP_TITLE: Record<Exclude<SearchTab, 'all'>, string> = { tasks: 'Задачі', comments: 'Коментарі', files: 'Файли', people: 'Люди' }

const q = ref(String(route.query.q ?? ''))
const tab = ref<SearchTab>((TABS.some((t) => t.key === route.query.tab) ? route.query.tab : 'all') as SearchTab)
const starred = ref(route.query.starred === '1')
const result = ref<SearchResult | null>(null)
const loading = ref(false)
const error = ref('')
const sel = ref(0)
const input = ref<HTMLInputElement | null>(null)
const resultsEl = ref<HTMLElement | null>(null)

const groups = computed(() => (tab.value === 'all' ? (['tasks', 'comments', 'files', 'people'] as const) : [tab.value as Exclude<SearchTab, 'all'>]))
const total = computed(() => (result.value ? Object.values(result.value.counts).reduce((a, b) => a + b, 0) : 0))

const DATE = new Intl.DateTimeFormat('uk-UA', { day: '2-digit', month: '2-digit', year: '2-digit' })
function date(value: string): string {
    return DATE.format(new Date(value))
}

function plain(parts: Hit[]): string {
    return parts.map((p) => p.t).join('')
}

let seq = 0
async function run(append = false) {
    const query = q.value.trim()
    router.replace({ query: { ...(query ? { q: query } : {}), ...(tab.value !== 'all' ? { tab: tab.value } : {}), ...(starred.value ? { starred: '1' } : {}) } })
    if (query.length < 2) {
        result.value = null
        return
    }
    const my = ++seq
    loading.value = true
    try {
        const offset = append && result.value && tab.value !== 'all' ? (result.value[tab.value]?.length ?? 0) : 0
        const r = await tasksApi.search(query, tab.value, starred.value, offset)
        if (my !== seq) return
        if (append && result.value && tab.value !== 'all') {
            const key = tab.value
            ;(result.value[key] as unknown[]).push(...((r[key] as unknown[] | undefined) ?? []))
        } else {
            result.value = r
            sel.value = 0
        }
        error.value = ''
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        if (my === seq) loading.value = false
    }
}

let debounce: number | undefined
watch(q, () => {
    window.clearTimeout(debounce)
    debounce = window.setTimeout(() => run(), 250)
})
watch([tab, starred], () => run())

/** Порядок результатів для ↑/↓ — з даних, а не з DOM, щоб підсвітка була реактивною. */
const order = computed<string[]>(() => {
    const r = result.value
    if (!r) return []
    const out: string[] = []
    for (const g of groups.value) {
        if (g === 'tasks') r.tasks?.forEach((t) => out.push('task:' + t.id))
        if (g === 'comments') r.comments?.forEach((c) => out.push('msg:' + c.taskId + ':' + c.id))
        if (g === 'files') r.files?.forEach((f) => out.push('file:' + f.taskId + ':' + f.id))
    }
    return out
})

function hits(): HTMLElement[] {
    return Array.from(resultsEl.value?.querySelectorAll<HTMLElement>('.f-hit[data-go]') ?? [])
}

function go(el: HTMLElement) {
    const [kind, a, b] = (el.dataset.go ?? '').split(':')
    if (kind === 'task') router.push({ name: 'task', params: { id: a } })
    if (kind === 'msg') router.push({ name: 'task', params: { id: a }, query: { m: b } })
    if (kind === 'file') router.push({ name: 'task', params: { id: a }, query: { f: b } })
}

function onKey(e: KeyboardEvent) {
    const list = hits()
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!list.length) return
        e.preventDefault()
        sel.value = e.key === 'ArrowDown' ? Math.min(list.length - 1, sel.value + 1) : Math.max(0, sel.value - 1)
        nextTick(() => list[sel.value]?.scrollIntoView({ block: 'nearest' }))
    } else if (e.key === 'Enter') {
        if (list[sel.value]) {
            e.preventDefault()
            go(list[sel.value])
        }
    } else if (e.key === 'Escape') {
        q.value = ''
    }
}

function isSel(key: string): boolean {
    return order.value[sel.value] === key
}

onMounted(() => {
    input.value?.focus()
    if (q.value) run()
})
</script>

<template>
    <div class="t-page f-page">
        <div class="f-search-box">
            <input
                ref="input"
                v-model="q"
                class="t-input"
                type="search"
                placeholder="Пошук: задачі, коментарі, файли, люди"
                aria-label="Пошук"
                autocomplete="off"
                @keydown="onKey"
            />
            <button v-if="q" type="button" class="clear" aria-label="Очистити" @click="q = ''; input?.focus()">✕</button>
        </div>

        <div class="f-tabs" role="tablist">
            <button type="button" class="starbtn" :class="{ on: starred }" :aria-pressed="starred" title="Лише обране" @click="starred = !starred">{{ starred ? '★' : '☆' }}</button>
            <button v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key" :class="{ on: tab === t.key }" @click="tab = t.key">
                {{ t.title }}<span v-if="result && t.key !== 'all'" class="n">{{ result.counts[t.key as Exclude<SearchTab, 'all'>] }}</span><span v-else-if="result" class="n">{{ total }}</span>
            </button>
            <span v-if="loading" class="t-sub">…</span>
        </div>

        <div v-if="error" class="t-error">{{ error }}</div>
        <div v-if="!result" class="f-empty-hint">Введіть хоча б 2 символи — шукаємо за частиною слова в назвах задач, контрагентах, описах, коментарях і назвах файлів.</div>
        <div v-else-if="!total" class="f-empty-hint">Нічого не знайдено за «{{ result.q }}».</div>

        <div v-else ref="resultsEl" class="f-results">
            <template v-for="g in groups" :key="g">
                <template v-if="result.counts[g]">
                    <h2>
                        {{ GROUP_TITLE[g] }} <span>{{ result.counts[g] }}</span>
                        <button v-if="tab === 'all' && result.counts[g] > (result[g]?.length ?? 0)" type="button" @click="tab = g">Усі {{ result.counts[g] }} →</button>
                    </h2>

                    <template v-if="g === 'tasks'">
                        <a v-for="t in result.tasks" :key="'t' + t.id" class="f-hit" :class="{ sel: isSel('task:' + t.id) }" :data-go="'task:' + t.id" :href="'/crm/tasks/' + t.id" @click.prevent="go($event.currentTarget as HTMLElement)">
                            <div class="top"><span>№ {{ t.number }} · {{ t.statusLabel }}</span><span v-if="t.favorite">★</span><span class="date">{{ date(t.at) }}</span></div>
                            <div class="ttl" :class="{ closed: t.closed }"><template v-for="(p, i) in t.title" :key="i"><mark v-if="p.m">{{ p.t }}</mark><template v-else>{{ p.t }}</template></template></div>
                            <div v-if="t.snippet.length" class="snip"><template v-for="(p, i) in t.snippet" :key="i"><mark v-if="p.m">{{ p.t }}</mark><template v-else>{{ p.t }}</template></template></div>
                        </a>
                    </template>

                    <template v-else-if="g === 'comments'">
                        <a v-for="c in result.comments" :key="'c' + c.id" class="f-hit" :class="{ sel: isSel('msg:' + c.taskId + ':' + c.id) }" :data-go="'msg:' + c.taskId + ':' + c.id" :href="'/crm/tasks/' + c.taskId + '?m=' + c.id" @click.prevent="go($event.currentTarget as HTMLElement)">
                            <div class="top">
                                <Avatar :person="c.author" :size="18" /><span>{{ c.author?.name ?? 'Система' }}</span><span v-if="c.starred">★</span>
                                <span class="date">{{ ago(c.at) }}</span>
                            </div>
                            <div class="ttl" :class="{ closed: c.closed }">№ {{ c.taskNumber }} · {{ c.taskTitle }}</div>
                            <div class="snip"><template v-for="(p, i) in c.snippet" :key="i"><mark v-if="p.m">{{ p.t }}</mark><template v-else>{{ p.t }}</template></template></div>
                        </a>
                    </template>

                    <template v-else-if="g === 'files'">
                        <a v-for="f in result.files" :key="'f' + f.id" class="f-hit file" :class="{ sel: isSel('file:' + f.taskId + ':' + f.id) }" :data-go="'file:' + f.taskId + ':' + f.id" :href="'/crm/tasks/' + f.taskId + '?f=' + f.id" @click.prevent="go($event.currentTarget as HTMLElement)">
                            <span class="th"><img v-if="f.thumb" :src="f.thumb" :alt="f.plainName" loading="lazy" /><template v-else>{{ (f.plainName.split('.').pop() ?? '').toUpperCase().slice(0, 4) }}</template></span>
                            <span style="min-width: 0; display: flex; flex-direction: column; gap: 2px">
                                <span class="ttl" style="color: var(--text)"><template v-for="(p, i) in f.name" :key="i"><mark v-if="p.m">{{ p.t }}</mark><template v-else>{{ p.t }}</template></template></span>
                                <span class="snip" :class="{ closed: f.closed }">№ {{ f.taskNumber }} · {{ f.taskTitle }} · {{ f.sizeLabel }} · {{ date(f.at) }}</span>
                            </span>
                        </a>
                    </template>

                    <template v-else>
                        <div v-for="p in result.people" :key="'p' + p.id" class="f-hit person" :title="plain(p.nameMarked)">
                            <Avatar :person="p" :size="32" />
                            <span style="min-width: 0">
                                <span class="ttl" style="color: var(--text)"><template v-for="(h, i) in p.nameMarked" :key="i"><mark v-if="h.m">{{ h.t }}</mark><template v-else>{{ h.t }}</template></template></span>
                                <div class="snip">{{ p.position ?? p.roleLabel }}<template v-if="p.department"> · {{ p.department }}</template></div>
                            </span>
                        </div>
                    </template>
                </template>
            </template>
            <button v-if="tab !== 'all' && result.counts[tab] > (result[tab]?.length ?? 0)" type="button" class="t-btn small f-more-btn" :disabled="loading" @click="run(true)">Показати ще</button>
        </div>
    </div>
</template>
