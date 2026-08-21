<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, type FleetSchedule, type FleetScheduleRow, type FleetTrip } from '../app/api'
import { useSession } from '../app/store'

const session = useSession()
const board = ref<FleetSchedule | null>(null)
const error = ref('')
const loading = ref(true)

/** Тиждень — те вікно, яким планують поїздки; далі гортають кнопками. */
const DAYS = 7

const WEEKDAYS = ['нд', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб']

/** Зсув у днях від сьогодні. Стан тримаємо тут, у посиланні його немає. */
const offset = ref(0)

function isoDay(shift: number): string {
    const day = new Date()
    day.setHours(12, 0, 0, 0)
    day.setDate(day.getDate() + shift)

    return `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`
}

function dayLabel(iso: string): string {
    const [year, month, day] = iso.split('-').map(Number)
    const date = new Date(year, month - 1, day)

    return `${WEEKDAYS[date.getDay()]} ${String(day).padStart(2, '0')}.${String(month).padStart(2, '0')}`
}

const today = isoDay(0)

async function load() {
    error.value = ''
    loading.value = true

    try {
        board.value = await api.schedule(isoDay(offset.value), DAYS)
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        loading.value = false
    }
}

function move(days: number) {
    offset.value += days
    load()
}

function toToday() {
    offset.value = 0
    load()
}

const days = computed(() => board.value?.days ?? [])

/** Поїздки однієї машини в один день — по годинах, як їх і читають. */
function tripsOf(row: FleetScheduleRow, day: string): FleetTrip[] {
    return row.trips.filter(trip => trip.date === day).sort((a, b) => a.hour - b.hour)
}

function hour(trip: FleetTrip): string {
    return `${String(trip.hour).padStart(2, '0')}:00`
}

/** Старі броні маршруту не мають — там його заміняє завдання. */
function where(trip: FleetTrip): string {
    return trip.destination || trip.task || 'маршрут не вказано'
}

function tooltip(trip: FleetTrip): string {
    const parts = [`${hour(trip)} → ${where(trip)}`, `взяв(ла): ${trip.bookedBy}`]

    if (trip.bookedByPhone) parts.push(trip.bookedByPhone)
    if (trip.task) parts.push(trip.task)

    return parts.join('\n')
}

/** Скільки годин доби вже зайнято — підпис завантаження машини. */
function loadOf(row: FleetScheduleRow): string {
    const busy = days.value.reduce((sum, day) => sum + tripsOf(row, day).length, 0)

    return busy === 0 ? 'вільна весь тиждень' : `зайнято годин: ${busy}`
}

onMounted(load)
</script>

<template>
    <div v-if="error" class="error">{{ error }}</div>

    <div class="card">
        <div class="filters">
            <h2>Календар автопарку</h2>
            <button @click="move(-DAYS)">← тиждень</button>
            <button @click="toToday">сьогодні</button>
            <button @click="move(DAYS)">тиждень →</button>
            <span v-if="days.length" class="muted">{{ dayLabel(days[0]) }} … {{ dayLabel(days[days.length - 1]) }}</span>
        </div>

        <div v-if="loading" class="center">Завантаження…</div>
        <div v-else-if="!board?.items.length" class="center muted">
            Машин ще немає — додайте їх у розділі «Автопарк».
        </div>

        <div v-else class="table-wrap">
            <table class="board">
                <thead>
                <tr>
                    <th class="car-col">Машина й водій</th>
                    <th v-for="day in days" :key="day" :class="{ today: day === today }">{{ dayLabel(day) }}</th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="row in board.items" :key="row.id">
                    <td class="car-col">
                        <div class="car">{{ row.label }}</div>
                        <div class="muted small">
                            {{ row.drivers.length
                                ? row.drivers.map(d => d.name + (d.phone ? ` · ${d.phone}` : '')).join(', ')
                                : 'водій не закріплений' }}
                        </div>
                        <div class="muted small">{{ loadOf(row) }}</div>
                    </td>

                    <td v-for="day in days" :key="day" :class="{ today: day === today }">
                        <div
                            v-for="trip in tripsOf(row, day)"
                            :key="trip.id"
                            class="trip"
                            :title="tooltip(trip)"
                        >
                            <span class="time">{{ hour(trip) }}</span>
                            <span class="where">{{ where(trip) }}</span>
                            <span class="muted small who">{{ trip.bookedBy }}</span>
                        </div>

                        <span v-if="!tripsOf(row, day).length" class="free">вільна</span>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p class="muted">
        Той самий розклад люди бачать у боті — «🚗 Автопарк» → «📅 Розклад машин». Бронюють теж у боті: машина,
        день, година, куди їде й що потрібно зробити.
    </p>
</template>

<style scoped>
.board {
    table-layout: fixed;
}

.board th,
.board td {
    vertical-align: top;
}

.car-col {
    width: 220px;
}

.car {
    font-weight: 600;
}

.small {
    font-size: 13px;
}

.today {
    background: var(--accent-soft);
}

.trip {
    border-left: 3px solid var(--accent);
    padding: 2px 0 2px 6px;
    margin-bottom: 6px;
}

.trip .time {
    font-weight: 600;
    margin-right: 4px;
}

.trip .where {
    overflow-wrap: anywhere;
}

.trip .who {
    display: block;
}

.free {
    color: var(--success);
    font-size: 13px;
}
</style>
