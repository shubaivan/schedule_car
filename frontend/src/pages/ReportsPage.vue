<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, type Report } from '../app/api'
import { useSession } from '../app/store'

const session = useSession()
const report = ref<Report | null>(null)
const error = ref('')
const loading = ref(true)

// За замовчуванням — поточний місяць.
const today = new Date()
const iso = (date: Date) => date.toISOString().slice(0, 10)
const from = ref(iso(new Date(today.getFullYear(), today.getMonth(), 1)))
const to = ref(iso(today))

const money = (value: number) => value.toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

async function load() {
    loading.value = true
    error.value = ''

    try {
        report.value = await api.reports(from.value, to.value)
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        loading.value = false
    }
}

/** Швидкі періоди: місяць і квартал питають найчастіше. */
function shift(months: number) {
    const start = new Date(today.getFullYear(), today.getMonth() - months + 1, 1)
    from.value = iso(start)
    to.value = iso(today)
    load()
}

onMounted(load)
</script>

<template>
    <div v-if="error" class="error">{{ error }}</div>

    <div class="card">
        <div class="filters">
            <label>з <input v-model="from" type="date" /></label>
            <label>по <input v-model="to" type="date" /></label>
            <button class="primary" @click="load">Показати</button>
            <button @click="shift(1)">Цей місяць</button>
            <button @click="shift(3)">Квартал</button>
        </div>
    </div>

    <div v-if="loading" class="center">Завантаження…</div>

    <template v-else-if="report">
        <div class="card">
            <h3 style="margin-top:0">Разом за період</h3>
            <dl class="grid">
                <div>
                    <dt>Витрачено</dt>
                    <dd><b>{{ money(report.totals.spent) }} ₴</b></dd>
                </div>
                <div>
                    <dt>Закупівель</dt>
                    <dd>{{ report.totals.purchases }}</dd>
                </div>
                <div>
                    <dt>Заявок подано</dt>
                    <dd>{{ report.totals.requests }}</dd>
                </div>
                <div>
                    <dt>Закрито</dt>
                    <dd>{{ report.totals.closed }}</dd>
                </div>
                <div>
                    <dt>У роботі</dt>
                    <dd>
                        {{ report.totals.open }}
                        <span v-if="report.totals.overdue" class="flag">{{ report.totals.overdue }} прострочено</span>
                    </dd>
                </div>
                <div>
                    <dt>Середній строк</dt>
                    <dd>
                        <template v-if="report.totals.leadTimeDays !== null">
                            {{ report.totals.leadTimeDays }} дн.
                        </template>
                        <span v-else class="muted">—</span>
                    </dd>
                </div>
            </dl>
        </div>

        <div class="card">
            <h3 style="margin-top:0">Постачальники</h3>
            <p v-if="!report.suppliers.length" class="muted">За цей період закупівель не було.</p>
            <div v-else class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Постачальник</th>
                        <th>Закупівель</th>
                        <th>Сума</th>
                        <th>Частка</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="supplier in report.suppliers" :key="supplier.id">
                        <td class="wrap">{{ supplier.name }}</td>
                        <td>{{ supplier.purchases }}</td>
                        <td>{{ money(supplier.total) }} ₴</td>
                        <td>{{ supplier.share }}%</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-top:0">Підрозділи</h3>
            <p v-if="!report.departments.length" class="muted">Немає даних.</p>
            <div v-else class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Підрозділ</th>
                        <th>Заявок</th>
                        <th>Сума</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="department in report.departments" :key="department.id ?? 'none'">
                        <td class="wrap">{{ department.name }}</td>
                        <td>{{ department.requests }}</td>
                        <td>{{ money(department.total) }} ₴</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-top:0">Матеріали</h3>
            <p v-if="!report.items.length" class="muted">Немає даних.</p>
            <div v-else class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Матеріал</th>
                        <th>Заявок</th>
                        <th>Сума</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="row in report.items" :key="row.item">
                        <td class="wrap">{{ row.item }}</td>
                        <td>{{ row.requests }}</td>
                        <td>{{ money(row.total) }} ₴</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="muted">
            Матеріали групуються за текстом заявки: «Цемент М400» і «цемент м-400» поки рахуються окремо.
            Точну аналітику по цінах дасть довідник номенклатури.
        </p>
    </template>
</template>
