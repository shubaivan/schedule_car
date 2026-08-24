<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, type ApiDepartment } from '../app/api'
import { useSession } from '../app/store'

const session = useSession()
const departments = ref<ApiDepartment[]>([])
const name = ref('')
const error = ref('')
const notice = ref('')
const loading = ref(true)
const saving = ref(false)

async function load() {
    error.value = ''

    try {
        departments.value = (await api.departments()).items
        // Список підрозділів висить у формах бота й CRM — оновлюємо його разом
        // із таблицею, щоб щойно доданий цех одразу було видно у фільтрах.
        await session.load()
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        loading.value = false
    }
}

async function create() {
    if (!name.value.trim() || saving.value) return

    error.value = ''
    notice.value = ''
    saving.value = true

    try {
        await api.createDepartment(name.value)
        name.value = ''
        await load()
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        saving.value = false
    }
}

async function rename(department: ApiDepartment, event: Event) {
    const value = (event.target as HTMLInputElement).value.trim()

    if (!value || value === department.name) return

    error.value = ''
    notice.value = ''

    try {
        Object.assign(department, await api.updateDepartment(department.id, { name: value }))
        await session.load()
    } catch (e) {
        error.value = session.handle(e)
        await load()
    }
}

async function toggle(department: ApiDepartment) {
    error.value = ''
    notice.value = ''

    try {
        Object.assign(department, await api.updateDepartment(department.id, { active: !department.active }))
        await session.load()
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function remove(department: ApiDepartment) {
    error.value = ''
    notice.value = ''

    try {
        notice.value = (await api.deleteDepartment(department.id)).message
        await load()
    } catch (e) {
        error.value = session.handle(e)
    }
}

onMounted(load)
</script>

<template>
    <div v-if="error" class="error">{{ error }}</div>
    <p v-if="notice" class="muted">{{ notice }}</p>

    <div class="card">
        <h3 style="margin-top:0">Підрозділи</h3>
        <p class="muted">
            Це той самий список, з якого працівник обирає підрозділ, подаючи заявку в боті.
        </p>

        <form class="filters" @submit.prevent="create">
            <input v-model="name" type="text" placeholder="Назва підрозділу, напр. Цех №1" required />
            <button class="primary" type="submit" :disabled="saving || !name.trim()">Додати</button>
        </form>
    </div>

    <div class="card">
        <div v-if="loading" class="center">Завантаження…</div>
        <div v-else-if="!departments.length" class="center muted">
            Підрозділів ще немає — додайте перший у формі вище.
        </div>

        <div v-else class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Підрозділ</th>
                    <th>Заявок</th>
                    <th>Людей</th>
                    <th>Стан</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="department in departments" :key="department.id">
                    <td class="wrap">
                        <input type="text" :value="department.name" @change="rename(department, $event)" />
                    </td>
                    <td>{{ department.requests }}</td>
                    <td>{{ department.people }}</td>
                    <td>
                        <span class="badge" :class="department.active ? 'badge-in_stock' : 'badge-waiting'">
                            {{ department.active ? 'у списку' : 'прихований' }}
                        </span>
                    </td>
                    <td class="row">
                        <button @click="toggle(department)">
                            {{ department.active ? 'Сховати' : 'Повернути' }}
                        </button>
                        <button class="danger" @click="remove(department)">Видалити</button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p class="muted">
        Підрозділ, на якому вже висять заявки чи люди, не зникає назовсім: «Видалити» прибере його зі
        списку вибору, а історія лишиться цілою.
    </p>
</template>
