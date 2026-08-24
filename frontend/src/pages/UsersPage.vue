<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, type ApiUser, type StaffPhone } from '../app/api'
import { useSession } from '../app/store'
import { formatDateTime } from '../shared/format'

const session = useSession()
const users = ref<ApiUser[]>([])
const error = ref('')
const notice = ref('')
const loading = ref(true)
const withArchived = ref(false)

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

// Ті, хто ще чекає рішення, — окремим списком угорі: це найчастіша робота
// менеджера на цьому екрані.
const pending = computed(() => users.value.filter((user) => user.accessStatus === 'pending'))

async function load() {
    try {
        const [people, phones] = await Promise.all([api.users(withArchived.value), api.staff()])
        users.value = people.items
        staff.value = phones.items
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        loading.value = false
    }
}

async function update(user: ApiUser, payload: Parameters<typeof api.updateUser>[1]) {
    error.value = ''
    notice.value = ''

    try {
        Object.assign(user, await api.updateUser(user.id, payload))
    } catch (e) {
        error.value = session.handle(e)
        // Сервер відмовив — показуємо те, що насправді в базі.
        await load()
    }
}

/** Правка поля просто в таблиці: зберігаємо на change, як у постачальниках. */
function edited(user: ApiUser, field: 'firstName' | 'lastName', event: Event) {
    const value = (event.target as HTMLInputElement).value.trim()

    if (value === (user[field] ?? '')) return

    update(user, { [field]: value || null })
}

async function decide(user: ApiUser, status: 'approved' | 'rejected') {
    error.value = ''
    notice.value = ''

    try {
        Object.assign(user, await api.decideAccess(user.id, status))
        notice.value = status === 'approved'
            ? `${user.name} — доступ відкрито, бот уже написав про це.`
            : `${user.name} — доступ закрито.`
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function remove(user: ApiUser) {
    error.value = ''
    notice.value = ''

    try {
        notice.value = (await api.deleteUser(user.id)).message
        await load()
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function restore(user: ApiUser) {
    error.value = ''
    notice.value = ''

    try {
        Object.assign(user, await api.restoreUser(user.id))
        await load()
    } catch (e) {
        error.value = session.handle(e)
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
    <p v-if="notice" class="muted">{{ notice }}</p>

    <div v-if="pending.length" class="card">
        <h3 style="margin-top:0">Чекають підтвердження</h3>
        <p class="muted">Ці люди зареєструвались у боті й чекають, поки їм відкриють доступ.</p>

        <div class="table-wrap">
            <table>
                <tbody>
                <tr v-for="user in pending" :key="user.id">
                    <td class="wrap">{{ user.name }}</td>
                    <td>{{ user.phone ?? '—' }}</td>
                    <td>{{ user.department ?? 'без підрозділу' }}</td>
                    <td class="row">
                        <button class="primary" @click="decide(user, 'approved')">Погодити</button>
                        <button class="danger" @click="decide(user, 'rejected')">Відхилити</button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="row" style="justify-content: space-between">
            <h3 style="margin:0">Люди в системі</h3>
            <label class="row muted">
                <input v-model="withArchived" type="checkbox" @change="load" />
                показати прибраних
            </label>
        </div>

        <p class="muted" style="margin-top:.5rem">
            Ім'я, роль і підрозділ правляться просто в таблиці. Телефон не змінюється: саме за ним людина
            чіпляється до свого запису під час реєстрації в боті.
        </p>

        <div v-if="loading" class="center">Завантаження…</div>

        <div v-else class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Ім'я</th>
                    <th>Прізвище</th>
                    <th>Телефон</th>
                    <th>Доступ</th>
                    <th>Роль</th>
                    <th>Підрозділ</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="user in users" :key="user.id">
                    <td>
                        <input type="text" :value="user.firstName ?? ''" @change="edited(user, 'firstName', $event)" />
                    </td>
                    <td>
                        <input type="text" :value="user.lastName ?? ''" @change="edited(user, 'lastName', $event)" />
                    </td>
                    <td class="muted">{{ user.phone ?? '—' }}</td>
                    <td>
                        <span class="badge" :class="`badge-access-${user.accessStatus}`">
                            {{ user.accessStatusLabel }}
                        </span>
                        <div class="row" style="margin-top:.35rem">
                            <button v-if="user.accessStatus !== 'approved'" @click="decide(user, 'approved')">
                                Погодити
                            </button>
                            <button v-if="user.accessStatus !== 'rejected'" @click="decide(user, 'rejected')">
                                Відхилити
                            </button>
                        </div>
                    </td>
                    <td>
                        <select
                            :value="user.role"
                            @change="update(user, { role: ($event.target as HTMLSelectElement).value })"
                        >
                            <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                        </select>
                    </td>
                    <td>
                        <select
                            :value="user.departmentId ?? ''"
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
                    <td>
                        <button v-if="user.archived" @click="restore(user)">Повернути</button>
                        <button v-else class="danger" @click="remove(user)">Прибрати</button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <p class="muted" style="margin-top:.75rem">
            Прибрана людина зникає зі списку й більше не заходить у бота. Якщо на ній висять заявки, запис
            лишається в історії — його видно за галочкою «показати прибраних».
        </p>
    </div>

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
</template>
