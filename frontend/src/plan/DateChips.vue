<script setup lang="ts">
/**
 * Дата для форм «Плану» — однакова в задачі, заявці й русі складу: швидкі чипи
 * («Сьогодні», «Завтра»…) і поле дати поруч. Значення — YYYY-MM-DD або ''.
 */
const props = withDefaults(defineProps<{ modelValue: string; quick?: { label: string; days: number }[]; label?: string; clearable?: boolean }>(), {
    quick: () => [
        { label: 'Сьогодні', days: 0 },
        { label: 'Завтра', days: 1 },
        { label: '3 дні', days: 3 },
        { label: 'Тиждень', days: 7 },
    ],
    label: 'Дата',
    clearable: true,
})
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

function iso(days: number): string {
    const d = new Date()
    d.setDate(d.getDate() + days)
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function pick(days: number) {
    const value = iso(days)
    emit('update:modelValue', props.modelValue === value && props.clearable ? '' : value)
}
</script>

<template>
    <button v-for="q in quick" :key="q.days" type="button" class="p-chip" :class="{ on: modelValue === iso(q.days) }" @click="pick(q.days)">{{ q.label }}</button>
    <input :value="modelValue" type="date" class="p-date" :aria-label="label" @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)" />
</template>
