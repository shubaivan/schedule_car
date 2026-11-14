<script setup lang="ts">
import { quantity } from '../../plan/uk'
import { computed, onMounted, ref } from 'vue'
import TaskRow from './TaskRow.vue'
import { tasksApi, type Dashboard } from './api'
import { dueLabel, plural, today } from './format'
import { useTasks } from './store'
import { useSession } from '../../app/store'
import { useLiveRefresh } from '../../plan/live'
import { tone } from '../supply/status'

/**
 * «Огляд» (Мій день): що чекає мого рішення (задачі й заявки), що горить, що на мені,
 * що в моєму відділі; дві смуги — постачання й склад. Кожна задача — лише в одному
 * блоці (сервер прибирає повтори), на телефоні блоки коротші.
 */
const session = useSession()
const store = useTasks()
const data = ref<Dashboard | null>(null)
const error = ref('')

async function load() {
    try {
        await store.load()
        data.value = await tasksApi.dashboard()
    } catch (e) {
        error.value = session.handle(e)
    }
}

const hello = () => {
    const h = new Date().getHours()
    return h < 12 ? 'Доброго ранку' : h < 18 ? 'Добрий день' : 'Добрий вечір'
}

const decide = computed(() => !!store.meta?.me.can.decide || !!data.value?.supply.approve)
/** Скільки чекає рішення: задачі (для того, хто вирішує) + заявки (для того, хто погоджує). */
const decisionsTotal = computed(() => {
    const d = data.value
    if (!d) return 0
    return (store.meta?.me.can.decide ? d.stats.decisions : 0) + (d.supply.approve ? d.supply.counts.decide : 0)
})
const newsTitle = computed(() => {
    const s = data.value?.stats
    if (!s) return ''
    return s.unread
        ? `${s.unread} ${plural(s.unread, 'запис', 'записи', 'записів')} у Хроніці з новим (${s.unreadEvents} ${plural(s.unreadEvents, 'подія', 'події', 'подій')}) — те саме число, що біля «Хроніки»`
        : 'У Хроніці все прочитано'
})

onMounted(() => load())
// «Огляд» — задачі, заявки, склад: з Mercure за подією (+ раз на хвилину), інакше кожні 30 с.
useLiveRefresh(() => load(), { pollMs: 30000, debounceMs: 1000 })
</script>

<template>
    <div class="t-page p-home">
        <div class="t-head">
            <div>
                <h1>{{ hello() }}, {{ store.meta?.me.name.split(' ')[0] ?? session.user?.firstName }}</h1>
                <div class="t-sub">{{ today() }} · {{ store.meta?.me.position ?? store.meta?.me.roleLabel }}<template v-if="store.meta?.me.department"> · {{ store.meta?.me.department }}</template></div>
            </div>
            <router-link :to="{ name: 'tasks-new' }" class="t-btn primary">＋ Нова задача</router-link>
        </div>

        <div v-if="error" class="t-error">{{ error }}</div>
        <div v-if="!data" class="t-empty">Завантаження…</div>

        <template v-else>
            <div class="t-stats">
                <router-link :to="{ name: 'tasks-board' }" class="t-stat" title="Відкриті задачі, де ви автор чи учасник"><span class="v">{{ data.stats.mine }}</span><span class="k">Мої відкриті</span></router-link>
                <router-link :to="{ name: 'tasks-board' }" class="t-stat" :class="{ alert: data.stats.overdue }" title="Відкриті задачі з терміном у минулому"><span class="v">{{ data.stats.overdue }}</span><span class="k">Прострочені</span></router-link>
                <router-link :to="{ name: 'tasks-chronicle', query: { unread: '1' } }" class="t-stat" :class="{ alert: data.stats.unread }" :title="newsTitle" data-testid="home-news"><span class="v">{{ data.stats.unread }}</span><span class="k">З новим у Хроніці</span></router-link>
                <a v-if="decide" href="#decide" class="t-stat" :class="{ warn: decisionsTotal }" title="Задачі й заявки, що чекають вашого рішення"><span class="v">{{ decisionsTotal }}</span><span class="k">Чекають рішення</span></a>
                <router-link v-else :to="{ name: 'tasks-board' }" class="t-stat"><span class="v">{{ data.stats.open }}</span><span class="k">Відкриті · усього</span></router-link>
            </div>

            <section v-if="data.decisions.length || data.supply.decisions.length" id="decide" class="t-panel t-decide">
                <h2>⚖️ Чекають вашого рішення <span class="count">{{ data.decisions.length + data.supply.decisions.length }}</span></h2>
                <div class="t-rows">
                    <TaskRow v-for="t in data.decisions" :key="'t' + t.id" :task="t" />
                    <router-link v-for="r in data.supply.decisions" :key="'s' + r.id" :to="{ name: 'supply-board', params: { id: r.id } }" class="t-row p-home-supply" data-testid="home-supply-decision">
                        <span class="title">{{ r.item }} — {{ quantity(r) }}</span>
                        <span class="meta">
                            <span>Заявка № {{ r.number }}</span>
                            <span class="t-status" :class="tone(r.status)">{{ r.statusLabel }}</span>
                            <span v-if="r.needBy" class="t-due" :class="{ over: r.overdue }">⏰ {{ dueLabel(r.needBy) }}</span>
                            <span>{{ r.author.name }}</span>
                        </span>
                    </router-link>
                </div>
            </section>

            <div class="p-home-strips">
                <section class="p-home-strip" data-testid="home-supply">
                    <h2>🛒 Постачання</h2>
                    <router-link :to="{ name: 'supply-board', query: { v: 'open' } }"><b>{{ data.supply.counts.open }}</b> відкриті</router-link>
                    <router-link :to="{ name: 'supply-board', query: { v: 'open', st: 'new' } }"><b>{{ data.supply.counts.new }}</b> нові</router-link>
                    <router-link :to="{ name: 'supply-board', query: { v: 'overdue' } }" :class="{ alert: data.supply.counts.overdue }"><b>{{ data.supply.counts.overdue }}</b> прострочені</router-link>
                    <router-link :to="{ name: 'supply-board', query: { v: 'decide' } }" :class="{ warn: data.supply.counts.decide }"><b>{{ data.supply.counts.decide }}</b> на затвердженні</router-link>
                    <router-link v-if="!data.supply.manage" :to="{ name: 'supply-board', query: { v: 'mine' } }"><b>{{ data.supply.counts.mine }}</b> мої</router-link>
                </section>
                <section v-if="data.warehouse" class="p-home-strip" data-testid="home-warehouse">
                    <h2>📦 Склад</h2>
                    <router-link :to="{ name: 'warehouse-movements' }"><b>{{ data.warehouse.shipmentsToday }}</b> {{ plural(data.warehouse.shipmentsToday, 'відвантаження', 'відвантаження', 'відвантажень') }} сьогодні</router-link>
                    <router-link :to="{ name: 'warehouse-movements' }"><b>{{ data.warehouse.receiptsToday }}</b> {{ plural(data.warehouse.receiptsToday, 'надходження', 'надходження', 'надходжень') }} сьогодні</router-link>
                    <router-link :to="{ name: 'warehouse-items' }" :class="{ warn: data.warehouse.repair }"><b>{{ data.warehouse.repair }}</b> у ремонті</router-link>
                    <router-link :to="{ name: 'warehouse-movements' }"><b>{{ data.warehouse.movesWeek }}</b> {{ plural(data.warehouse.movesWeek, 'рух', 'рухи', 'рухів') }} за тиждень</router-link>
                </section>
            </div>

            <div class="t-grid">
                <section class="t-panel">
                    <h2>🔥 Прострочені <span class="count">{{ data.stats.overdue }}</span></h2>
                    <div v-if="data.overdue.length" class="t-rows"><TaskRow v-for="t in data.overdue" :key="t.id" :task="t" /></div>
                    <div v-else class="t-empty">{{ data.stats.overdue ? 'Усі прострочені — вище, у рішеннях.' : 'Усе в строк.' }}</div>
                </section>
                <section class="t-panel">
                    <h2>📌 Мої задачі <span class="count">{{ data.stats.mine }}</span></h2>
                    <div v-if="data.mine.length" class="t-rows"><TaskRow v-for="t in data.mine" :key="t.id" :task="t" /></div>
                    <div v-else class="t-empty">{{ data.stats.mine ? 'Усі ваші відкриті задачі — у блоках вище.' : 'Відкритих задач немає 🎉' }}</div>
                </section>
                <section class="t-panel p-home-dep">
                    <h2>🏢 {{ data.departmentName ?? 'Мій відділ' }} <span class="count">{{ data.department.length }}</span></h2>
                    <div v-if="data.department.length" class="t-rows"><TaskRow v-for="t in data.department" :key="t.id" :task="t" /></div>
                    <div v-else class="t-empty">{{ data.departmentName ? 'Інших відкритих задач відділу немає.' : 'Вас ще не додали до відділу.' }}</div>
                </section>
                <section class="t-panel p-home-recent">
                    <h2>🕘 Остання активність</h2>
                    <div v-if="data.recent.length" class="t-rows"><TaskRow v-for="t in data.recent" :key="t.id" :task="t" /></div>
                    <div v-else class="t-empty">Поки тихо.</div>
                </section>
            </div>
            <router-link :to="{ name: 'tasks-board' }" class="t-btn p-home-all">Усі задачі на дошці →</router-link>
        </template>
    </div>
</template>

<style>
.p-home-strips { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 10px; }
.p-home-strip { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; padding: 10px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--p-r-lg, 12px); }
.p-home-strip h2 { margin: 0 6px 0 0; font-size: 14px; }
.p-home-strip a { color: var(--text-muted); font-size: 13.5px; white-space: nowrap; text-decoration: none; }
.p-home-strip a b { color: var(--text); font-variant-numeric: tabular-nums; margin-right: 3px; }
.p-home-strip a:hover b { color: var(--accent); }
.p-home-strip a.alert b { color: var(--rose); }
.p-home-strip a.warn b { color: var(--amber); }
.p-home-supply .title { font-weight: 600; }
.p-home-all { display: none; }
@media (max-width: 760px) {
    /* Телефон: коротко — по 3 рядки в блоці, без «Відділу» й «Активності» (вони є на дошці й у Хроніці). */
    .p-home .t-grid .t-rows > :nth-child(n + 4) { display: none; }
    .p-home .p-home-dep, .p-home .p-home-recent { display: none; }
    .p-home-strips { grid-template-columns: minmax(0, 1fr); }
    .p-home-strip { gap: 4px 12px; }
    .p-home-all { display: flex; justify-content: center; }
    .p-home .t-stats { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
    .p-home .t-stat { padding: 8px 6px; gap: 2px; }
    .p-home .t-stat .v { font-size: 1.35rem; }
    .p-home .t-stat .k { font-size: .7rem; line-height: 1.15; }
}
</style>
