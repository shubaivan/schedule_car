<script setup lang="ts">
import Avatar from './Avatar.vue'
import type { Person } from './api'

withDefaults(defineProps<{ people: Person[]; total?: number; max?: number; size?: number }>(), { max: 4, size: 26 })
</script>

<template>
    <span class="t-stack">
        <Avatar v-for="p in people.slice(0, max)" :key="p.id" :person="p" :size="size" />
        <span v-if="(total ?? people.length) > max" class="t-more">+{{ (total ?? people.length) - max }}</span>
    </span>
</template>
