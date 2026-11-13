<script setup lang="ts">
import type { Label } from './api'

defineProps<{ label: Label }>()
</script>

<template>
    <span class="t-label" :style="{ color: label.color, borderColor: label.color + '55', background: label.color + '14' }">
        <span :style="{ width: '7px', height: '7px', borderRadius: '2px', background: label.color }" />{{ label.name }}
    </span>
</template>
