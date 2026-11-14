<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, type NewRequestPayload } from '../../app/api'
import { useSession } from '../../app/store'
import Icon from '../tasks/shell/Icon.vue'
import { goBack } from '../../plan/layers'

/**
 * «➕ Нова заявка» — окремою сторінкою /supply/new, а не формою над таблицею:
 * «Назад» на телефоні закриває її, як будь-яке вікно. Заявка та сама, що з бота
 * (той самий сервіс, номер і сповіщення). На стенді заявок сторінка в старій шапці
 * й веде у стару картку; у «Плані» — у його оболонці й відкриває заявку праворуч від списку.
 */
const session = useSession()
const router = useRouter()
const plan = computed(() => session.user?.home === 'tasks')
const list = computed(() => (plan.value ? { name: 'supply-board' } : { name: 'requests' }))

const saving = ref(false)
const error = ref('')
const itemBox = ref<HTMLInputElement | null>(null)
const units = computed(() => session.meta?.units ?? [])
const departments = computed(() => session.meta?.departments ?? [])
const draft = ref<NewRequestPayload>({
    item: '',
    quantity: '',
    unit: 'piece',
    needBy: '',
    urgent: false,
    site: '',
    note: '',
    departmentId: session.user?.departmentId ?? null,
})

function cancel() {
    goBack(router, list.value)
}

async function submit() {
    if (saving.value) return
    error.value = ''
    saving.value = true
    try {
        const created = await api.createRequest(draft.value)
        // Форму в історії заміняє сама заявка: «Назад» із заявки веде до списку, а не знову у форму.
        router.replace(plan.value ? { name: 'supply-board', params: { id: String(created.id) } } : { name: 'request', params: { id: String(created.id) } })
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        saving.value = false
    }
}

onMounted(async () => {
    await nextTick()
    itemBox.value?.focus()
})
</script>

<template>
    <div :class="plan ? 'p-formpage' : 'supply-formpage'">
        <p v-if="!plan"><router-link :to="list">← До списку</router-link></p>

        <form class="new-request" :class="plan ? 't-formcard p-form p-form-page' : 'card'" aria-labelledby="new-request-title" @submit.prevent="submit" @keydown.esc="cancel">
            <header v-if="plan">
                <button type="button" class="p-icon-btn p-form-back" aria-label="Назад" @click="cancel"><Icon name="back" /></button>
                <h2 id="new-request-title">Нова заявка</h2>
                <button type="button" class="p-icon-btn p-form-x" aria-label="Закрити" title="Закрити (Esc)" @click="cancel"><Icon name="x" /></button>
            </header>
            <h2 v-else id="new-request-title">➕ Нова заявка</h2>

            <div :class="{ body: plan }">
                <div v-if="error" class="error">{{ error }}</div>
                <div class="form-grid">
                    <label class="wide">Що потрібно *
                        <input ref="itemBox" v-model="draft.item" type="text" required placeholder="Цемент М500, арматура Ø12, фанера 18 мм…">
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
                <p class="muted small">Менеджер отримає сповіщення в боті, як і про заявку, подану з телефона.</p>
            </div>

            <footer v-if="plan">
                <button type="button" class="p-btn ghost" @click="cancel">Скасувати</button>
                <button class="p-btn primary" type="submit" :disabled="saving || !draft.item.trim() || !draft.quantity.trim()">{{ saving ? 'Зберігаю…' : 'Подати заявку' }}</button>
            </footer>
            <div v-else class="row actions">
                <button class="primary" type="submit" :disabled="saving || !draft.item.trim() || !draft.quantity.trim()">
                    {{ saving ? 'Зберігаю…' : 'Подати заявку' }}
                </button>
                <button type="button" @click="cancel">Скасувати</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.new-request h2 {
    margin: 0 0 .75rem;
    font-size: 18px;
}

.p-form-page h2 {
    margin: 0;
    font-size: 16px;
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
    .actions button {
        min-height: 44px;
    }

    .form-grid input, .form-grid select, .form-grid textarea {
        font-size: 16px;
    }
}
</style>
