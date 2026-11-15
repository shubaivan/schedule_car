<script setup lang="ts">
import PersonName from '../../plan/PersonName.vue'
import { num, plural } from '../../plan/uk'
import { computed, onMounted, ref } from 'vue'
import TaskRow from './TaskRow.vue'
import { tasksApi } from './api'
import { dueLabel, today } from './format'
import { useTasks } from './store'
import { api } from '../../app/api'
import { useSession } from '../../app/store'
import { useLiveRefresh } from '../../plan/live'
import { useNotifications } from '../../plan/notifications/store'
import { isoDay } from '../plan/searchQuery'
import { overviewApi, type Decision, type DecisionAction, type Overview } from '../plan/overview'

/**
 * «Огляд» v2 — екран директора за зразком ПланФікса (DASHBOARD_MARKET_2026-11-15 §3–4):
 * зверху рядок великих чисел «Сьогодні», клік по числу — той самий список задач, заявок
 * чи рухів складу (окрема адреса, «Назад» повертає сюди). Нижче: «Чекають мого рішення»
 * з кнопками просто в рядку, «Контроль по відділах», праворуч — постачання, автопарк,
 * склад. Продажі, дебіторка й ліди — позначені «скоро», без вигаданих цифр.
 * Цифри живі: оновлюються за подією (Mercure), без нього — раз на 30 с.
 */
const session = useSession()
const store = useTasks()
const live = useNotifications()
const data = ref<Overview | null>(null)
const error = ref('')

async function load() {
    try {
        await store.load()
        data.value = await overviewApi.get()
        error.value = ''
    } catch (e) {
        error.value = session.handle(e)
    }
}

const hello = () => {
    const h = new Date().getHours()
    return h < 12 ? 'Доброго ранку' : h < 18 ? 'Добрий день' : 'Добрий вечір'
}
const todayIso = isoDay(new Date())
const updated = computed(() => (data.value ? new Date(data.value.generatedAt).toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' }) : ''))
const month = computed(() => (data.value?.supply.month ? new Intl.DateTimeFormat('uk-UA', { month: 'long' }).format(new Date(data.value.supply.month.from + 'T12:00:00')) : ''))
const deciding = computed(() => !!data.value && (data.value.scope.decideTasks || data.value.scope.decideSupply))
const uah = (n: number) => `${num(Math.round(n))} ₴`
const list = (show: string, dep?: number | 'none') => ({ name: 'tasks-list', query: { ...(show !== 'open' ? { show } : {}), ...(dep !== undefined ? { dep: String(dep) } : {}) } })

/* ---------- рішення в рядку ---------- */
const busy = ref<string | null>(null)
const rejecting = ref<string | null>(null)
const reason = ref('')
const rowError = ref<Record<string, string>>({})
const done = ref<Record<string, string>>({})
const key = (d: Decision) => `${d.kind}-${d.id}`
const openRoute = (d: Decision) => (d.kind === 'task' ? { name: 'tasks-board', params: { id: d.id } } : { name: 'supply-board', params: { id: d.id } })

async function act(d: Decision, a: DecisionAction) {
    const k = key(d)
    if (a.reject && rejecting.value !== k) {
        rejecting.value = k
        reason.value = ''
        return
    }
    const comment = a.reject ? reason.value.trim() : ''
    if (a.reasonRequired && !comment) {
        rowError.value = { ...rowError.value, [k]: 'Вкажіть причину — заявник має знати, що робити далі.' }
        return
    }
    busy.value = k
    rowError.value = { ...rowError.value, [k]: '' }
    try {
        if (d.kind === 'task') await tasksApi.status(d.id, a.to, comment || undefined)
        else await api.changeStatus(d.id, a.to, comment || undefined)
        done.value = { ...done.value, [k]: a.reject ? 'Відхилено' : 'Погоджено' }
        rejecting.value = null
        await load()
    } catch (e) {
        rowError.value = { ...rowError.value, [k]: session.handle(e) }
    } finally {
        busy.value = null
    }
}

/* ---------- автопарк: години броні «10–12, 15» ---------- */
function hours(list: number[]): string {
    const out: string[] = []
    for (let i = 0; i < list.length; i++) {
        let j = i
        while (j + 1 < list.length && list[j + 1] === list[j] + 1) j++
        out.push(i === j ? `${list[i]}:00` : `${list[i]}–${list[j] + 1}:00`)
        i = j
    }
    return out.join(', ')
}

onMounted(() => load())
// З Mercure — за подією (+ раз на хвилину), інакше кожні 30 с.
useLiveRefresh(() => load(), { pollMs: 30000, debounceMs: 1000 })
</script>

<template>
    <div class="t-page p-home ov">
        <div class="t-head">
            <div>
                <h1>{{ hello() }}, {{ store.meta?.me.name.split(' ')[0] ?? session.user?.firstName }}</h1>
                <div class="t-sub">
                    {{ today() }} · {{ store.meta?.me.position ?? store.meta?.me.roleLabel }}
                    <template v-if="store.meta?.me.department">· {{ store.meta?.me.department }}</template>
                    <template v-if="updated">
                        ·
                        <span
                            class="ov-live"
                            :class="{ on: live.transport === 'mercure' }"
                            :title="live.transport === 'mercure' ? 'Цифри оновлюються самі, щойно щось змінилось' : 'Оновлюється кожні 30 с'"
                        >
                            оновлено о {{ updated }}
                        </span>
                    </template>
                </div>
            </div>
            <router-link :to="{ name: 'tasks-new' }" class="t-btn primary">＋ Нова задача</router-link>
        </div>

        <div v-if="error" class="t-error">{{ error }}</div>
        <div v-if="!data" class="t-empty">Завантаження…</div>

        <template v-else>
            <!-- (1) Сьогодні: великі числа, клік — список -->
            <nav class="ov-kpi" aria-label="Сьогодні" data-testid="ov-kpi">
                <router-link :to="list('overdue')" class="ov-tile" :class="{ alert: data.kpi.overdueTasks }" data-testid="kpi-overdue">
                    <span class="v">{{ data.kpi.overdueTasks }}</span>
                    <span class="k">Прострочені задачі</span>
                </router-link>
                <a v-if="deciding" href="#decide" class="ov-tile" :class="{ warn: data.kpi.decisions }" data-testid="kpi-decide">
                    <span class="v">{{ data.kpi.decisions }}</span>
                    <span class="k">Чекають рішення</span>
                </a>
                <router-link v-else :to="list('mine')" class="ov-tile" data-testid="kpi-mine">
                    <span class="v">{{ data.kpi.mineTasks }}</span>
                    <span class="k">Мої відкриті</span>
                </router-link>
                <router-link :to="{ name: 'supply-board', query: { v: 'overdue' } }" class="ov-tile" :class="{ alert: data.kpi.supplyOverdue }" data-testid="kpi-supply">
                    <span class="v">{{ data.kpi.supplyOverdue }}</span>
                    <span class="k">Заявки прострочені</span>
                </router-link>
                <a v-if="data.kpi.fleet" href="#fleet" class="ov-tile" data-testid="kpi-fleet">
                    <span class="v">{{ data.kpi.fleet.busy }} / {{ data.kpi.fleet.free }}</span>
                    <span class="k">Машини зайняті / вільні</span>
                </a>
                <div v-else class="ov-tile off" title="Розділ «Автопарк» у Плані ще не ввімкнено" data-testid="kpi-fleet">
                    <span class="v">—</span>
                    <span class="k">Автопарк · не підключено</span>
                </div>
                <div class="ov-tile off" title="Звернення з сайту й ІІ-консультанта — після підключення бота сайту" data-testid="kpi-leads">
                    <span class="v soon">скоро</span>
                    <span class="k">Нові звернення</span>
                </div>
                <router-link
                    v-if="data.kpi.shipmentsToday !== null"
                    :to="{ name: 'warehouse-movements', query: { t: 'shipment', from: todayIso, to: todayIso } }"
                    class="ov-tile"
                    data-testid="kpi-shipments"
                >
                    <span class="v">{{ data.kpi.shipmentsToday }}</span>
                    <span class="k">Видача майна сьогодні</span>
                </router-link>
                <router-link v-else :to="list('stale')" class="ov-tile" :class="{ warn: data.kpi.staleTasks }" data-testid="kpi-stale">
                    <span class="v">{{ data.kpi.staleTasks }}</span>
                    <span class="k">Без руху {{ data.control.staleDays }}+ дн.</span>
                </router-link>
            </nav>

            <div class="ov-grid">
                <div class="ov-main">
                    <!-- (2) Чекають мого рішення -->
                    <section v-if="deciding" id="decide" class="t-panel ov-decide" :class="{ calm: !data.decisions.total }" data-testid="ov-decide">
                        <h2>
                            ⚖️ Чекають мого рішення
                            <span class="count">{{ data.decisions.total }}</span>
                        </h2>
                        <div v-if="data.decisions.items.length" class="ov-drows">
                            <div v-for="d in data.decisions.items" :key="key(d)" class="ov-drow" :data-testid="'decision-' + key(d)">
                                <router-link :to="openRoute(d)" class="ov-dmain">
                                    <span class="ov-kind" :class="d.kind">{{ d.kind === 'task' ? 'Задача' : 'Заявка' }} № {{ d.number }}</span>
                                    <span class="ov-dtitle">{{ d.title }}</span>
                                    <span class="ov-dmeta">
                                        <span class="t-status tone-amber">{{ d.statusLabel }}</span>
                                        <span v-if="d.due" class="t-due" :class="{ over: d.overdue }">⏰ {{ dueLabel(d.due) }}</span>
                                        <b v-if="d.amount !== null">{{ uah(d.amount) }}</b>
                                        <span v-if="d.detail">{{ d.detail }}</span>
                                        <PersonName v-if="d.person" :person="d.person" :link="false" />
                                    </span>
                                </router-link>
                                <div v-if="rejecting === key(d)" class="ov-reason">
                                    <input
                                        v-model="reason"
                                        class="t-input"
                                        :placeholder="d.actions.find((a) => a.reject)?.reasonRequired ? 'Причина відхилення (обовʼязково)' : 'Причина (необовʼязково)'"
                                        :aria-label="'Причина відхилення: ' + d.title"
                                        @keydown.enter="
                                            act(
                                                d,
                                                d.actions.find((a) => a.reject)!,
                                            )
                                        "
                                    />
                                    <div class="ov-acts">
                                        <button
                                            type="button"
                                            class="t-btn small danger"
                                            :disabled="busy === key(d)"
                                            @click="
                                                act(
                                                    d,
                                                    d.actions.find((a) => a.reject)!,
                                                )
                                            "
                                        >
                                            Відхилити
                                        </button>
                                        <button type="button" class="t-btn small" @click="rejecting = null">Скасувати</button>
                                    </div>
                                </div>
                                <div v-else class="ov-acts">
                                    <button
                                        v-for="a in d.actions"
                                        :key="a.to"
                                        type="button"
                                        class="t-btn small"
                                        :class="a.reject ? 'danger' : 'primary'"
                                        :disabled="busy === key(d)"
                                        :data-testid="'act-' + a.to"
                                        @click="act(d, a)"
                                    >
                                        {{ a.label }}
                                    </button>
                                    <router-link :to="openRoute(d)" class="t-btn small">Відкрити</router-link>
                                </div>
                                <div v-if="rowError[key(d)]" class="t-error ov-rowerr">{{ rowError[key(d)] }}</div>
                            </div>
                        </div>
                        <div v-else class="t-empty">Рішень не чекає нічого.</div>
                        <div v-if="data.decisions.total > data.decisions.items.length || data.decisions.tasks || data.decisions.supply" class="ov-more">
                            <router-link v-if="data.decisions.tasks" :to="list('decide')">Усі задачі на рішенні ({{ data.decisions.tasks }}) →</router-link>
                            <router-link v-if="data.decisions.supply" :to="{ name: 'supply-board', query: { v: 'decide' } }">
                                Усі заявки на рішенні ({{ data.decisions.supply }}) →
                            </router-link>
                        </div>
                    </section>

                    <!-- (3) Контроль по відділах -->
                    <section class="t-panel ov-control" data-testid="ov-control">
                        <h2>
                            🏢 Контроль по відділах
                            <span v-if="!data.scope.all" class="count">· ваші задачі</span>
                        </h2>
                        <table class="ov-table">
                            <thead>
                                <tr>
                                    <th>Відділ</th>
                                    <th>
                                        <span class="long">Відкриті</span>
                                        <span class="short">Відкр.</span>
                                    </th>
                                    <th>
                                        <span class="long">Прострочені</span>
                                        <span class="short">Простр.</span>
                                    </th>
                                    <th>
                                        <span class="long">Без руху {{ data.control.staleDays }}+ дн.</span>
                                        <span class="short">Стоять {{ data.control.staleDays }}+ дн.</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in data.control.rows" :key="r.id" :data-testid="'dep-' + r.id">
                                    <td>
                                        <router-link :to="list('open', r.id)">{{ r.name }}</router-link>
                                    </td>
                                    <td>
                                        <router-link :to="list('open', r.id)">{{ r.open }}</router-link>
                                    </td>
                                    <td :class="{ bad: r.overdue }">
                                        <router-link :to="list('overdue', r.id)">{{ r.overdue }}</router-link>
                                    </td>
                                    <td :class="{ warn: r.stale }">
                                        <router-link :to="list('stale', r.id)">{{ r.stale }}</router-link>
                                    </td>
                                </tr>
                                <tr v-if="data.control.none.open" class="muted" data-testid="dep-none">
                                    <td><router-link :to="list('open', 'none')">Без відділу</router-link></td>
                                    <td>
                                        <router-link :to="list('open', 'none')">{{ data.control.none.open }}</router-link>
                                    </td>
                                    <td :class="{ bad: data.control.none.overdue }">
                                        <router-link :to="list('overdue', 'none')">{{ data.control.none.overdue }}</router-link>
                                    </td>
                                    <td :class="{ warn: data.control.none.stale }">
                                        <router-link :to="list('stale', 'none')">{{ data.control.none.stale }}</router-link>
                                    </td>
                                </tr>
                                <tr v-if="!data.control.rows.length && !data.control.none.open">
                                    <td colspan="4" class="t-empty">Відкритих задач немає.</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td>Усього</td>
                                    <td>
                                        <router-link :to="list('open')">{{ data.control.total.open }}</router-link>
                                    </td>
                                    <td :class="{ bad: data.control.total.overdue }">
                                        <router-link :to="list('overdue')">{{ data.control.total.overdue }}</router-link>
                                    </td>
                                    <td :class="{ warn: data.control.total.stale }">
                                        <router-link :to="list('stale')">{{ data.control.total.stale }}</router-link>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                        <p class="ov-note">Задача з кількома відділами рахується в кожному, тож «Усього» — без повторів, а не сума рядків.</p>
                    </section>

                    <section class="t-panel" data-testid="ov-mine">
                        <h2>
                            📌 Мої задачі
                            <span class="count">{{ data.kpi.mineTasks }}</span>
                        </h2>
                        <div v-if="data.mine.length" class="t-rows"><TaskRow v-for="t in data.mine" :key="t.id" :task="t" /></div>
                        <div v-else class="t-empty">Відкритих задач немає.</div>
                        <router-link v-if="data.kpi.mineTasks > data.mine.length" :to="list('mine')" class="ov-more">Усі мої ({{ data.kpi.mineTasks }}) →</router-link>
                    </section>
                </div>

                <aside class="ov-side">
                    <!-- (4) Продажі — скоро -->
                    <section v-if="data.scope.all" class="t-panel ov-soon" data-testid="ov-sales">
                        <h2>
                            📈 Продажі: план / факт
                            <span class="ov-badge">скоро</span>
                        </h2>
                        <p>План місяця директор вводитиме сам (як «Ввести вручну» в ПланФіксі), факт — з 1С. Поки цифр немає — не показуємо жодних.</p>
                    </section>

                    <!-- (7) Автопарк по бронях -->
                    <section id="fleet" class="t-panel" data-testid="ov-fleet">
                        <h2>🚚 Автопарк сьогодні</h2>
                        <template v-if="data.fleet.enabled">
                            <div class="ov-line">
                                <b>{{ data.fleet.busyToday }}</b>
                                {{ plural(data.fleet.busyToday, 'зайнята', 'зайняті', 'зайнятих') }} ·
                                <b>{{ data.fleet.freeToday }}</b>
                                {{ plural(data.fleet.freeToday, 'вільна', 'вільні', 'вільних') }} · завтра в броні {{ data.fleet.busyTomorrow }}
                            </div>
                            <ul v-if="data.fleet.cars.length" class="ov-cars">
                                <li v-for="c in data.fleet.cars" :key="c.id">
                                    <span class="car">
                                        {{ c.number }}
                                        <small v-if="c.model">{{ c.model }}</small>
                                    </span>
                                    <span v-if="c.today.length" class="busy">сьогодні {{ hours(c.today) }}</span>
                                    <span v-else class="free">вільна</span>
                                    <span v-if="c.tomorrow.length" class="tmr">завтра {{ hours(c.tomorrow) }}</span>
                                </li>
                            </ul>
                            <div v-else class="t-empty">Машин ще не додано.</div>
                            <router-link :to="{ name: 'schedule' }" class="ov-more">Розклад машин →</router-link>
                        </template>
                        <p v-else class="ov-note">Розділ «Автопарк» у Плані ще не ввімкнено. Щойно в ньому зʼявляться машини, тут буде видно броні на сьогодні й завтра.</p>
                    </section>

                    <!-- (5) Звернення — скоро -->
                    <section v-if="data.scope.all" class="t-panel ov-soon" data-testid="ov-leads">
                        <h2>
                            📨 Звернення за тиждень
                            <span class="ov-badge">скоро</span>
                        </h2>
                        <p>Сайт, ІІ-консультант, Telegram — зʼявляться, коли бот сайту передаватиме заявки в План.</p>
                    </section>

                    <!-- (6) Постачання за місяць -->
                    <section class="t-panel" data-testid="ov-supply">
                        <h2>
                            🛒 Постачання
                            <template v-if="month">· {{ month }}</template>
                        </h2>
                        <div class="ov-links">
                            <router-link :to="{ name: 'supply-board', query: { v: 'open' } }">
                                <b>{{ data.supply.counts.open }}</b>
                                відкриті
                            </router-link>
                            <router-link :to="{ name: 'supply-board', query: { v: 'overdue' } }" :class="{ alert: data.supply.counts.overdue }">
                                <b>{{ data.supply.counts.overdue }}</b>
                                прострочені
                            </router-link>
                            <router-link :to="{ name: 'supply-board', query: { v: 'open', st: 'new' } }">
                                <b>{{ data.supply.counts.new }}</b>
                                нові
                            </router-link>
                            <router-link :to="{ name: 'supply-board', query: { v: 'decide' } }" :class="{ warn: data.supply.counts.decide }">
                                <b>{{ data.supply.counts.decide }}</b>
                                на затвердженні
                            </router-link>
                            <router-link v-if="!data.supply.manage" :to="{ name: 'supply-board', query: { v: 'mine' } }">
                                <b>{{ data.supply.counts.mine }}</b>
                                мої
                            </router-link>
                        </div>
                        <template v-if="data.supply.month">
                            <div class="ov-money" data-testid="ov-spent">
                                Закуплено:
                                <b>{{ uah(data.supply.month.spent) }}</b>
                                · {{ data.supply.month.purchases }}
                                {{ plural(data.supply.month.purchases, 'закупівля', 'закупівлі', 'закупівель') }}
                            </div>
                            <ul v-if="data.supply.month.suppliers.length" class="ov-top">
                                <li v-for="s in data.supply.month.suppliers" :key="s.id">
                                    <span>{{ s.name }}</span>
                                    <b>{{ uah(s.total) }}</b>
                                </li>
                            </ul>
                            <ul v-if="data.supply.month.departments.length" class="ov-top muted">
                                <li v-for="d in data.supply.month.departments" :key="d.id ?? 0">
                                    <span>{{ d.name }}</span>
                                    <b>{{ uah(d.total) }}</b>
                                </li>
                            </ul>
                            <router-link v-if="data.supply.reports" :to="{ name: 'reports' }" class="ov-more">Звіт постачання →</router-link>
                        </template>
                    </section>

                    <!-- (10) Склад -->
                    <section v-if="data.warehouse" class="t-panel" data-testid="ov-warehouse">
                        <h2>📦 Склад</h2>
                        <div class="ov-links">
                            <router-link :to="{ name: 'warehouse-movements', query: { t: 'shipment', from: todayIso, to: todayIso } }">
                                <b>{{ data.warehouse.shipmentsToday }}</b>
                                видача сьогодні
                            </router-link>
                            <router-link :to="{ name: 'warehouse-movements', query: { t: 'receipt', from: todayIso, to: todayIso } }">
                                <b>{{ data.warehouse.receiptsToday }}</b>
                                надходження
                            </router-link>
                            <router-link :to="{ name: 'warehouse-items', query: { v: 'repair' } }" :class="{ warn: data.warehouse.repair }">
                                <b>{{ data.warehouse.repair }}</b>
                                у ремонті
                            </router-link>
                            <router-link :to="{ name: 'warehouse-movements' }">
                                <b>{{ data.warehouse.movesWeek }}</b>
                                {{ plural(data.warehouse.movesWeek, 'рух', 'рухи', 'рухів') }} за тиждень
                            </router-link>
                        </div>
                        <template v-if="data.warehouse.lowCount">
                            <router-link :to="{ name: 'warehouse-items', query: { v: 'low' } }" class="ov-lowhead" data-testid="ov-low">
                                ⚠ Нижче мінімуму: {{ data.warehouse.lowCount }}
                            </router-link>
                            <ul class="ov-top">
                                <li v-for="l in data.warehouse.low" :key="l.id">
                                    <router-link :to="{ name: 'warehouse-items', params: { id: l.id } }">{{ l.name }}</router-link>
                                    <b class="bad">{{ l.quantity }} / {{ l.min }} {{ l.unit }}</b>
                                </li>
                            </ul>
                        </template>
                        <p v-else class="ov-note">Залишків нижче мінімуму немає. Мінімум задається в картці позиції «кількістю».</p>
                    </section>

                    <!-- (8) Дебіторка — скоро -->
                    <section v-if="data.scope.all" class="t-panel ov-soon" data-testid="ov-debt">
                        <h2>
                            💳 Дебіторка
                            <span class="ov-badge">скоро</span>
                        </h2>
                        <p>Загальна й прострочена сума, топ контрагентів — з 1С, коли підключимо обмін.</p>
                    </section>
                </aside>
            </div>
        </template>
    </div>
</template>

<style>
.ov .ov-live::before {
    content: '';
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    margin-right: 4px;
    background: var(--text-muted);
    vertical-align: 1px;
}
.ov .ov-live.on::before {
    background: var(--green);
}
.ov-kpi {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 10px;
}
.ov-tile {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 12px 14px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--p-r-lg, 12px);
    box-shadow: var(--t-shadow);
    color: var(--text);
    text-decoration: none;
    min-width: 0;
}
a.ov-tile:hover {
    border-color: var(--accent);
}
.ov-tile .v {
    font-size: 30px;
    font-weight: 800;
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
    line-height: 1.1;
}
.ov-tile .v.soon {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-muted);
    line-height: 2.2;
}
.ov-tile .k {
    font-size: 13px;
    color: var(--text-muted);
    font-weight: 600;
    line-height: 1.2;
}
.ov-tile.alert .v {
    color: var(--rose);
}
.ov-tile.warn .v {
    color: var(--amber);
}
.ov-tile.off {
    background: var(--surface-2);
    border-style: dashed;
    box-shadow: none;
}
.ov-tile.off .v {
    color: var(--text-muted);
}
.ov-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.75fr) minmax(300px, 1fr);
    gap: 14px;
    align-items: start;
}
.ov-main,
.ov-side {
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-width: 0;
}
.ov-decide {
    border-color: var(--amber);
}
.ov-decide.calm {
    border-color: var(--border);
}
.ov-drows {
    display: flex;
    flex-direction: column;
}
.ov-drow {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 6px 12px;
    align-items: center;
    padding: 9px 4px;
    border-bottom: 1px solid var(--border);
}
.ov-drow:last-child {
    border-bottom: 0;
}
.ov-dmain {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    color: var(--text);
    text-decoration: none;
}
.ov-dmain:hover .ov-dtitle {
    color: var(--accent);
}
.ov-kind {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-muted);
}
.ov-kind.supply {
    color: var(--violet);
}
.ov-dtitle {
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ov-dmeta {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 10px;
    align-items: center;
    font-size: 12.5px;
    color: var(--text-muted);
}
.ov-dmeta b {
    color: var(--text);
}
.ov-acts {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    justify-content: flex-end;
}
.ov-reason {
    grid-column: 1 / -1;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.ov-reason .t-input {
    flex: 1 1 260px;
    min-width: 0;
}
.ov-rowerr {
    grid-column: 1 / -1;
}
.ov-more {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 16px;
    margin-top: 8px;
    font-size: 13.5px;
}
.ov-table {
    width: 100%;
    border-collapse: collapse;
    font-variant-numeric: tabular-nums;
}
.ov-table th {
    text-align: right;
    font-size: 12.5px;
    color: var(--text-muted);
    font-weight: 600;
    padding: 4px 8px;
    border-bottom: 1px solid var(--border);
}
.ov-table .short {
    display: none;
}
.ov-table th:first-child,
.ov-table td:first-child {
    text-align: left;
}
.ov-table td {
    text-align: right;
    padding: 0;
    border-bottom: 1px solid var(--border);
}
.ov-table td a {
    display: block;
    padding: 8px;
    color: var(--text);
    text-decoration: none;
}
.ov-table td a:hover {
    background: var(--accent-soft);
}
.ov-table td.bad a {
    color: var(--rose);
    font-weight: 700;
}
.ov-table td.warn a {
    color: var(--amber);
    font-weight: 700;
}
.ov-table tr.muted td:first-child a {
    color: var(--text-muted);
}
.ov-table tfoot td {
    border-bottom: 0;
    font-weight: 700;
}
.ov-table tfoot td:first-child {
    padding: 8px;
}
.ov-note {
    margin: 8px 0 0;
    font-size: 12.5px;
    color: var(--text-muted);
}
.ov-soon {
    border-style: dashed;
    box-shadow: none;
    background: var(--surface-2);
}
.ov-soon h2 {
    margin-bottom: 4px;
}
.ov-soon p {
    margin: 0;
    font-size: 13px;
    color: var(--text-muted);
}
.ov-badge {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted);
    border: 1px solid var(--border-strong);
    border-radius: 999px;
    padding: 1px 7px;
}
.ov-links {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 14px;
}
.ov-links a {
    color: var(--text-muted);
    font-size: 13.5px;
    text-decoration: none;
    white-space: nowrap;
}
.ov-links a b {
    color: var(--text);
    font-variant-numeric: tabular-nums;
}
.ov-links a:hover b {
    color: var(--accent);
}
.ov-links a.alert b,
.ov-top .bad {
    color: var(--rose);
}
.ov-links a.warn b {
    color: var(--amber);
}
.ov-money {
    margin-top: 10px;
    font-size: 14px;
}
.ov-top {
    list-style: none;
    margin: 6px 0 0;
    padding: 0;
    font-size: 13px;
}
.ov-top li {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 3px 0;
}
.ov-top li span,
.ov-top li a {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ov-top.muted {
    color: var(--text-muted);
}
.ov-top b {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.ov-lowhead {
    display: inline-block;
    margin-top: 10px;
    font-weight: 700;
    color: var(--rose);
}
.ov-line {
    font-size: 13.5px;
}
.ov-cars {
    list-style: none;
    margin: 8px 0 0;
    padding: 0;
    font-size: 13px;
}
.ov-cars li {
    display: flex;
    flex-wrap: wrap;
    gap: 2px 10px;
    padding: 5px 0;
    border-bottom: 1px solid var(--border);
}
.ov-cars .car {
    font-weight: 700;
    min-width: 110px;
}
.ov-cars .busy {
    color: var(--amber);
}
.ov-cars .free {
    color: var(--green);
}
.ov-cars .tmr {
    color: var(--text-muted);
}
@media (max-width: 1180px) {
    .ov-kpi {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
@media (max-width: 900px) {
    .ov-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
@media (max-width: 760px) {
    .ov-kpi {
        gap: 6px;
    }
    .ov-tile {
        padding: 8px 8px;
    }
    .ov-tile .v {
        font-size: 22px;
    }
    .ov-tile .v.soon {
        font-size: 13px;
        line-height: 1.7;
    }
    .ov-tile .k {
        font-size: 11.5px;
    }
    /* Рядок рішення на телефоні: текст зверху, кнопки під ним на всю ширину, торкання ≥ 44 px. */
    .ov-drow {
        grid-template-columns: minmax(0, 1fr);
    }
    .ov-dtitle {
        white-space: normal;
    }
    .ov-acts {
        display: grid;
        grid-auto-flow: column;
        grid-auto-columns: minmax(0, 1fr);
        justify-content: stretch;
    }
    .ov-acts .t-btn {
        min-height: 44px;
        justify-content: center;
    }
    .ov-reason {
        flex-direction: column;
    }
    .ov-reason .t-input {
        flex: 0 0 auto;
        min-height: 44px;
        font-size: 16px;
    }
    .ov-table td a {
        padding: 10px 6px;
    }
    .ov-table th {
        padding: 4px 6px;
        font-size: 11.5px;
    }
    .ov-table {
        table-layout: fixed;
    }
    .ov-table th:first-child {
        width: 43%;
    }
    .ov-table th {
        white-space: normal;
        line-height: 1.2;
    }
    .ov-table .long {
        display: none;
    }
    .ov-table .short {
        display: inline;
    }
    .ov-table td:first-child a {
        overflow-wrap: anywhere;
    }
}
</style>
