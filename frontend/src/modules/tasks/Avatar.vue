<script setup lang="ts">
import { computed } from 'vue'
import type { Person } from './api'

const props = withDefaults(defineProps<{ person: Person | null; size?: number }>(), { size: 32 })
const style = computed(() => ({
    width: props.size + 'px',
    height: props.size + 'px',
    fontSize: Math.round(props.size * 0.38) + 'px',
    background: props.person?.color ?? '#94a3b8',
}))
</script>

<template>
    <span class="t-ava" :style="style" :title="person ? person.name + (person.position ? ' · ' + person.position : '') : ''">{{ person?.initials ?? '?' }}</span>
</template>
