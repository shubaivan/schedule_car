<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, type ApiUser, type StaffPhone } from '../app/api'
import { useSession } from '../app/store'
import { formatDateTime } from '../shared/format'

const session = useSession()
const users = ref<ApiUser[]>([])
const error = ref('')
const loading = ref(true)

const departments = computed(() => session.meta?.departments ?? [])
const roles = [
    { value: 'worker', label: 'Робітник' },
    { value: 'manager', label: 'Менеджер із постачання' },
    { value: 'director', label: 'Директор' },
    { value: 'admin', label: 'Адміністратор' },
]

// Довідник телефонів: роль видається сама, щойно людина зайде в бота.
const staff = ref<StaffPhone[]>([])
const draft = ref({ phone: '', name: '', role: 'worker', note: '' })
const saving = ref(false)

async function load() {
    try {
        const [people, phones] = await Promise.all([api.users(), api.staff()])
        users.value = people.items
        staff.value = phones.items
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        loading.value = false
    }
}

async function update(user: ApiUser, payload: { role?: string; departmentId?: number | null }) {
    error.value = ''

    try {
        Object.assign(user, await api.updateUser(user.id, payload))
    } catch (e) {
        error.value = session.handle(e)
        await load()
    }
}

async function addPhone() {
    if (!draft.value.phone.trim()) return

    saving.value = true
    error.value = ''

    try {
        await api.saveStaff({ ...draft.value })
        draft.value = { phone: '', name: '', role: 'worker', note: '' }
        await load()
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        saving.value = false
    }
}

async function removePhone(id: number) {
    error.value = ''

    try {
        await api.deleteStaff(id)
        await load()
    } catch (e) {
        error.value = session.handle(e)
    }
}

onMounted(load)
</script>

<template>
    <div v-if="error" class="error">{{ error }}</div>

    <p v-if="!session.isAdmin()" class="muted">
        Змінювати ролі та підрозділи вже зареєстрованих людей може лише адміністратор.
    </p>

    <div class="card">
        <h3 style="margin-top:0">Довідник телефонів</h3>
        <p class="muted">
            Внесіть номер і роль — людина отримає її автоматично, щойно поділиться контактом у боті.
            Якщо вона вже зареєстрована, роль видається одразу.
        </p>

        <div class="table-wrap" v-if="staff.length">
            <table>
                <thead>
                <tr>
                    <th>Телефон</th>
                    <th>Хто</th>
                    <th>Роль</th>
                    <th>Стан</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="entry in staff" :key="entry.id">
                    <td>{{ entry.phone }}</td>
                    <td class="wrap">
                        {{ entry.name ?? '—' }}
                        <br v-if="entry.note">
                        <span v-if="entry.note" class="muted">{{ entry.note }}</span>
                    </td>
                    <td>{{ entry.roleLabel }}</td>
                    <td class="wrap">
                        <template v-if="entry.appliedTo">
                            видано: {{ entry.appliedTo.name }}<br>
                            <span class="muted">{{ formatDateTime(entry.appliedAt!) }}</span>
                        </template>
                        <span v-else class="muted">чекає на реєстрацію</span>
                    </td>
                    <td>
                        <button class="danger" :disabled="saving" @click="removePhone(entry.id)">Прибрати</button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <div class="filters" style="margin-top:.75rem">
            <input v-model="draft.phone" type="text" placeholder="Телефон, напр. 0671112233" />
            <input v-model="draft.name" type="text" placeholder="Чий номер" />
            <select v-model="draft.role">
                <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
            </select>
            <input v-model="draft.note" type="text" placeholder="Примітка" />
            <button class="primary" :disabled="saving || !draft.phone.trim()" @click="addPhone">Додати</button>
        </div>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Люди в системі</h3>

        <div v-if="loading" class="center">Завантаження…</div>

        <div v-else class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Людина</th>
                    <th>Телефон</th>
                    <th>Доступ</th>
                    <th>Роль</th>
                    <th>Підрозділ</th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="user in users" :key="user.id">
                    <td class="wrap">{{ user.name }}</td>
                    <td>{{ user.phone ?? '—' }}</td>
                    <td>
                        <span class="badge" :class="`badge-access-${user.accessStatus}`">
                            {{ user.accessStatusLabel }}
                        </span>
                    </td>
                    <td>
                        <select
                            :value="user.role"
                            :disabled="!session.isAdmin()"
                            @change="update(user, { role: ($event.target as HTMLSelectElement).value })"
                        >
                            <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                        </select>
                    </td>
                    <td>
                        <select
                            :value="user.departmentId ?? ''"
                            :disabled="!session.isAdmin()"
                            @change="update(user, {
                                departmentId: ($event.target as HTMLSelectElement).value
                                    ? Number(($event.target as HTMLSelectElement).value)
                                    : null,
                            })"
                        >
                            <option value="">— без підрозділу —</option>
                            <option v-for="item in departments" :key="item.id" :value="item.id">{{ item.name }}</option>
                        </select>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
