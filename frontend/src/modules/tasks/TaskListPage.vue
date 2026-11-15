<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import TaskCard from './TaskCard.vue'
import TaskView from './TaskView.vue'
import { TASK_SAVED } from './shell/nav'
import { useSplitTask } from './shell/split'
import { useTasks } from './store'
import { useSession } from '../../app/store'
import { useLiveRefresh } from '../../plan/live'
import { plural } from '../../plan/uk'
import { overviewApi, type ListFilter, type TaskList } from '../plan/overview'

/**
 * Список задач під фільтром — сюди ведуть числа «Огляду» (як у ПланФіксі: клік по
 * цифрі → ті самі задачі списком). Фільтр — в адресі (/tasks/list?show=overdue&dep=3; ?f= — відкритий файл),
 * тож «Назад» повертає, посиланням можна поділитися. Задача — праворуч, на телефоні
 * на весь екран.
 */
const session = useSession()
const store = useTasks()
const route = useRoute()
const router = useRouter()
const { openId, open, close } = useSplitTask('tasks-list')

const FILTERS: { key: ListFilter; label: string }[] = [
    { key: 'open', label: 'Відкриті' },
    { key: 'overdue', label: 'Прострочені' },
    { key: 'stale', label: 'Без руху' },
    { key: 'mine', label: 'Мої' },
]

const str = (v: unknown) => (typeof v === 'string' ? v : '')
const f = computed<ListFilter>(() => (FILTERS.some((x) => x.key === route.query.show) ? (route.query.show as ListFilter) : 'open'))
const dep = computed(() => str(route.query.dep))
const data = ref<TaskList | null>(null)
const error = ref('')
const loading = ref(false)

const label = (key: ListFilter, days = 3) => (key === 'stale' ? `Без руху ${days}+ дн.` : (FILTERS.find((x) => x.key === key)?.label ?? ''))
const title = computed(() => {
    const d = data.value
    const what = label(f.value, d?.filter.staleDays)
    return d?.filter.departmentName ? `${what} · ${d.filter.departmentName}` : what
})

function setQuery(patch: Record<string, string>) {
    const query: Record<string, string> = {}
    for (const [k, v] of Object.entries({ ...route.query, ...patch })) if (typeof v === 'string' && v !== '') query[k] = v
    router.replace({ name: 'tasks-list', params: route.params, query })
}

let seq = 0
async function load() {
    const mine = ++seq
    loading.value = true
    try {
        await store.load()
        const fresh = await overviewApi.list(f.value, dep.value)
        if (mine !== seq) return
        data.value = fresh
        error.value = ''
    } catch (e) {
        if (mine === seq) error.value = session.handle(e)
    } finally {
        if (mine === seq) loading.value = false
    }
}

watch(() => [route.query.show, route.query.dep], load)
useLiveRefresh(() => load(), { pollMs: 30000, match: (e) => e.subject.type === 'task', debounceMs: 800 })
onMounted(() => {
    load()
    window.addEventListener(TASK_SAVED, load)
})
onBeforeUnmount(() => window.removeEventListener(TASK_SAVED, load))
</script>

<template>
    <div class="p-split" :class="{ split: openId !== null }">
        <div class="p-split-left t-page p-tlist" :class="{ narrow: openId !== null }">
            <div class="p-tlist-head">
                <router-link :to="{ name: 'tasks' }" class="p-link">← Огляд</router-link>
                <h2 data-testid="tlist-title">
                    {{ title }}
                    <span v-if="data" class="count">{{ data.total }}</span>
                </h2>
            </div>
            <div class="p-chips wrap" role="tablist" aria-label="Фільтр">
                <button
                    v-for="x in FILTERS"
                    :key="x.key"
                    type="button"
                    role="tab"
                    class="p-chip"
                    :class="{ on: f === x.key }"
                    :aria-selected="f === x.key"
                    @click="setQuery({ show: x.key === 'open' ? '' : x.key })"
                >
                    {{ label(x.key, data?.filter.staleDays) }}
                </button>
                <button v-if="dep" type="button" class="p-chip on" title="Прибрати фільтр за відділом" @click="setQuery({ dep: '' })">
                    {{ data?.filter.departmentName ?? 'Відділ' }} ✕
                </button>
            </div>
            <p v-if="data && !data.filter.all" class="p-small p-muted">Лише задачі, де ви автор або учасник.</p>
            <div v-if="error" class="t-error">{{ error }}</div>

            <div class="p-col on p-tlist-col" :aria-busy="loading">
                <div class="p-col-body">
                    <template v-if="data">
                        <TaskCard v-for="t in data.items" :key="t.id" :task="t" show-status :selected="t.id === openId" @open="open($event)" />
                        <div v-if="!data.items.length" class="p-empty">За цим фільтром задач немає.</div>
                        <div v-else-if="data.total > data.items.length" class="p-empty">
                            Показано {{ data.items.length }} з {{ data.total }} — звузьте фільтр або скористайтесь пошуком.
                        </div>
                        <div v-else class="p-small p-muted p-tlist-foot">{{ data.total }} {{ plural(data.total, 'задача', 'задачі', 'задач') }}</div>
                    </template>
                    <template v-else>
                        <div v-for="i in 3" :key="i" class="p-skel" />
                    </template>
                </div>
            </div>
        </div>

        <div v-if="openId !== null" class="p-split-right p-board-right">
            <TaskView :id="String(openId)" :key="openId + ':' + (route.query.m ?? '') + ':' + (route.query.f ?? '')" embedded @close="close" />
        </div>
    </div>
</template>

<style>
.p-tlist-head {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.p-tlist-head h2 {
    margin: 0;
    font-size: 18px;
    display: flex;
    align-items: baseline;
    gap: 8px;
}
.p-tlist-head h2 .count {
    color: var(--text-muted);
    font-weight: 600;
    font-size: 15px;
}
.p-tlist-col {
    min-height: 0;
}
.p-tlist-foot {
    text-align: center;
    padding: 8px;
}
</style>
