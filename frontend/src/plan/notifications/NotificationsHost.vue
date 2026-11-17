<script setup lang="ts">
import { clearDrafts } from '../../modules/zvyazok/api'
import { onBeforeUnmount, onMounted, watch, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import Toast from '../Toast.vue'
import { notificationsApi } from './api'
import { forgetPushOnLogout } from './push'
import { useNotifications } from './store'

/**
 * Живі сповіщення у вкладці: запускає канал (Mercure / опитування), показує
 * жовті тости, тримає лічильник у заголовку вкладки «(N) План» і позначає
 * прочитаними сповіщення суб'єкта, який людина щойно відкрила.
 */
const store = useNotifications()
const route = useRoute()
const pageTitle = document.title

/** «Вийти» (звичайне посилання /crm/logout): спершу відв'язати push цього браузера, щоб чужі сповіщення сюди не йшли. */
async function onClick(e: MouseEvent) {
    const a = (e.target as HTMLElement | null)?.closest?.('a[href="/crm/logout"]')
    if (!a || e.defaultPrevented || e.button !== 0) return
    e.preventDefault()
    clearDrafts()
    await Promise.race([forgetPushOnLogout(), new Promise((r) => setTimeout(r, 1500))])
    window.location.href = '/crm/logout'
}

onMounted(() => {
    void store.start()
    document.addEventListener('click', onClick, true)
})
onBeforeUnmount(() => {
    store.stop()
    document.removeEventListener('click', onClick, true)
    document.title = pageTitle
})

watchEffect(() => {
    const n = store.unread
    document.title = (n ? `(${n > 99 ? '99+' : n}) ` : '') + 'План'
})

/** Маршрут → ключ суб'єкта, що відкритий (задача в split / на весь екран, заявка). */
function openedSubject(): string | null {
    const id = Number(route.params.id)
    if (!id) return null
    if (['tasks-board', 'task-full', 'tasks-chronicle', 'tasks-search'].includes(String(route.name))) return `task-${id}`
    if (['request', 'supply-board', 'supply-full'].includes(String(route.name))) return `supply-${id}`
    return null
}

let lastRead = ''
watch(
    () => [route.name, route.params.id, store.lastEvent?.event],
    async () => {
        const key = openedSubject()
        const fresh = store.lastEvent && key === `${store.lastEvent.subject.type}-${store.lastEvent.subject.id}`
        if (!key || (key === lastRead && !fresh) || !store.unread) return
        lastRead = key
        try {
            store.unread = (await notificationsApi.readSubject(key)).unread
        } catch {
            /* не критично */
        }
    },
    { immediate: true },
)
</script>

<template>
    <Toast :toasts="store.toasts" @dismiss="store.dismiss" />
</template>
