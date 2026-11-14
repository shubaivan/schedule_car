<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, type NewRequestPayload, type RequestListResponse } from '../../app/api'
import { useSession } from '../../app/store'
import StatusBadge from '../../shared/StatusBadge.vue'
import { formatDate } from '../../shared/format'

const session = useSession()
const router = useRouter()

/*
 * «➕ Нова заявка» прямо в CRM. У боті заявку подають з телефона на об'єкті,
 * тут — з-за столу; заявка та сама (той самий сервіс, номер і сповіщення).
 */
const creating = ref(false)
const saving = ref(false)
const formError = ref('')
const units = computed(() => session.meta?.units ?? [])
const blank = (): NewRequestPayload => ({
    item: '',
    quantity: '',
    unit: 'piece',
    needBy: '',
    urgent: false,
    site: '',
    note: '',
    departmentId: session.user?.departmentId ?? null,
})
const draft = ref<NewRequestPayload>(blank())

function openForm() {
    draft.value = blank()
    formError.value = ''
    creating.value = true
}

async function submit() {
    if (saving.value) return

    formError.value = ''
    saving.value = true

    try {
        const created = await api.createRequest(draft.value)
        creating.value = false
        router.push({ name: 'request', params: { id: created.id } })
    } catch (e) {
        formError.value = session.handle(e)
    } finally {
        saving.value = false
    }
}

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

    <div class="new-request-bar">
        <button v-if="!creating" class="primary" @click="openForm">➕ Нова заявка</button>
    </div>

    <form v-if="creating" class="card new-request" @submit.prevent="submit">
        <h2>➕ Нова заявка</h2>
        <div v-if="formError" class="error">{{ formError }}</div>
        <div class="form-grid">
            <label class="wide">Що потрібно *
                <input v-model="draft.item" type="text" required placeholder="Цемент М500, арматура Ø12, фанера 18 мм…" autofocus>
            </label>
            <label>Кількість *
                <input v-model="draft.quantity" type="text" inputmode="decimal" required placeholder="10">
            </label>
            <label>Одиниця
                <select v-model="draft.unit">
                    <option v-for="u in units" :key="u.value" :value="u.value">{{ u.label }}</option>
                </select>
            </label>
            <label>Для якого об'єкта / куди
                <input v-model="draft.site" type="text" placeholder="ЖК, цех, адреса">
            </label>
            <label>Підрозділ
                <select v-model="draft.departmentId">
                    <option :value="null">—</option>
                    <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select>
            </label>
            <label>Потрібно до
                <input v-model="draft.needBy" type="date">
            </label>
            <label class="row urgent"><input v-model="draft.urgent" type="checkbox"> терміново</label>
            <label class="wide">Примітка
                <textarea v-model="draft.note" rows="2" placeholder="Марка, розміри, постачальник, якщо відомий…"></textarea>
            </label>
        </div>
        <div class="row actions">
            <button class="primary" type="submit" :disabled="saving || !draft.item.trim() || !draft.quantity.trim()">
                {{ saving ? 'Зберігаю…' : 'Подати заявку' }}
            </button>
            <button type="button" @click="creating = false">Скасувати</button>
        </div>
        <p class="muted small">Менеджер отримає сповіщення в боті, як і про заявку, подану з телефона.</p>
    </form>

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
        <div v-else-if="!data?.items.length" class="center">
            <template v-if="status || department || accent || search || urgent || overdue">Заявок за цим фільтром немає.</template>
            <template v-else>
                <p>Заявок ще немає.</p>
                <p class="muted">
                    Натисніть «➕ Нова заявка» вгорі — або в боті «📦 Постачання» → «➕ Нова заявка»,
                    якщо ви на об'єкті з телефоном.
                </p>
            </template>
        </div>

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

<style scoped>
.new-request-bar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: .75rem;
}

.new-request h2 {
    margin: 0 0 .75rem;
    font-size: 18px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: .75rem 1rem;
}

.form-grid label {
    display: flex;
    flex-direction: column;
    gap: .25rem;
    font-size: 13px;
    color: var(--text-muted);
}

.form-grid .wide {
    grid-column: 1 / -1;
}

.form-grid label.urgent {
    flex-direction: row;
    align-items: center;
    align-self: end;
    color: var(--text);
    font-size: 15px;
}

.actions {
    margin-top: 1rem;
    gap: .5rem;
}

.small {
    font-size: 13px;
}

@media (max-width: 640px) {
    .new-request-bar button {
        width: 100%;
        min-height: 44px;
    }

    .form-grid input, .form-grid select, .form-grid textarea {
        font-size: 16px;
    }
}
</style>
