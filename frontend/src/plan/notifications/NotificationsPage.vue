<script setup lang="ts">
import PersonName from '../PersonName.vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { notificationsApi, type PlanNotification } from './api'
import { useNotifications } from './store'
import { useSession } from '../../app/store'

/**
 * Центр сповіщень — окрема сторінка (не модалка): усе, що сталося в задачах і
 * заявках, де я «в темі». ?unread=1 — лише непрочитані; клік — прочитано й
 * перехід на задачу/заявку. Нове з'являється зверху саме (живий канал).
 */
const store = useNotifications()
const session = useSession()
const route = useRoute()
const router = useRouter()
const items = ref<PlanNotification[]>([])
const next = ref<number | null>(null)
const loading = ref(false)
const error = ref('')
const unreadOnly = computed(() => route.query.unread === '1')
let controller: AbortController | null = null

async function load(more = false) {
    controller?.abort()
    controller = new AbortController()
    loading.value = true
    error.value = ''
    try {
        const page = await notificationsApi.page({ unread: unreadOnly.value, before: more ? next.value : null }, controller.signal)
        items.value = more ? [...items.value, ...page.items] : page.items
        next.value = page.next
        store.unread = page.unread
    } catch (e) {
        if ((e as Error).name !== 'AbortError') error.value = session.handle(e)
    } finally {
        loading.value = false
    }
}

async function open(item: PlanNotification) {
    void store.markRead(item).catch(() => undefined)
    await router.push(item.url.startsWith('/crm/') ? item.url.slice(4) : item.url)
}

async function readAll() {
    await store.markAll()
    items.value = unreadOnly.value ? [] : items.value.map((i) => ({ ...i, readAt: i.readAt ?? new Date().toISOString() }))
}

const ICONS: Record<string, string> = { mention: '@', approval: '⚖', status: '↻', comment: '💬', assigned: '＋', purchase: '₴', warehouse: '📦', lead: '★' }

function when(iso: string): string {
    const d = new Date(iso)
    const mins = Math.round((Date.now() - d.getTime()) / 60000)
    if (mins < 1) return 'щойно'
    if (mins < 60) return `${mins} хв тому`
    const sameDay = d.toDateString() === new Date().toDateString()
    return sameDay ? d.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString('uk-UA', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' })
}

watch(unreadOnly, () => load())
// Нова подія, поки сторінка відкрита, — перечитати першу сторінку.
watch(() => store.lastEvent?.event, (e, old) => e && e !== old && load())
// Лічильник виріс без події (подія прийшла, поки канал ще підключався) — теж перечитати.
watch(() => store.unread, (n, old) => n > old && !loading.value && load())
onMounted(() => load())
onBeforeUnmount(() => controller?.abort())
</script>

<template>
    <div class="p-notif">
        <header class="p-notif-bar">
            <div class="p-seg" role="tablist" aria-label="Фільтр">
                <router-link :to="{ name: 'notifications' }" :class="{ on: !unreadOnly }" role="tab" :aria-selected="!unreadOnly">Усі</router-link>
                <router-link :to="{ name: 'notifications', query: { unread: '1' } }" :class="{ on: unreadOnly }" role="tab" :aria-selected="unreadOnly">Непрочитані<template v-if="store.unread"> · {{ store.unread }}</template></router-link>
            </div>
            <button type="button" class="p-btn" :disabled="!store.unread" data-testid="read-all" @click="readAll">Прочитати всі</button>
            <router-link :to="{ name: 'notifications-settings' }" class="p-btn" data-testid="notif-settings">Налаштування</router-link>
        </header>

        <p class="p-notif-hint" data-testid="notif-hint">
            Тут — лише те, що потребує вас: згадки, нові задачі й заявки на вас, потрібне ваше рішення, файли, якими з вами поділились.
            Коментарі, статуси й рухи складу — у <router-link :to="{ name: 'tasks-chronicle' }">Хроніці</router-link> з «+N».
            <router-link :to="{ name: 'notifications-settings' }">Змінити</router-link>
        </p>
        <p v-if="error" class="p-notif-error" role="alert">{{ error }}</p>

        <ul class="p-notif-list" data-testid="notif-list">
            <li v-for="item in items" :key="item.id">
                <button type="button" class="p-notif-row" :class="{ unread: !item.readAt, important: item.important }" @click="open(item)">
                    <span class="p-notif-ico" :data-group="item.group" aria-hidden="true">{{ ICONS[item.group] ?? '•' }}</span>
                    <span class="p-notif-body">
                        <span class="p-notif-head"><b><template v-if="item.actor && item.headline.startsWith(item.actor.name)"><PersonName :person="item.actor" :link="false" />{{ item.headline.slice(item.actor.name.length) }}</template><template v-else>{{ item.headline }}</template></b><time :datetime="item.createdAt">{{ when(item.createdAt) }}</time></span>
                        <span class="p-notif-title">{{ item.title }}</span>
                        <span v-if="item.detail" class="p-notif-detail">{{ item.detail }}</span>
                    </span>
                    <span v-if="!item.readAt" class="p-notif-dot" aria-label="непрочитане" />
                </button>
            </li>
        </ul>

        <div v-if="!loading && !items.length && !error" class="p-notif-empty">
            <b>{{ unreadOnly ? 'Усе прочитано' : 'Сповіщень поки немає' }}</b>
            <span>Тут з'являтимуться згадки, нові задачі й заявки на вас, погодження й файли, якими з вами поділились.</span>
        </div>
        <button v-if="next" type="button" class="p-btn p-notif-more" :disabled="loading" @click="load(true)">Показати ще</button>
        <p v-if="loading && !items.length" class="p-muted">Завантаження…</p>
    </div>
</template>

<style>
.p-notif { max-width: 860px; margin: 0 auto; display: flex; flex-direction: column; gap: 12px; }
.p-notif-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.p-notif-bar .p-seg { display: flex; gap: 2px; padding: 3px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; margin-right: auto; }
.p-notif-bar .p-seg a { padding: 5px 12px; border-radius: 7px; font-weight: 650; font-size: 13.5px; color: var(--text-muted); text-decoration: none; }
.p-notif-bar .p-seg a.on { background: var(--surface); color: var(--text); box-shadow: var(--t-shadow); }
.p-notif-list { list-style: none; margin: 0; padding: 0; background: var(--surface); border: 1px solid var(--border); border-radius: var(--p-r, 10px); overflow: hidden; }
.p-notif-list li + li { border-top: 1px solid var(--border); }
.p-notif-row { width: 100%; display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border: 0; background: none; color: var(--text); text-align: left; cursor: pointer; font: inherit; }
.p-notif-row:hover { background: var(--surface-2); }
.p-notif-row.unread { background: var(--accent-soft); }
.p-notif-row.important { box-shadow: inset 4px 0 0 #f59e0b; }
.p-notif-row.important.unread { background: #fef3c7; color: #1f2937; }
.p-notif-ico { flex: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--surface-2); border: 1px solid var(--border); font-weight: 700; font-size: 14px; }
.p-notif-ico[data-group="mention"], .p-notif-ico[data-group="approval"], .p-notif-ico[data-group="lead"] { background: #fde68a; border-color: #f59e0b; color: #78350f; }
.p-notif-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.p-notif-head { display: flex; gap: 8px; justify-content: space-between; align-items: baseline; }
.p-notif-head time { flex: none; color: var(--text-muted); font-size: 12px; }
.p-notif-title { font-size: 13.5px; color: var(--link); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.p-notif-row.important.unread .p-notif-title { color: #1e40af; }
.p-notif-detail { font-size: 13.5px; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.p-notif-row.important.unread .p-notif-detail { color: #4b5563; }
.p-notif-dot { flex: none; width: 9px; height: 9px; margin-top: 6px; border-radius: 50%; background: var(--accent); }
.p-notif-empty { display: flex; flex-direction: column; gap: 6px; align-items: center; text-align: center; padding: 40px 16px; color: var(--text-muted); }
.p-notif-empty b { color: var(--text); font-size: 15px; }
.p-notif-more { align-self: center; }
.p-notif-error { color: var(--danger); margin: 0; }
.p-notif-hint { margin: 0; font-size: 13px; color: var(--text-muted); line-height: 1.45; }
.p-notif-hint a { color: var(--link, var(--accent)); }
@media (max-width: 760px) {
    .p-notif-bar .p-seg { flex: 1 1 100%; margin-right: 0; }
    .p-notif-bar .p-seg a { flex: 1; text-align: center; }
    .p-notif-bar .p-btn { flex: 1; }
    .p-notif-row { padding: 12px; gap: 10px; }
}
</style>
