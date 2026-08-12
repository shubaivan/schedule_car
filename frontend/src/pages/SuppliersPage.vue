<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, type ApiSupplier, type SupplierPayload } from '../app/api'
import { useSession } from '../app/store'

const session = useSession()
const suppliers = ref<ApiSupplier[]>([])
const query = ref('')
const onlyActive = ref(false)
const error = ref('')
const loading = ref(true)
const saving = ref(false)

const blank = (): SupplierPayload => ({ name: '', edrpou: '', phone: '', contactPerson: '' })
const draft = ref<SupplierPayload>(blank())

async function load() {
    error.value = ''

    try {
        suppliers.value = (await api.suppliers({ q: query.value, active: onlyActive.value })).items
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        loading.value = false
    }
}

async function create() {
    if (!draft.value.name?.trim() || saving.value) return

    error.value = ''
    saving.value = true

    try {
        await api.createSupplier(draft.value)
        draft.value = blank()
        await load()
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        saving.value = false
    }
}

/** Правка поля просто в таблиці: зберігаємо на blur, як у списку людей. */
async function update(supplier: ApiSupplier, payload: SupplierPayload) {
    error.value = ''

    try {
        Object.assign(supplier, await api.updateSupplier(supplier.id, payload))
    } catch (e) {
        error.value = session.handle(e)
        // Сервер відмовив — показуємо те, що насправді в базі.
        await load()
    }
}

function edited(supplier: ApiSupplier, field: keyof ApiSupplier, event: Event) {
    const value = (event.target as HTMLInputElement).value.trim()

    if (value === (supplier[field] ?? '')) return

    update(supplier, { [field]: value || null })
}

onMounted(load)
</script>

<template>
    <div v-if="error" class="error">{{ error }}</div>

    <div class="card">
        <div class="filters">
            <input
                v-model="query"
                type="search"
                placeholder="Пошук: назва, ЄДРПОУ, контакт"
                @keyup.enter="load"
                @search="load"
            />
            <label class="flag">
                <input v-model="onlyActive" type="checkbox" @change="load" />
                лише активні
            </label>
            <button @click="load">Оновити</button>
        </div>

        <form class="filters" @submit.prevent="create">
            <input v-model="draft.name" type="text" placeholder="ФОП Петренко О.П." required />
            <input v-model="draft.edrpou" type="text" placeholder="ЄДРПОУ / ІПН" />
            <input v-model="draft.phone" type="text" placeholder="Телефон" />
            <input v-model="draft.contactPerson" type="text" placeholder="Контактна особа" />
            <button class="primary" type="submit" :disabled="saving || !draft.name?.trim()">
                Додати постачальника
            </button>
        </form>
    </div>

    <div class="card">
        <div v-if="loading" class="center">Завантаження…</div>
        <div v-else-if="!suppliers.length" class="center muted">
            Постачальників ще немає — додайте першого у формі вище.
        </div>

        <div v-else class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Постачальник</th>
                    <th>ЄДРПОУ / ІПН</th>
                    <th>Телефон</th>
                    <th>Контактна особа</th>
                    <th>Стан</th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="supplier in suppliers" :key="supplier.id">
                    <td class="wrap">
                        <input type="text" :value="supplier.name" @change="edited(supplier, 'name', $event)" />
                    </td>
                    <td>
                        <input type="text" :value="supplier.edrpou ?? ''" @change="edited(supplier, 'edrpou', $event)" />
                    </td>
                    <td>
                        <input type="text" :value="supplier.phone ?? ''" @change="edited(supplier, 'phone', $event)" />
                    </td>
                    <td class="wrap">
                        <input
                            type="text"
                            :value="supplier.contactPerson ?? ''"
                            @change="edited(supplier, 'contactPerson', $event)"
                        />
                    </td>
                    <td>
                        <button
                            :class="supplier.active ? 'danger' : ''"
                            @click="update(supplier, { active: !supplier.active })"
                        >
                            {{ supplier.active ? 'Прибрати' : 'Повернути' }}
                        </button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p class="muted">
        Постачальників не видаляємо — прибраний зникає зі списку вибору, але лишається в історії закупівель.
    </p>
</template>
