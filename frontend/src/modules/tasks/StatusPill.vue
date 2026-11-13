<script setup lang="ts">
import { STATUS_TONE } from './format'

defineProps<{ status: string; label: string }>()
</script>

<template>
    <span class="t-status" :class="'tone-' + (STATUS_TONE[status] ?? 'slate')">{{ label }}</span>
</template>
