<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, type CarPayload, type DriverPayload, type FleetCar, type FleetDriver } from '../app/api'
import { useSession } from '../app/store'

const session = useSession()
const cars = ref<FleetCar[]>([])
const drivers = ref<FleetDriver[]>([])
const error = ref('')
const loading = ref(true)
const saving = ref(false)

const blankCar = (): CarPayload => ({ carNumber: '', model: '' })
const blankDriver = (): DriverPayload => ({ phone: '', name: '', carId: null })
const carDraft = ref<CarPayload>(blankCar())
const driverDraft = ref<DriverPayload>(blankDriver())

async function load() {
    error.value = ''

    try {
        const [carList, driverList] = await Promise.all([api.cars(), api.drivers()])
        cars.value = carList.items
        drivers.value = driverList.items
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        loading.value = false
    }
}

async function addCar() {
    if (!carDraft.value.carNumber?.trim() || saving.value) return

    error.value = ''
    saving.value = true

    try {
        await api.createCar(carDraft.value)
        carDraft.value = blankCar()
        await load()
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        saving.value = false
    }
}

async function addDriver() {
    if (!driverDraft.value.phone?.trim() || saving.value) return

    error.value = ''
    saving.value = true

    try {
        await api.createDriver(driverDraft.value)
        driverDraft.value = blankDriver()
        await load()
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        saving.value = false
    }
}

async function updateCar(car: FleetCar, payload: CarPayload) {
    error.value = ''

    try {
        Object.assign(car, await api.updateCar(car.id, payload))
        // Назва машини змінилась — у водіїв вона теж підписана.
        await load()
    } catch (e) {
        error.value = session.handle(e)
        await load()
    }
}

async function updateDriver(driver: FleetDriver, payload: DriverPayload) {
    error.value = ''

    try {
        Object.assign(driver, await api.updateDriver(driver.id, payload))
    } catch (e) {
        error.value = session.handle(e)
        await load()
    }
}

async function removeDriver(driver: FleetDriver) {
    error.value = ''

    try {
        await api.deleteDriver(driver.id)
        await load()
    } catch (e) {
        error.value = session.handle(e)
    }
}

function carEdited(car: FleetCar, field: 'carNumber' | 'model', event: Event) {
    const value = (event.target as HTMLInputElement).value.trim()

    if (value === (car[field] ?? '')) return

    updateCar(car, { [field]: value || null })
}

function driverEdited(driver: FleetDriver, field: 'name' | 'note', event: Event) {
    const value = (event.target as HTMLInputElement).value.trim()

    if (value === (driver[field] ?? '')) return

    updateDriver(driver, { [field]: value || null })
}

function assignCar(driver: FleetDriver, event: Event) {
    const value = (event.target as HTMLSelectElement).value

    updateDriver(driver, { carId: value === '' ? null : Number(value) })
}

onMounted(load)
</script>

<template>
    <div v-if="error" class="error">{{ error }}</div>

    <div class="card">
        <h2>Машини</h2>

        <form class="filters" @submit.prevent="addCar">
            <input v-model="carDraft.carNumber" type="text" placeholder="AA1234BB" required />
            <input v-model="carDraft.model" type="text" placeholder="Renault Master" />
            <button class="primary" type="submit" :disabled="saving || !carDraft.carNumber?.trim()">
                Додати машину
            </button>
        </form>

        <div v-if="loading" class="center">Завантаження…</div>
        <div v-else-if="!cars.length" class="center muted">Машин ще немає — додайте першу у формі вище.</div>

        <div v-else class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Номер</th>
                    <th>Марка</th>
                    <th>Водії</th>
                    <th>Стан</th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="car in cars" :key="car.id">
                    <td>
                        <input type="text" :value="car.carNumber" @change="carEdited(car, 'carNumber', $event)" />
                    </td>
                    <td class="wrap">
                        <input type="text" :value="car.model ?? ''" @change="carEdited(car, 'model', $event)" />
                    </td>
                    <td class="wrap muted">
                        {{ drivers.filter(d => d.carId === car.id).map(d => d.name ?? d.phone).join(', ') || '—' }}
                    </td>
                    <td>
                        <button :class="car.active ? 'danger' : ''" @click="updateCar(car, { active: !car.active })">
                            {{ car.active ? 'Прибрати' : 'Повернути' }}
                        </button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Водії</h2>

        <form class="filters" @submit.prevent="addDriver">
            <input v-model="driverDraft.phone" type="text" placeholder="0671112233" required />
            <input v-model="driverDraft.name" type="text" placeholder="Петро Іванович" />
            <select v-model="driverDraft.carId">
                <option :value="null">без машини</option>
                <option v-for="car in cars" :key="car.id" :value="car.id">{{ car.label }}</option>
            </select>
            <button class="primary" type="submit" :disabled="saving || !driverDraft.phone?.trim()">
                Додати водія
            </button>
        </form>

        <div v-if="!loading && !drivers.length" class="center muted">
            Водіїв ще немає. Внесіть телефон — щойно людина натисне «Старт» у боті, вона отримає повідомлення,
            що вона водій.
        </div>

        <div v-else-if="!loading" class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Телефон</th>
                    <th>Хто</th>
                    <th>Машина</th>
                    <th>Примітка</th>
                    <th>У боті</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="driver in drivers" :key="driver.id">
                    <td>{{ driver.phone }}</td>
                    <td class="wrap">
                        <input type="text" :value="driver.name ?? ''" @change="driverEdited(driver, 'name', $event)" />
                    </td>
                    <td>
                        <select :value="driver.carId ?? ''" @change="assignCar(driver, $event)">
                            <option value="">без машини</option>
                            <option v-for="car in cars" :key="car.id" :value="car.id">{{ car.label }}</option>
                        </select>
                    </td>
                    <td class="wrap">
                        <input type="text" :value="driver.note ?? ''" @change="driverEdited(driver, 'note', $event)" />
                    </td>
                    <td class="muted">{{ driver.appliedTo ?? 'чекає' }}</td>
                    <td>
                        <button class="danger" @click="removeDriver(driver)">Прибрати</button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p class="muted">
        Водія заводять телефоном — акаунт у Telegram людина створює собі сама. Щойно вона поділиться номером у боті,
        бот напише їй, за якою машиною вона закріплена, і надсилатиме бронювання цієї машини.
    </p>
</template>
