<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, type ApiUser } from '../app/api'
import { useSession } from '../app/store'

const session = useSession()
const users = ref<ApiUser[]>([])
const error = ref('')
const loading = ref(true)

const departments = computed(() => session.meta?.departments ?? [])
const roles = [
    { value: 'worker', label: 'Робітник' },
    { value: 'manager', label: 'Менеджер із постачання' },
    { value: 'admin', label: 'Адміністратор' },
]

async function load() {
    try {
        users.value = (await api.users()).items
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

onMounted(load)
</script>

<template>
    <div v-if="error" class="error">{{ error }}</div>

    <p v-if="!session.isAdmin()" class="muted">
        Змінювати ролі та підрозділи може лише адміністратор.
    </p>

    <div class="card">
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
