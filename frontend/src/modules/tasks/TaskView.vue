<script setup lang="ts">
import { computed, nextTick, ref, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import Avatar from './Avatar.vue'
import FileViewer from './FileViewer.vue'
import LabelChip from './LabelChip.vue'
import StatusPill from './StatusPill.vue'
import TaskChat from './TaskChat.vue'
import TaskForm from './TaskForm.vue'
import { tasksApi, type TaskDetail, type TasksMeta } from './api'
import { dueLabel } from './format'
import { extClass } from './files'
import { useTasks } from './store'
import { useSession } from '../../app/store'

const props = defineProps<{ id: string }>()
const session = useSession()
const store = useTasks()
const route = useRoute()

const task = ref<TaskDetail | null>(null)
const meta = ref<TasksMeta | null>(null)
const error = ref('')
const notFound = ref(false)
const viewer = ref<number | null>(null)
const editing = ref(false)
const tab = ref<'chat' | 'details' | 'files'>('chat')
const decisionNote = ref('')
const moving = ref(false)
const chatRef = ref<InstanceType<typeof TaskChat> | null>(null)

const files = computed(() => task.value?.files ?? [])
const transitions = computed(() => task.value?.can.transitions ?? [])
const decisions = computed(() => transitions.value.filter((t) => t.decision))
const moves = computed(() => transitions.value.filter((t) => !t.decision))

async function load() {
    try {
        meta.value = await store.load()
        task.value = await tasksApi.get(Number(props.id))
        error.value = ''
        // Із пошуку по файлах — одразу відкрити файл.
        const file = Number(route.query.f)
        if (file && task.value.files.some((f) => f.id === file)) viewer.value = file
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
    } catch (e) {
        task.value.favorite = !on
        error.value = session.handle(e)
    }
}

async function move(to: string, withNote = false) {
    if (!task.value) return
    moving.value = true
    try {
        const fresh = await tasksApi.status(task.value.id, to, withNote ? decisionNote.value : undefined)
        fresh.readUpTo = task.value.readUpTo
        task.value = fresh
        decisionNote.value = ''
        await nextTick()
        chatRef.value?.scrollDown(true)
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        moving.value = false
    }
}

function saved(updated: TaskDetail) {
    updated.readUpTo = task.value?.readUpTo ?? null
    task.value = updated
}

watch(() => props.id, load)
onMounted(load)
</script>

<template>
    <div class="t-page">
        <div v-if="notFound" class="t-panel t-empty">
            <p>Задачу не знайдено або ви не її учасник.</p>
            <router-link :to="{ name: 'tasks-board' }" class="t-btn">← До дошки</router-link>
        </div>
        <div v-else-if="!task" class="t-empty">{{ error || 'Завантаження…' }}</div>

        <div v-else class="t-task">
            <section class="t-task-main">
                <div class="t-task-head">
                    <div class="crumbs">
                        <router-link :to="{ name: 'tasks-board' }">← Дошка</router-link>
                        <span>·</span>
                        <span>№ {{ task.number }}</span>
                        <span>·</span>
                        <StatusPill :status="task.status" :label="task.statusLabel" />
                        <span v-if="task.dueAt" class="t-due" :class="{ over: task.overdue }">⏰ {{ dueLabel(task.dueAt) }}</span>
                    </div>
                    <h1>
                        {{ task.title }}
                        <button type="button" class="f-fav" :class="{ on: task.favorite }" :title="task.favorite ? 'Прибрати з обраного' : 'В обране'" :aria-pressed="task.favorite" @click="toggleFavorite">{{ task.favorite ? '★' : '☆' }}</button>
                    </h1>
                    <div v-if="task.labels.length" class="t-labels">
                        <LabelChip v-for="l in task.labels" :key="l.id" :label="l" />
                    </div>

                    <div v-if="decisions.length" class="t-decision">
                        <span>⚖️ Рішення директора</span>
                        <input v-model="decisionNote" class="t-input" style="flex: 1 1 200px; font-size: 14px; padding: .4rem .6rem" placeholder="Коментар до рішення (необовʼязково)" />
                        <div class="t-actions">
                            <button
                                v-for="d in decisions"
                                :key="d.value"
                                type="button"
                                class="t-btn small"
                                :class="d.value === 'approved' ? 'primary' : d.value === 'rejected' ? 'danger' : ''"
                                :disabled="moving"
                                @click="move(d.value, true)"
                            >{{ d.action }}</button>
                        </div>
                    </div>
                    <div v-else-if="task.awaitsDecision" class="t-decision">⏳ Чекає рішення директора</div>

                    <div v-if="moves.length || task.can.edit" class="t-actions">
                        <button v-for="t in moves" :key="t.value" type="button" class="t-btn small" :class="{ primary: t.value === 'in_work' || t.value === 'done' }" :disabled="moving" @click="move(t.value)">
                            {{ t.action }}
                        </button>
                        <button v-if="task.can.edit" type="button" class="t-btn small ghost" @click="editing = true">✏️ Редагувати</button>
                    </div>
                </div>

                <div v-if="error" class="t-error" style="margin: 0 1rem">{{ error }}</div>
                <div class="t-mobile-tabs">
                    <button type="button" :class="{ on: tab === 'chat' }" @click="tab = 'chat'">💬 Чат</button>
                    <button type="button" :class="{ on: tab === 'details' }" @click="tab = 'details'">ℹ️ Деталі</button>
                    <button type="button" :class="{ on: tab === 'files' }" @click="tab = 'files'">📎 Файли · {{ files.length }}</button>
                </div>

                <TaskChat ref="chatRef" :task="task" :meta="meta" :hidden="tab !== 'chat'" @open="viewer = $event" @reload="reload" />
            </section>

            <aside class="t-side" :class="{ 'hide-mobile': tab === 'chat' }">
                <div v-if="tab !== 'files'" class="t-panel">
                    <h2>Деталі</h2>
                    <dl class="t-dl">
                        <dt>Контрагент</dt><dd>{{ task.client ?? '—' }}</dd>
                        <dt>Термін</dt><dd><span :class="{ 't-due over': task.overdue }">{{ task.dueAt ? new Date(task.dueAt).toLocaleDateString('uk-UA') + ' · ' + dueLabel(task.dueAt) : '—' }}</span></dd>
                        <dt>Постановник</dt><dd class="t-person"><Avatar :person="task.creator" :size="22" /> {{ task.creator.name }}</dd>
                        <dt>Створено</dt><dd>{{ new Date(task.createdAt).toLocaleDateString('uk-UA') }}</dd>
                        <dt v-if="task.departments.length">Відділи</dt>
                        <dd v-if="task.departments.length">{{ task.departments.map((d) => d.name).join(', ') }}</dd>
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
                        <button v-for="f in files" :key="f.id" type="button" :title="f.name" @click="viewer = f.id">
                            <img v-if="f.urls.thumb" :src="f.urls.thumb" :alt="f.name" loading="lazy" />
                            <span v-else class="ico" :class="extClass(f)">{{ f.ext }}</span>
                            <span v-if="f.kind !== 'image'" class="badge" :class="extClass(f)">{{ f.ext }}</span>
                        </button>
                    </div>
                    <div v-else class="t-empty">Файлів ще немає — додайте їх у чат.</div>
                </div>
            </aside>
        </div>

        <FileViewer v-if="viewer !== null && task" :files="files" :start-id="viewer" @close="viewer = null" />
        <TaskForm v-if="editing && task" :task="task" @close="editing = false" @saved="saved" />
    </div>
</template>
