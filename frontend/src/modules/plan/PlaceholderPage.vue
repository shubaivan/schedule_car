<script setup lang="ts">
import { computed } from 'vue'
import Icon from '../tasks/shell/Icon.vue'
import { useSession } from '../../app/store'

/**
 * Пункт меню «Плану», модуль якого ще будується (склад, сповіщення, журнал аудиту).
 * Меню вже ціле, а людина бачить чесне «ще не готово» і куди піти тим часом.
 */
const props = defineProps<{ kind: 'warehouse' | 'notifications' | 'admin' }>()
const session = useSession()

const PAGES = {
    warehouse: { icon: 'warehouse', title: 'Склад у «Плані»', text: 'Позиції, рухи й журнал складу переїжджають сюди — у той самий список і розділений екран, що й задачі та заявки.' },
    notifications: { icon: 'bell', title: 'Сповіщення', text: 'Тут буде стрічка всього, що стосується вас: задачі, заявки, склад і нові ліди — без перезавантаження сторінки.' },
    admin: { icon: 'shield', title: 'Журнал дій', text: 'Хто що створив, змінив, закрив, які файли додав і хто коли заходив — з фільтрами. Доступ — лише суперадміністраторам.' },
} as const

const page = computed(() => PAGES[props.kind])
</script>

<template>
    <div class="p-profile p-placeholder">
        <section class="p-card">
            <div class="p-placeholder-ico"><Icon :name="page.icon" :size="28" /></div>
            <h2>{{ page.title }}</h2>
            <p class="p-muted">{{ page.text }}</p>
            <p class="p-muted p-small">Розділ готується.</p>
            <p v-if="kind === 'warehouse' && session.user?.warehouse"><a href="/sklad" class="p-btn">Відкрити склад (поточна версія)</a></p>
            <p v-if="kind === 'notifications'"><router-link :to="{ name: 'tasks-chronicle' }" class="p-btn">Поки що — Хроніка</router-link></p>
        </section>
    </div>
</template>

<style>
.p-placeholder { margin: 24px auto; }
.p-placeholder .p-card { text-align: center; padding: 28px 20px; }
.p-placeholder h2 { margin: 8px 0; font-size: 18px; }
.p-placeholder-ico { width: 56px; height: 56px; margin: 0 auto; border-radius: 16px; display: flex; align-items: center; justify-content: center; background: var(--accent-soft); color: var(--accent); }
</style>
