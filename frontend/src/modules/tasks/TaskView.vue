<script setup lang="ts">
import { vStickyHead } from '../../plan/stickyHead'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Avatar from './Avatar.vue'
import FileViewer from './FileViewer.vue'
import TaskChat from './TaskChat.vue'
import TaskHeader from './TaskHeader.vue'
import { tasksApi, type TaskDetail, type TasksMeta } from './api'
import { dueLabel } from './format'
import { TASK_SAVED } from './shell/nav'
import { refreshChronicleCounts } from './chronicle'
import { extClass } from './files'
import { useTasks } from './store'
import { useSession } from '../../app/store'
import { closeLayer, openLayer } from '../../plan/layers'
import { takeFlash } from '../../plan/flash'

/** embedded — права половина розділеного екрана (кнопка «закрити» замість «← Дошка»). */
const props = defineProps<{ id: string; embedded?: boolean }>()
const emit = defineEmits<{ close: [] }>()
const session = useSession()
const store = useTasks()
const route = useRoute()
const router = useRouter()

const task = ref<TaskDetail | null>(null)
const meta = ref<TasksMeta | null>(null)
const error = ref('')
const notFound = ref(false)
/** Банер на сторінці (замість alert): напр. «файли не завантажились» після створення. */
const banner = ref<string | null>(null)
const tab = ref<'chat' | 'details' | 'files'>('chat')
const moving = ref(false)
/** Права панель (деталі, учасники, файли), коли їй не вистачає місця поруч із чатом. */
const sideOpen = ref(false)
const chatRef = ref<InstanceType<typeof TaskChat> | null>(null)

const files = computed(() => task.value?.files ?? [])

/**
 * Відкритий файл — у адресі (?f=12), не в стані компонента: «Назад» закриває
 * переглядач, посилання з пошуку відкриває одразу файл, ←/→ міняють ?f= без нових кроків.
 */
const viewer = computed(() => {
    const id = Number(route.query.f)
    return id && files.value.some((f) => f.id === id) ? id : null
})
const openFile = (id: number) => openLayer(router, route, 'f', id)
const closeFile = () => closeLayer(router, route, 'f')
const showFile = (id: number) => router.replace({ query: { ...route.query, f: String(id) } })
const edit = () => task.value && router.push({ name: 'task-edit', params: { id: String(task.value.id) } })

async function load() {
    try {
        meta.value = await store.load()
        // Гортаючи ↑/↓, відповіді можуть прийти не по черзі — показуємо лише останню.
        const want = props.id
        const loaded = await tasksApi.get(Number(want))
        if (want !== props.id) return
        task.value = loaded
        error.value = ''
        notFound.value = false
        tab.value = 'chat'
        banner.value = takeFlash('task-' + loaded.id)
        // Відкрита задача стала прочитаною — бейдж «Хроніки» оновлюємо одразу.
        refreshChronicleCounts()
    } catch (e) {
        const msg = session.handle(e)
        if (/40[34]|не учасник/i.test(msg)) notFound.value = true
        error.value = msg
    }
}

/** Статус змінився деінде — перечитуємо задачу, стрічку лишаємо на місці. */
async function reload() {
    if (!task.value) return
    try {
        const fresh = await tasksApi.get(task.value.id)
        fresh.readUpTo = task.value.readUpTo
        task.value = fresh
    } catch {
        /* наступне опитування спробує ще */
    }
}

async function toggleFavorite() {
    if (!task.value) return
    const on = !task.value.favorite
    task.value.favorite = on
    try {
        await tasksApi.favorite(task.value.id, on)
        window.dispatchEvent(new Event(TASK_SAVED))
    } catch (e) {
        task.value.favorite = !on
        error.value = session.handle(e)
    }
}

async function move(to: string, note?: string) {
    if (!task.value) return
    moving.value = true
    try {
        const fresh = await tasksApi.status(task.value.id, to, note)
        fresh.readUpTo = task.value.readUpTo
        task.value = fresh
        window.dispatchEvent(new Event(TASK_SAVED))
        await nextTick()
        chatRef.value?.scrollDown(true)
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        moving.value = false
    }
}

watch(() => props.id, load)
// ?f= на файл, якого немає (видалили, чуже посилання) — прибираємо з адреси, щоб не глушив клавіші.
watch([() => route.query.f, task], () => {
    if (task.value && route.query.f && viewer.value === null) router.replace({ query: { ...route.query, f: undefined } })
})
// Телефон: панель вкладки починається одразу під смугою вкладок — прокрутку стрічки скидаємо.
watch(tab, (t) => {
    if (t !== 'chat' && window.matchMedia('(max-width: 760px)').matches) window.scrollTo({ top: 0 })
})
onMounted(load)
</script>

<template>
    <div class="t-page" :class="{ 'p-embedded': embedded }">
        <div v-if="notFound" class="t-panel t-empty">
            <p>Задачу не знайдено або ви не її учасник.</p>
            <button v-if="embedded" type="button" class="t-btn" @click="emit('close')">← Назад</button>
            <router-link v-else :to="{ name: 'tasks-board' }" class="t-btn">← До дошки</router-link>
        </div>
        <div v-else-if="!task" class="t-empty">{{ error || 'Завантаження…' }}</div>

        <div v-else class="t-task" :class="{ 'side-tab': tab !== 'chat' }">
            <section v-sticky-head class="t-task-main">
                <TaskHeader
                    :task="task"
                    :embedded="embedded"
                    :moving="moving"
                    :side-open="sideOpen"
                    @move="move"
                    @edit="edit"
                    @close="emit('close')"
                    @side="sideOpen = !sideOpen"
                    @favorite="toggleFavorite"
                />

                <div v-if="banner" class="t-error p-banner" role="status">{{ banner }} <button type="button" class="p-link" @click="banner = null">Зрозуміло</button></div>
                <div v-if="error" class="t-error" style="margin: 0 1rem">{{ error }}</div>
                <div class="t-mobile-tabs">
                    <button type="button" :class="{ on: tab === 'chat' }" @click="tab = 'chat'">💬 Чат</button>
                    <button type="button" :class="{ on: tab === 'details' }" @click="tab = 'details'">ℹ️ Деталі</button>
                    <button type="button" :class="{ on: tab === 'files' }" @click="tab = 'files'">📎 Файли · {{ files.length }}</button>
                </div>

                <TaskChat :key="task.id" ref="chatRef" :task="task" :meta="meta" :hidden="tab !== 'chat'" @open="openFile" @reload="reload" />
            </section>

            <aside class="t-side" :class="{ 'hide-mobile': tab === 'chat', open: sideOpen }">
                <div v-if="tab !== 'files'" class="t-panel">
                    <h2>Деталі</h2>
                    <dl class="t-dl">
                        <dt>Контрагент</dt><dd>{{ task.client ?? '—' }}</dd>
                        <dt>Термін</dt><dd><span :class="{ 't-due over': task.overdue }">{{ task.dueAt ? new Date(task.dueAt).toLocaleDateString('uk-UA') + ' · ' + dueLabel(task.dueAt) : '—' }}</span></dd>
                        <dt>Постановник</dt><dd class="t-person"><Avatar :person="task.creator" :size="22" /> {{ task.creator.name }}</dd>
                        <dt>Створено</dt><dd>{{ new Date(task.createdAt).toLocaleDateString('uk-UA') }}</dd>
                        <dt v-if="task.departments.length">Відділи</dt>
                        <dd v-if="task.departments.length" class="p-deps-dd"><span v-for="d in task.departments" :key="d.id" class="p-dep">[{{ d.name }}]</span></dd>
                    </dl>
                    <template v-if="task.description">
                        <h2 style="margin-top: 1rem">Опис</h2>
                        <div class="t-desc">{{ task.description }}</div>
                    </template>
                </div>
                <div v-if="tab !== 'files'" class="t-panel">
                    <h2>Учасники <span class="count">{{ task.members.length }}</span></h2>
                    <div class="t-people">
                        <div v-for="p in task.members" :key="p.id" class="t-person">
                            <Avatar :person="p" :size="32" />
                            <div style="min-width: 0">
                                <div class="n">{{ p.name }}</div>
                                <div class="p">{{ p.position ?? p.roleLabel }}<template v-if="p.via"> · через «{{ p.via }}»</template></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="tab !== 'details'" class="t-panel">
                    <h2>Файли задачі <span class="count">{{ files.length }}</span></h2>
                    <div v-if="files.length" class="t-gallery">
                        <button v-for="f in files" :key="f.id" type="button" :title="f.name" @click="openFile(f.id)">
                            <img v-if="f.urls.thumb" :src="f.urls.thumb" :alt="f.name" loading="lazy" />
                            <span v-else class="ico" :class="extClass(f)">{{ f.ext }}</span>
                            <span v-if="f.kind !== 'image'" class="badge" :class="extClass(f)">{{ f.ext }}</span>
                        </button>
                    </div>
                    <div v-else class="t-empty">Файлів ще немає — додайте їх у чат.</div>
                </div>
            </aside>
        </div>

        <FileViewer v-if="viewer !== null && task" :files="files" :start-id="viewer" @close="closeFile" @change="showFile" />
    </div>
</template>
