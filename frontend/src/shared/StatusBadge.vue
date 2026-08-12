<script setup lang="ts">
defineProps<{ status: string; label: string }>()
</script>

<template>
    <span class="badge" :class="`badge-${status}`">{{ label }}</span>
</template>
