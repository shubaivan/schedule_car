<script setup lang="ts">
import { computed } from 'vue'
import { useNotifications } from './store'

/** Дзвіночок із лічильником непрочитаних — для рейки, мобільної шапки, вкладок. */
withDefaults(defineProps<{ size?: number; label?: boolean }>(), { size: 20, label: false })
const store = useNotifications()
const badge = computed(() => (store.unread > 99 ? '99+' : String(store.unread)))
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0" />
    </svg>
    <span v-if="label" class="p-rail-long">Сповіщення</span>
    <b v-if="store.unread" class="p-badge" data-testid="bell-badge" :aria-label="store.unread + ' непрочитаних сповіщень'">{{ badge }}</b>
</template>
