<script setup lang="ts">
import { useRoute } from 'vue-router'

/** Розділи складу — рядок вкладок над списком (як «Відкриті / Мої» у заявок, але це адреси). */
const route = useRoute()
const on = (prefix: string) => String(route.name).startsWith(prefix)
</script>

<template>
    <nav class="w-nav p-chips" aria-label="Розділи складу">
        <router-link :to="{ name: 'warehouse-items' }" class="p-chip" :class="{ on: on('warehouse-item') }">Позиції</router-link>
        <router-link :to="{ name: 'warehouse-movements' }" class="p-chip" :class="{ on: on('warehouse-movement') }">Рухи</router-link>
        <router-link :to="{ name: 'warehouse-journal' }" class="p-chip" :class="{ on: on('warehouse-journal') }">Журнал</router-link>
    </nav>
</template>
