<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Avatar from './Avatar.vue'
import AttachmentPreview from './AttachmentPreview.vue'
import FileViewer from './FileViewer.vue'
import TaskForm from './TaskForm.vue'
import TaskHeader from './TaskHeader.vue'
import { tasksApi, type Attachment, type Message, type Person, type TaskDetail, type TasksMeta } from './api'
import { dayLabel, dueLabel, fileSize, time } from './format'
import { TASK_SAVED } from './shell/nav'
import { extClass } from './files'
import { useTasks } from './store'
import { useSession } from '../../app/store'

/** embedded — права половина розділеного екрана дошки (кнопка «закрити» замість «← Дошка»). */
const props = defineProps<{ id: string; embedded?: boolean }>()
const emit = defineEmits<{ close: [] }>()
const session = useSession()
const store = useTasks()

const task = ref<TaskDetail | null>(null)
const meta = ref<TasksMeta | null>(null)
const error = ref('')
const notFound = ref(false)
const chat = ref<HTMLElement | null>(null)
const viewer = ref<number | null>(null)
const editing = ref(false)
const tab = ref<'chat' | 'details' | 'files'>('chat')
const moving = ref(false)
/** Права панель (деталі, учасники, файли), коли їй не вистачає місця поруч із чатом. */
const sideOpen = ref(false)

// --- поле вводу ---
const text = ref('')
const pending = ref<{ file: File; url: string | null }[]>([])
const mentions = ref<number[]>([])
const sending = ref(false)
const dragging = ref(false)
const box = ref<HTMLTextAreaElement | null>(null)
const mentionQuery = ref<string | null>(null)
const mentionIndex = ref(0)

const messages = computed(() => task.value?.messages ?? [])
const files = computed(() => task.value?.files ?? [])
const lastId = computed(() => messages.value.reduce((m, x) => Math.max(m, x.id), 0))

/** Повідомлення з розділювачами днів і злиттям підряд від однієї людини. */
const feed = computed(() => {
    const out: ({ type: 'day'; key: string; label: string } | { type: 'msg'; key: string; m: Message; cont: boolean })[] = []
    let day = ''
    let prev: Message | null = null
    for (const m of messages.value) {
        const d = m.createdAt.slice(0, 10)
        if (d !== day) {
            day = d
            out.push({ type: 'day', key: 'd' + d, label: dayLabel(m.createdAt) })
            prev = null
        }
        const cont = !!prev && prev.kind === 'comment' && m.kind === 'comment' && prev.author?.id === m.author?.id
            && new Date(m.createdAt).getTime() - new Date(prev.createdAt).getTime() < 5 * 60000
        out.push({ type: 'msg', key: 'm' + m.id, m, cont })
        prev = m
    }
    return out
})

const mentionList = computed<Person[]>(() => {
    if (mentionQuery.value === null || !meta.value) return []
    const q = mentionQuery.value.toLowerCase()
    return meta.value.people.filter((p) => p.name.toLowerCase().includes(q)).slice(0, 7)
})

function escape(s: string): string {
    return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!)
}

/** Текст повідомлення: екрановано, @згадки підсвічено. */
function render(m: Message): string {
    let html = escape(m.text)
    for (const id of m.mentions) {
        const p = store.person(id)
        if (p) html = html.split('@' + escape(p.name)).join(`<span class="mention">@${escape(p.name)}</span>`)
    }
    return html
}

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
        await nextTick()
        scrollDown(true)
    } catch (e) {
        const msg = session.handle(e)
        if (/40[34]|не учасник/i.test(msg)) notFound.value = true
        error.value = msg
    }
}

function nearBottom(): boolean {
    const el = chat.value
    return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 160
}

function scrollDown(force = false) {
    const el = chat.value
    if (!el) return
    if (window.matchMedia('(max-width: 760px)').matches) {
        if (force) window.scrollTo({ top: document.body.scrollHeight })
        return
    }
    if (force || nearBottom()) el.scrollTop = el.scrollHeight
}

// --- опитування: нові повідомлення й готові превʼю ---
let timer: number | undefined
async function poll() {
    if (!task.value || document.hidden) return
    const waiting = files.value.filter((f) => f.preview === 'pending').map((f) => f.id)
    try {
        const r = await tasksApi.poll(task.value.id, lastId.value, waiting)
        const stick = nearBottom()
        if (r.messages.length) {
            const known = new Set(messages.value.map((m) => m.id))
            const fresh = r.messages.filter((m) => !known.has(m.id))
            task.value.messages.push(...fresh)
            for (const m of fresh) task.value.files.push(...m.attachments)
        }
        for (const a of r.attachments) replaceAttachment(a)
        if (r.status !== task.value.status) task.value = await tasksApi.get(task.value.id)
        if (r.messages.length && stick) {
            await nextTick()
            scrollDown(true)
        }
    } catch {
        /* мережа моргнула — наступна спроба за кілька секунд */
    }
}

function replaceAttachment(a: Attachment) {
    if (!task.value) return
    for (const m of task.value.messages) {
        const i = m.attachments.findIndex((x) => x.id === a.id)
        if (i >= 0) m.attachments[i] = a
    }
    const j = task.value.files.findIndex((x) => x.id === a.id)
    if (j >= 0) task.value.files[j] = a
}

// --- надсилання ---
function addFiles(list: FileList | File[]) {
    for (const file of Array.from(list)) {
        pending.value.push({ file, url: file.type.startsWith('image/') ? URL.createObjectURL(file) : null })
    }
}
function removePending(i: number) {
    const [p] = pending.value.splice(i, 1)
    if (p?.url) URL.revokeObjectURL(p.url)
}
function pick(e: Event) {
    const input = e.target as HTMLInputElement
    if (input.files) addFiles(input.files)
    input.value = ''
}
function onPaste(e: ClipboardEvent) {
    if (e.clipboardData?.files.length) {
        addFiles(e.clipboardData.files)
        e.preventDefault()
    }
}
let dragDepth = 0
function dragEnter(e: DragEvent) {
    if (!e.dataTransfer?.types.includes('Files')) return
    dragDepth++
    dragging.value = true
}
function dragLeave() {
    dragDepth = Math.max(0, dragDepth - 1)
    if (!dragDepth) dragging.value = false
}
function drop(e: DragEvent) {
    dragDepth = 0
    dragging.value = false
    if (e.dataTransfer?.files.length) addFiles(e.dataTransfer.files)
}

async function send() {
    if (!task.value || sending.value) return
    if (!text.value.trim() && !pending.value.length) return
    sending.value = true
    error.value = ''
    try {
        const used = mentions.value.filter((id) => {
            const p = store.person(id)
            return p && text.value.includes('@' + p.name)
        })
        const message = await tasksApi.post(task.value.id, text.value, pending.value.map((p) => p.file), used)
        if (!task.value.messages.some((m) => m.id === message.id)) {
            task.value.messages.push(message)
            task.value.files.push(...message.attachments)
        }
        text.value = ''
        mentions.value = []
        pending.value.forEach((p) => p.url && URL.revokeObjectURL(p.url))
        pending.value = []
        await nextTick()
        autosize()
        scrollDown(true)
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        sending.value = false
    }
}

function autosize() {
    const el = box.value
    if (!el) return
    el.style.height = 'auto'
    el.style.height = Math.min(el.scrollHeight, 180) + 'px'
}

function onInput() {
    autosize()
    const el = box.value
    if (!el) return
    const before = el.value.slice(0, el.selectionStart ?? el.value.length)
    const match = before.match(/(?:^|\s)@([^\s@]{0,20})$/u)
    mentionQuery.value = match ? match[1] : null
    mentionIndex.value = 0
}

function chooseMention(p: Person) {
    const el = box.value
    if (!el) return
    const caret = el.selectionStart ?? text.value.length
    const before = text.value.slice(0, caret).replace(/@([^\s@]{0,20})$/u, '@' + p.name + ' ')
    text.value = before + text.value.slice(caret)
    if (!mentions.value.includes(p.id)) mentions.value.push(p.id)
    mentionQuery.value = null
    nextTick(() => {
        el.focus()
        el.setSelectionRange(before.length, before.length)
    })
}

function onKey(e: KeyboardEvent) {
    if (mentionList.value.length) {
        if (e.key === 'ArrowDown') { mentionIndex.value = (mentionIndex.value + 1) % mentionList.value.length; e.preventDefault(); return }
        if (e.key === 'ArrowUp') { mentionIndex.value = (mentionIndex.value + mentionList.value.length - 1) % mentionList.value.length; e.preventDefault(); return }
        if (e.key === 'Enter' || e.key === 'Tab') { chooseMention(mentionList.value[mentionIndex.value]); e.preventDefault(); return }
        if (e.key === 'Escape') { mentionQuery.value = null; return }
    }
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault()
        send()
    }
}

async function remove(m: Message) {
    if (!task.value || !confirm('Видалити повідомлення?')) return
    try {
        const updated = await tasksApi.deleteMessage(task.value.id, m.id)
        Object.assign(m, updated)
        task.value.files = task.value.files.filter((f) => f.messageId !== m.id)
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function move(to: string, note?: string) {
    if (!task.value) return
    moving.value = true
    try {
        task.value = await tasksApi.status(task.value.id, to, note)
        window.dispatchEvent(new Event(TASK_SAVED))
        await nextTick()
        scrollDown(true)
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        moving.value = false
    }
}

function saved(updated: TaskDetail) {
    task.value = updated
    window.dispatchEvent(new Event(TASK_SAVED))
}

watch(() => props.id, load)

onMounted(() => {
    load()
    timer = window.setInterval(poll, 4000)
})
onBeforeUnmount(() => {
    window.clearInterval(timer)
    pending.value.forEach((p) => p.url && URL.revokeObjectURL(p.url))
})
</script>

<template>
    <div class="t-page" :class="{ 'p-embedded': embedded }">
        <div v-if="notFound" class="t-panel t-empty">
            <p>Задачу не знайдено або ви не її учасник.</p>
            <button v-if="embedded" type="button" class="t-btn" @click="emit('close')">← До дошки</button>
            <router-link v-else :to="{ name: 'tasks-board' }" class="t-btn">← До дошки</router-link>
        </div>
        <div v-else-if="!task" class="t-empty">{{ error || 'Завантаження…' }}</div>

        <div v-else class="t-task">
            <section class="t-task-main">
                <TaskHeader
                    :task="task"
                    :embedded="embedded"
                    :moving="moving"
                    :side-open="sideOpen"
                    @move="move"
                    @edit="editing = true"
                    @close="emit('close')"
                    @side="sideOpen = !sideOpen"
                />

                <div class="t-mobile-tabs">
                    <button type="button" :class="{ on: tab === 'chat' }" @click="tab = 'chat'">💬 Чат</button>
                    <button type="button" :class="{ on: tab === 'details' }" @click="tab = 'details'">ℹ️ Деталі</button>
                    <button type="button" :class="{ on: tab === 'files' }" @click="tab = 'files'">📎 Файли · {{ files.length }}</button>
                </div>

                <div
                    ref="chat"
                    class="t-chat"
                    :class="{ 't-main-hide-mobile': tab !== 'chat' }"
                    @dragenter.prevent="dragEnter"
                    @dragover.prevent
                    @dragleave="dragLeave"
                    @drop.prevent="drop"
                >
                    <div v-if="dragging" class="t-drop">Відпустіть, щоб додати файли</div>
                    <template v-for="row in feed" :key="row.key">
                        <div v-if="row.type === 'day'" class="t-day">{{ row.label }}</div>
                        <div v-else-if="row.m.kind === 'event'" class="t-event">
                            <b>{{ row.m.author?.name ?? 'Система' }}</b> {{ row.m.text }} · {{ time(row.m.createdAt) }}
                        </div>
                        <div v-else class="t-msg" :class="{ mine: row.m.mine, cont: row.cont }">
                            <Avatar :person="row.m.author" :size="32" />
                            <div class="t-bubble" :class="{ deleted: row.m.deleted }">
                                <div v-if="!row.m.mine && !row.cont" class="who" :style="{ color: row.m.author?.color }">
                                    {{ row.m.author?.name }}<small>{{ row.m.author?.position }}</small>
                                </div>
                                <div v-if="row.m.deleted" class="txt">Повідомлення видалено</div>
                                <template v-else>
                                    <div v-if="row.m.text" class="txt" v-html="render(row.m)" />
                                    <AttachmentPreview v-if="row.m.attachments.length" :files="row.m.attachments" @open="viewer = $event" />
                                </template>
                                <div class="when">
                                    <button v-if="row.m.mine && !row.m.deleted" type="button" class="del" title="Видалити" @click="remove(row.m)">🗑</button>
                                    {{ time(row.m.createdAt) }}
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <form v-if="task.can.comment" class="t-composer" :class="{ 't-main-hide-mobile': tab !== 'chat' }" style="position: relative" @submit.prevent="send">
                    <div v-if="mentionList.length" class="t-mentions">
                        <button v-for="(p, i) in mentionList" :key="p.id" type="button" :class="{ on: i === mentionIndex }" @mousedown.prevent="chooseMention(p)">
                            <Avatar :person="p" :size="26" /> <span>{{ p.name }} <small>· {{ p.position ?? p.roleLabel }}</small></span>
                        </button>
                    </div>
                    <div v-if="error" class="t-error">{{ error }}</div>
                    <div v-if="pending.length" class="t-pending">
                        <div v-for="(p, i) in pending" :key="i" class="p" :title="p.file.name">
                            <button type="button" class="x" aria-label="Прибрати" @click="removePending(i)">✕</button>
                            <img v-if="p.url" :src="p.url" alt="" />
                            <template v-else>
                                <span class="eb" :class="extClass({ ext: p.file.name.split('.').pop() ?? '', kind: 'other' })">{{ (p.file.name.split('.').pop() ?? '').toUpperCase().slice(0, 4) }}</span>
                                <span class="n">{{ p.file.name }}</span>
                                <span>{{ fileSize(p.file.size) }}</span>
                            </template>
                        </div>
                    </div>
                    <div class="t-composer-row">
                        <label class="t-btn ghost icon" title="Додати файли">
                            📎<input type="file" multiple hidden @change="pick" />
                        </label>
                        <textarea
                            ref="box"
                            v-model="text"
                            class="t-textarea"
                            rows="1"
                            placeholder="Повідомлення… (@ — згадати)"
                            @input="onInput"
                            @keydown="onKey"
                            @paste="onPaste"
                        />
                        <button type="submit" class="t-btn primary icon" :disabled="sending || (!text.trim() && !pending.length)" title="Надіслати (Ctrl+Enter)">
                            {{ sending ? '…' : '➤' }}
                        </button>
                    </div>
                    <div class="t-hint t-desk">Ctrl+Enter — надіслати · файли можна перетягнути в чат або вставити з буфера</div>
                </form>
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
