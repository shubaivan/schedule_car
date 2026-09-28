<script setup lang="ts">
import { computed, ref } from 'vue'
import { api, type ApiSupplier } from '../app/api'

/**
 * Постачальник із довідника: пошук за назвою чи ЄДРПОУ, а немає — «➕ Додати»
 * прямо тут, без переходу в довідник. Та сама ідея, що й select2 на складі.
 * Дубль ловить сервер (SupplierDirectory) — і каже, хто вже є.
 */
const props = defineProps<{ modelValue: number; suppliers: ApiSupplier[] }>()
const emit = defineEmits<{
    'update:modelValue': [id: number]
    created: [supplier: ApiSupplier]
    error: [message: string]
}>()

const query = ref('')
const open = ref(false)
const creating = ref(false)

const norm = (s: string) => s.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '')

const selected = computed(() => props.suppliers.find((s) => s.id === props.modelValue) ?? null)

const matches = computed(() => {
    const q = norm(query.value)
    const list = q === ''
        ? props.suppliers
        : props.suppliers.filter((s) => norm(s.name).includes(q) || (s.edrpou ?? '').includes(query.value.trim()))

    return list.slice(0, 30)
})

// Нового пропонуємо, лише коли точно такої назви (без регістру й розділових) ще немає.
const canCreate = computed(() => {
    const q = norm(query.value)

    return q !== '' && !props.suppliers.some((s) => norm(s.name) === q)
})

function pick(supplier: ApiSupplier) {
    emit('update:modelValue', supplier.id)
    query.value = ''
    open.value = false
}

async function create() {
    creating.value = true

    try {
        const supplier = await api.createSupplier({ name: query.value.trim() })
        emit('created', supplier)
        pick(supplier)
    } catch (e) {
        emit('error', e instanceof Error ? e.message : String(e))
    } finally {
        creating.value = false
    }
}

function blur() {
    // Даємо кліку по варіанту спрацювати раніше, ніж список сховається.
    setTimeout(() => (open.value = false), 150)
}
</script>

<template>
    <div class="picker">
        <input
            :value="open ? query : (selected?.name ?? '')"
            type="text"
            placeholder="Постачальник — почніть вводити назву"
            autocomplete="off"
            @focus="open = true; query = ''"
            @input="query = ($event.target as HTMLInputElement).value"
            @blur="blur"
        />
        <ul v-if="open" class="options">
            <li v-for="item in matches" :key="item.id" :class="{ on: item.id === modelValue }" @mousedown.prevent="pick(item)">
                {{ item.name }}<span v-if="item.edrpou" class="muted"> · {{ item.edrpou }}</span>
            </li>
            <li v-if="canCreate" class="create" @mousedown.prevent="create">
                {{ creating ? 'Додаю…' : `➕ Додати «${query.trim()}» у довідник` }}
            </li>
            <li v-if="!matches.length && !canCreate" class="muted">Почніть вводити назву</li>
        </ul>
    </div>
</template>

<style scoped>
.picker { position: relative; min-width: 240px; flex: 1; }
.picker input { width: 100%; }
.options {
    position: absolute; z-index: 20; left: 0; right: 0; top: calc(100% + 2px);
    margin: 0; padding: 4px 0; list-style: none; max-height: 260px; overflow-y: auto;
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
    box-shadow: 0 6px 18px rgba(0, 0, 0, .12);
}
.options li { padding: 7px 10px; cursor: pointer; }
.options li:hover, .options li.on { background: var(--accent-soft); }
.options li.create { color: var(--accent); font-weight: 600; }
.options li.muted { cursor: default; }
</style>
