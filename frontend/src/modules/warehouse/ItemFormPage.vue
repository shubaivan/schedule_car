<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, type ApiSupplier } from '../../app/api'
import { useSession } from '../../app/store'
import Icon from '../tasks/shell/Icon.vue'
import SupplierPicker from '../../shared/SupplierPicker.vue'
import { whApi, type ItemPayload, type WhMeta } from './api'
import { accessError } from './access'
import { goBack } from '../../plan/layers'
import './warehouse.css'

/**
 * Нова позиція (/sklad/items/new) і правка (/sklad/items/:id/edit) — сторінкою.
 * Перевіряє WarehouseDirectory::saveItem — ті самі правила, що й Twig-форма:
 * номер за префіксом категорії, облік не міняється після першого руху, списання — лише рухом.
 */
const props = defineProps<{ id?: string }>()
const session = useSession()
const router = useRouter()

const meta = ref<WhMeta | null>(null)
const suppliers = ref<ApiSupplier[]>([])
const supplierId = ref(0)
const error = ref('')
const saving = ref(false)
const ready = ref(false)
const draft = ref<ItemPayload>({
    name: '', categoryId: null, tracking: 'bulk', unit: 'шт', inventoryNumber: '', description: '', serialNumber: '',
    supplierId: '', purchasePrice: '', purchasedAt: '', rentalRate: '', state: 'active', attributes: [],
})
const editing = computed(() => !!props.id)
const category = computed(() => meta.value?.categories.find((c) => c.id === draft.value.categoryId) ?? null)
const back = computed(() => (props.id ? { name: 'warehouse-items', params: { id: props.id } } : { name: 'warehouse-items' }))

/** Підказки характеристик з категорії: порожні рядки під кожну, яку ще не заповнили. */
watch(category, (c) => {
    if (!c) return
    for (const name of c.attributes) if (!draft.value.attributes.some((a) => a.name === name)) draft.value.attributes.push({ name, value: '' })
})

async function submit() {
    if (saving.value) return
    error.value = ''
    saving.value = true
    try {
        const payload = { ...draft.value, supplierId: supplierId.value ? String(supplierId.value) : '' }
        const saved = props.id ? await whApi.updateItem(Number(props.id), payload) : await whApi.createItem(payload)
        whApi.meta(true).catch(() => undefined)
        // Правка — назад до картки, з якої прийшли (без другого такого ж кроку історії);
        // нова позиція — форму в історії заміняє її картка.
        if (props.id) goBack(router, back.value)
        else router.replace({ name: 'warehouse-items', params: { id: String(saved.id) } })
    } catch (e) {
        error.value = accessError(e) || session.handle(e)
    } finally {
        saving.value = false
    }
}

onMounted(async () => {
    try {
        meta.value = await whApi.meta()
        if (props.id) {
            const i = await whApi.item(Number(props.id))
            draft.value = {
                name: i.name, categoryId: i.category.id, tracking: i.tracking, unit: i.unit, inventoryNumber: i.inventoryNumber,
                description: i.description ?? '', serialNumber: i.serialNumber ?? '', supplierId: '', purchasePrice: i.purchasePrice ?? '',
                purchasedAt: i.purchasedAt ?? '', rentalRate: i.rentalRate ?? '', state: i.state,
                attributes: Object.entries(i.attributes).map(([name, value]) => ({ name, value })),
            }
            supplierId.value = i.supplier?.id ?? 0
        }
        ready.value = true
    } catch (e) {
        error.value = accessError(e) || session.handle(e)
    }
    api.suppliers({ active: true }).then((r) => (suppliers.value = r.items)).catch(() => undefined)
})
</script>

<template>
    <div class="t-page w-form-page">
        <form class="t-panel w-form" @submit.prevent="submit">
            <div class="w-form-head">
                <button type="button" class="p-icon-btn" title="Назад" aria-label="Назад" @click="goBack(router, back)"><Icon name="back" /></button>
                <h1>{{ editing ? 'Змінити позицію' : 'Нова позиція' }}</h1>
            </div>
            <div v-if="!ready" class="t-empty">{{ error || 'Завантаження…' }}</div>
            <template v-else-if="meta">
                <label class="w-field"><span>Назва *</span><input v-model="draft.name" class="t-input" type="text" required placeholder="напр. Кільце колодязне КС 10-9" /></label>
                <div class="t-two">
                    <label class="w-field">
                        <span>Категорія *</span>
                        <select v-model.number="draft.categoryId" class="t-select" required>
                            <option :value="null" disabled>Оберіть</option>
                            <option v-for="c in meta.categories.filter((c) => c.active || c.id === draft.categoryId)" :key="c.id" :value="c.id">{{ c.emoji }} {{ c.name }}</option>
                        </select>
                    </label>
                    <label class="w-field"><span>Інв. номер</span><input v-model="draft.inventoryNumber" class="t-input" type="text" :placeholder="category?.next ?? 'з категорії'" /></label>
                </div>
                <div class="t-two">
                    <label class="w-field">
                        <span>Облік</span>
                        <select v-model="draft.tracking" class="t-select">
                            <option v-for="t in meta.trackings" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </label>
                    <label class="w-field">
                        <span>Одиниця</span>
                        <select v-model="draft.unit" class="t-select" :disabled="draft.tracking === 'unit'">
                            <option v-for="u in meta.units" :key="u" :value="u">{{ u }}</option>
                        </select>
                    </label>
                </div>
                <div class="w-attrs">
                    <b class="p-small">Характеристики</b>
                    <div v-for="(a, i) in draft.attributes" :key="i" class="t-two">
                        <input v-model="a.name" class="t-input" type="text" placeholder="Назва (Марка бетону)" :aria-label="'Характеристика ' + (i + 1)" />
                        <input v-model="a.value" class="t-input" type="text" placeholder="Значення (В25)" :aria-label="'Значення ' + (i + 1)" />
                    </div>
                    <button type="button" class="p-link" @click="draft.attributes.push({ name: '', value: '' })">+ Характеристика</button>
                </div>
                <div class="w-field"><span>Постачальник / виробник</span><SupplierPicker v-model="supplierId" :suppliers="suppliers" @created="suppliers.push($event)" @error="error = $event" /></div>
                <div class="t-two">
                    <label class="w-field"><span>Ціна за од., ₴</span><input v-model="draft.purchasePrice" class="t-input" type="text" inputmode="decimal" /></label>
                    <label class="w-field"><span>Оренда, ₴/доба</span><input v-model="draft.rentalRate" class="t-input" type="text" inputmode="decimal" /></label>
                </div>
                <div class="t-two">
                    <label class="w-field"><span>Куплено</span><input v-model="draft.purchasedAt" class="t-input" type="date" /></label>
                    <label class="w-field"><span>Серійний №</span><input v-model="draft.serialNumber" class="t-input" type="text" /></label>
                </div>
                <label v-if="draft.state !== 'written_off'" class="w-field">
                    <span>Стан</span>
                    <select v-model="draft.state" class="t-select">
                        <option v-for="s in meta.states.filter((s) => s.value !== 'written_off')" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </label>
                <label class="w-field"><span>Опис</span><textarea v-model="draft.description" class="t-textarea" rows="3" /></label>

                <div v-if="error" class="t-error" role="alert">{{ error }}</div>
                <div class="t-actions">
                    <button type="submit" class="t-btn primary" :disabled="saving">{{ saving ? 'Зберігаю…' : editing ? 'Зберегти' : 'Створити позицію' }}</button>
                    <button type="button" class="t-btn ghost" :disabled="saving" @click="goBack(router, back)">Скасувати</button>
                </div>
            </template>
        </form>
    </div>
</template>
