<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, type RequestListResponse } from '../../app/api'
import { useSession } from '../../app/store'
import StatusBadge from '../../shared/StatusBadge.vue'
import { formatDate } from '../../shared/format'

const session = useSession()

const data = ref<RequestListResponse | null>(null)
const error = ref('')
const loading = ref(false)

const status = ref('')
const accent = ref('')
const department = ref('')
const search = ref('')
const urgent = ref(false)
const overdue = ref(false)
const page = ref(1)

const statuses = computed(() => session.meta?.statuses ?? [])
// Мітки менеджера: без «порожньої» — її роль грає кнопка «Усі».
const accents = computed(() => (session.meta?.accents ?? []).filter((item) => item.value !== 'none'))
const departments = computed(() => session.meta?.departments ?? [])
const pages = computed(() => (data.value ? Math.ceil(data.value.total / data.value.pageSize) : 1))

async function load() {
    loading.value = true
    error.value = ''

    try {
        data.value = await api.requests({
            status: status.value ? [status.value] : undefined,
            department: department.value || undefined,
            accent: accent.value || undefined,
            q: search.value || undefined,
            urgent: urgent.value,
            overdue: overdue.value,
            page: page.value,
        })
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        loading.value = false
    }
}

// Будь-яка зміна фільтра завжди повертає на першу сторінку.
watch([status, department, accent, urgent, overdue], () => {
    page.value = 1
    load()
})

let searchTimer: number | undefined
watch(search, () => {
    window.clearTimeout(searchTimer)
    searchTimer = window.setTimeout(() => {
        page.value = 1
        load()
    }, 300)
})

watch(page, load)

onMounted(load)
</script>

<template>
    <div v-if="error" class="error">{{ error }}</div>

    <div class="filters">
        <div class="tabs">
            <button class="tab" :class="{ 'is-active': status === '' }" @click="status = ''">
                Усі<span class="count">{{ data?.total ?? '' }}</span>
            </button>
            <button
                v-for="item in statuses"
                :key="item.value"
                class="tab"
                :class="{ 'is-active': status === item.value }"
                @click="status = item.value"
            >
                {{ item.emoji }} {{ item.label }}
                <span class="count">{{ data?.counts?.[item.value] ?? 0 }}</span>
            </button>
        </div>
    </div>

    <div class="filters">
        <input v-model="search" type="search" placeholder="Пошук: номер, матеріал, об'єкт">
        <select v-model="department">
            <option value="">Усі підрозділи</option>
            <option v-for="item in departments" :key="item.id" :value="item.id">{{ item.name }}</option>
        </select>
        <select v-model="accent">
            <option value="">Будь-яка мітка</option>
            <option v-for="item in accents" :key="item.value" :value="item.value">
                {{ item.emoji }} {{ item.label }}
            </option>
        </select>
        <label class="row"><input v-model="urgent" type="checkbox"> терміново</label>
        <label class="row"><input v-model="overdue" type="checkbox"> прострочені</label>
    </div>

    <div class="card">
        <div v-if="loading" class="center">Завантаження…</div>
        <div v-else-if="!data?.items.length" class="center">Заявок за цим фільтром немає.</div>

        <div v-else class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Мітка</th>
                    <th>№</th>
                    <th>Матеріал</th>
                    <th>Кількість</th>
                    <th>Потрібно до</th>
                    <th>Заявник</th>
                    <th>Підрозділ</th>
                    <th>Статус</th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="item in data.items" :key="item.id">
                    <td :title="item.accent === 'none' ? '' : item.accentLabel">{{ item.accentEmoji || '·' }}</td>
                    <td>
                        <router-link :to="{ name: 'request', params: { id: item.id } }">{{ item.number }}</router-link>
                    </td>
                    <td class="wrap">
                        {{ item.item }}
                        <span v-if="item.urgent" class="muted quiet">терміново</span>
                    </td>
                    <td>{{ item.quantityLabel }}</td>
                    <td>
                        {{ item.needBy ? formatDate(item.needBy) : '—' }}
                        <span v-if="item.overdue" class="flag">прострочено</span>
                    </td>
                    <td>{{ item.author.name }}</td>
                    <td>{{ item.department ?? '—' }}</td>
                    <td><StatusBadge :status="item.status" :label="item.statusLabel" /></td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div v-if="pages > 1" class="row">
        <button :disabled="page <= 1" @click="page--">← Назад</button>
        <span class="muted">{{ page }} з {{ pages }}</span>
        <button :disabled="page >= pages" @click="page++">Далі →</button>
    </div>
</template>
