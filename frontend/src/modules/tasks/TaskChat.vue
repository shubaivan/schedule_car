<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Avatar from './Avatar.vue'
import MessageBubble from './MessageBubble.vue'
import type { Attachment, Message, Person, TasksMeta } from './api'
import { dayLabel, fileSize, time } from './format'
import { extClass } from './files'
import { useTasks } from './store'
import { taskFeed, type FeedApi, type FeedSubject } from './feedApi'
import { useSession } from '../../app/store'
import { flushUndo, undoable } from '../../plan/undo'
import { useLiveRefresh } from '../../plan/live'
import './feed.css'

/**
 * Стрічка задачі й поле вводу. Опитує сервер раз на кілька секунд: нові
 * повідомлення, правки/видалення/закріплення старих, готові превʼю, хто що прочитав.
 * Об'єкт задачі — від батька (TaskView), стрічку ми доповнюємо на місці.
 *
 * Той самий чат малює й заявку: api — адаптер стрічки (taskFeed / supplyFeed),
 * task — будь-що з полями FeedSubject. Видалення — з тостом «Скасувати», без вікна підтвердження.
 */
const props = defineProps<{ task: FeedSubject; meta: TasksMeta | null; hidden?: boolean; api?: FeedApi }>()
const emit = defineEmits<{ open: [fileId: number]; reload: [] }>()

const session = useSession()
const store = useTasks()
const route = useRoute()

const chat = ref<HTMLElement | null>(null)
const error = ref('')
const text = ref('')
const pending = ref<{ file: File; url: string | null }[]>([])
const mentions = ref<number[]>([])
const sending = ref(false)
const dragging = ref(false)
const box = ref<HTMLTextAreaElement | null>(null)
const mentionQuery = ref<string | null>(null)
const mentionIndex = ref(0)
const replyTo = ref<Message | null>(null)
const editing = ref<Message | null>(null)
const highlighted = ref<number | null>(null)
const pinIndex = ref(0)
/** Межа «Нові повідомлення» — фіксуємо при відкритті, щоб не стрибала. */
const unreadFrom = ref<number | null>(null)
let changedSince: string | null = null

const feedApi = computed<FeedApi>(() => props.api ?? taskFeed(props.task.id))
const features = computed(() => feedApi.value.features)
/** Повідомлення, видалені «на екрані», поки тост дає шанс скасувати. */
const removing = ref(new Set<number>())

const messages = computed(() => props.task.messages.filter((m) => !removing.value.has(m.id)))
const lastId = computed(() => props.task.messages.reduce((m, x) => Math.max(m, x.id), 0))
const pinned = computed(() =>
    messages.value.filter((m) => m.pinned && !m.deleted).sort((a, b) => (b.pinned!.at > a.pinned!.at ? 1 : -1)),
)
const pinnedNow = computed(() => pinned.value[pinIndex.value % Math.max(1, pinned.value.length)] ?? null)

function readersOf(m: Message): Person[] {
    if (m.kind !== 'comment' || m.deleted) return []
    const out: Person[] = []
    for (const r of props.task.reads ?? []) {
        if (r.lastMessageId < m.id || r.userId === m.author?.id) continue
        const p = store.person(r.userId)
        if (p) out.push(p)
    }
    return out
}

type Row =
    | { type: 'day'; key: string; label: string }
    | { type: 'unread'; key: string }
    | { type: 'msg'; key: string; m: Message; cont: boolean }

/** Повідомлення з розділювачами днів, «Нові повідомлення» і злиттям підряд від однієї людини. */
const feed = computed(() => {
    const out: Row[] = []
    let day = ''
    let prev: Message | null = null
    let divider = false
    for (const m of messages.value) {
        const d = m.createdAt.slice(0, 10)
        if (d !== day) {
            day = d
            out.push({ type: 'day', key: 'd' + d, label: dayLabel(m.createdAt) })
            prev = null
        }
        if (!divider && unreadFrom.value !== null && m.id > unreadFrom.value && !m.mine && m.kind === 'comment') {
            divider = true
            out.push({ type: 'unread', key: 'unread' })
            prev = null
        }
        const cont = !!prev && prev.kind === 'comment' && m.kind === 'comment' && prev.author?.id === m.author?.id
            && !m.replyTo && new Date(m.createdAt).getTime() - new Date(prev.createdAt).getTime() < 5 * 60000
        out.push({ type: 'msg', key: 'm' + m.id, m, cont })
        prev = m
    }
    return out
})

const mentionList = computed<Person[]>(() => {
    if (mentionQuery.value === null || !props.meta || !features.value.mentions) return []
    const q = mentionQuery.value.toLowerCase()
    return props.meta.people.filter((p) => p.name.toLowerCase().includes(q)).slice(0, 7)
})

function isMobile(): boolean {
    return window.matchMedia('(max-width: 760px)').matches
}

function nearBottom(): boolean {
    const el = chat.value
    return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 160
}

function scrollDown(force = false) {
    const el = chat.value
    if (!el) return
    if (isMobile()) {
        if (force) window.scrollTo({ top: document.body.scrollHeight })
        return
    }
    if (force || nearBottom()) el.scrollTop = el.scrollHeight
}

/**
 * Прокрутити стрічку до елемента. На десктопі крутимо лише сам чат —
 * scrollIntoView зрушив би й сторінку, і шапка задачі поїхала б угору.
 */
function reveal(el: Element, center: boolean, smooth = false) {
    const box = chat.value
    if (!box || isMobile()) {
        el.scrollIntoView({ block: center ? 'center' : 'start', behavior: smooth ? 'smooth' : 'auto' })
        return
    }
    const r = el.getBoundingClientRect()
    const b = box.getBoundingClientRect()
    const top = box.scrollTop + r.top - b.top - (center ? (b.height - r.height) / 2 : 8)
    box.scrollTo({ top: Math.max(0, top), behavior: smooth ? 'smooth' : 'auto' })
}

/** Відкрили задачу: до повідомлення з пошуку (?m=), до першого непрочитаного, інакше вниз. */
async function scrollInitial() {
    await nextTick()
    const target = Number(route.query.m)
    if (target && document.getElementById('msg-' + target)) {
        jump(target)
        return
    }
    const divider = chat.value?.querySelector('.f-unread')
    if (divider) reveal(divider, false)
    else scrollDown(true)
}

function jump(id: number) {
    const el = document.getElementById('msg-' + id)
    if (!el) return
    reveal(el, true, true)
    highlighted.value = id
    window.setTimeout(() => highlighted.value === id && (highlighted.value = null), 1800)
}

function replace(updated: Message) {
    const list = props.task.messages
    const i = list.findIndex((x) => x.id === updated.id)
    if (i >= 0) list[i] = updated
    // Цитати цього повідомлення у відповідях — теж оновити (правка чи видалення).
    for (const m of list) {
        if (m.replyTo?.id === updated.id) {
            m.replyTo = { ...m.replyTo, text: updated.deleted ? '' : updated.text.slice(0, 160), deleted: updated.deleted, files: updated.deleted ? 0 : updated.attachments.length }
        }
    }
    if (updated.deleted) props.task.files = props.task.files.filter((f) => f.messageId !== updated.id)
}

// --- опитування ---
/** since — з якого повідомлення питати (після власного надсилання — з того, що було до нього). */
async function poll(since?: number) {
    if (document.hidden && since === undefined) return
    const task = props.task
    const waiting = task.files.filter((f) => f.preview === 'pending').map((f) => f.id)
    try {
        const r = await feedApi.value.poll(since ?? lastId.value, waiting, changedSince)
        if (task !== props.task) return
        changedSince = r.now
        const stick = nearBottom()
        const before = task.messages.length
        if (r.full) {
            // Уся стрічка (заявка): беремо як є — зникле на сервері зникає й тут.
            task.messages.splice(0, task.messages.length, ...r.messages)
            if (r.files) task.files.splice(0, task.files.length, ...r.files)
        } else if (r.messages.length) {
            const known = new Set(task.messages.map((m) => m.id))
            const fresh = r.messages.filter((m) => !known.has(m.id))
            task.messages.push(...fresh)
            for (const m of fresh) task.files.push(...m.attachments)
            // Колега написав між нашими опитуваннями й нашим повідомленням — ставимо на своє місце.
            if (fresh.length) sortFeed(task)
        }
        for (const m of r.changed) replace(m)
        for (const a of r.attachments) replaceAttachment(a)
        task.reads = r.reads
        if (r.status !== task.status) emit('reload')
        if (task.messages.length > before && stick) {
            await nextTick()
            scrollDown(true)
        }
    } catch {
        /* мережа моргнула — наступна спроба за кілька секунд */
    }
}

/** Порядок задачі — за id, як віддає сервер і як рахується межа «Нові повідомлення». */
function sortFeed(task: FeedSubject) {
    task.messages.sort((a, b) => a.id - b.id)
}

function replaceAttachment(a: Attachment) {
    for (const m of props.task.messages) {
        const i = m.attachments.findIndex((x) => x.id === a.id)
        if (i >= 0) m.attachments[i] = a
    }
    const j = props.task.files.findIndex((x) => x.id === a.id)
    if (j >= 0) props.task.files[j] = a
}

// --- дії з повідомленням ---
function startReply(m: Message) {
    editing.value = null
    replyTo.value = m
    nextTick(() => box.value?.focus())
}

function startEdit(m: Message) {
    replyTo.value = null
    editing.value = m
    text.value = m.text
    nextTick(() => {
        autosize()
        box.value?.focus()
    })
}

function cancelCompose() {
    if (editing.value) text.value = ''
    editing.value = null
    replyTo.value = null
    nextTick(autosize)
}

async function togglePin(m: Message) {
    if (!feedApi.value.pin) return
    try {
        replace(await feedApi.value.pin(m.id, !m.pinned))
        pinIndex.value = 0
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function toggleStar(m: Message) {
    if (!feedApi.value.star) return
    try {
        replace(await feedApi.value.star(m.id, !m.starred))
    } catch (e) {
        error.value = session.handle(e)
    }
}

/**
 * Видалення без вікна підтвердження: повідомлення одразу зникає, 8 секунд тост «Скасувати»,
 * і лише потім запит на сервер. Пішли зі сторінки раніше — запит іде одразу.
 */
function remove(m: Message) {
    const api = feedApi.value
    if (!api.remove) return
    const task = props.task
    removing.value = new Set([...removing.value, m.id])
    const unhide = () => {
        const next = new Set(removing.value)
        next.delete(m.id)
        removing.value = next
    }
    undoable(api.removedText ?? 'Видалено', {
        undo: unhide,
        commit: async () => {
            try {
                const updated = await api.remove!(m)
                if (task !== props.task) return
                if (updated) replace(updated)
                else {
                    const i = task.messages.findIndex((x) => x.id === m.id)
                    if (i >= 0) task.messages.splice(i, 1)
                    const ids = new Set(m.attachments.map((a) => a.id))
                    task.files.splice(0, task.files.length, ...task.files.filter((f) => !ids.has(f.id)))
                }
            } catch (e) {
                error.value = session.handle(e)
            } finally {
                unhide()
            }
        },
    })
}

// --- надсилання ---
/** Файли можна додати лише там, де є композер і скріпка (стрічка складу — лише читання, заявка без права — теж). */
const canAttach = computed(() => !!props.task.can.comment && props.task.can.attach !== false)
function addFiles(list: FileList | File[]) {
    if (!canAttach.value) return
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
    if (e.clipboardData?.files.length && !editing.value) {
        addFiles(e.clipboardData.files)
        e.preventDefault()
    }
}
let dragDepth = 0
function dragEnter(e: DragEvent) {
    if (!canAttach.value || !e.dataTransfer?.types.includes('Files')) return
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
    if (e.dataTransfer?.files.length && !editing.value) addFiles(e.dataTransfer.files)
}

async function send() {
    if (sending.value) return
    const task = props.task
    if (editing.value) {
        sending.value = true
        try {
            if (!feedApi.value.edit) return
            replace(await feedApi.value.edit(editing.value.id, text.value))
            editing.value = null
            text.value = ''
            await nextTick()
            autosize()
        } catch (e) {
            error.value = session.handle(e)
        } finally {
            sending.value = false
        }
        return
    }
    if (!text.value.trim() && !pending.value.length) return
    sending.value = true
    error.value = ''
    try {
        const used = mentions.value.filter((id) => {
            const p = store.person(id)
            return p && text.value.includes('@' + p.name)
        })
        const before = lastId.value
        const posted = await feedApi.value.post(text.value, pending.value.map((p) => p.file), used, replyTo.value?.id)
        if (feedApi.value.fullFeed) {
            // Сервер віддав усю стрічку у своєму порядку (заявка) — беремо як є.
            task.messages.splice(0, task.messages.length, ...posted)
            task.files.splice(0, task.files.length, ...posted.flatMap((m) => m.attachments))
        } else {
            const known = new Set(task.messages.map((m) => m.id))
            for (const message of posted) {
                if (known.has(message.id)) continue
                task.messages.push(message)
                task.files.push(...message.attachments)
            }
            sortFeed(task)
        }
        // Інакше чуже повідомлення, що прийшло між опитуванням і нашим, загубилося б: lastId уже наш.
        if (posted.length === 1 && posted[0].id > before + 1) void poll(before)
        feedApi.value.onSaved?.()
        text.value = ''
        mentions.value = []
        replyTo.value = null
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
    if (e.key === 'Escape' && (replyTo.value || editing.value)) {
        cancelCompose()
        return
    }
    // ↑ у порожньому полі — змінити своє останнє повідомлення (як у Telegram).
    if (e.key === 'ArrowUp' && !text.value && !editing.value && feedApi.value.edit) {
        const mine = [...messages.value].reverse().find((m) => m.can?.edit && m.text)
        if (mine) {
            e.preventDefault()
            startEdit(mine)
        }
        return
    }
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault()
        send()
    }
}

function opened() {
    unreadFrom.value = props.task.readUpTo ?? null
    changedSince = props.task.now ?? null
    replyTo.value = null
    editing.value = null
    pinIndex.value = 0
    removing.value = new Set()
    scrollInitial()
}

watch(() => props.task.id, opened)

onMounted(() => opened())
// З Mercure — за подією цієї стрічки (+ раз на хвилину); прев'ю файлу ще готується — частіше.
useLiveRefresh(() => poll(), {
    pollMs: feedApi.value.intervalMs ?? 4000,
    match: (e) => feedApi.value.live?.(e) ?? false,
    debounceMs: 150,
    fastWhile: () => props.task.files.some((f) => f.preview === 'pending'),
})
onBeforeUnmount(() => {
    // Видалене «на екрані» не повинно ожити: пішли з задачі — запит іде одразу.
    flushUndo()
    pending.value.forEach((p) => p.url && URL.revokeObjectURL(p.url))
})

/** Чи документ зараз «видалено на екрані» (чекає таймера тосту) — щоб панель документів теж його сховала. */
const removingFile = (fileId: number) => props.task.messages.some((m) => removing.value.has(m.id) && m.attachments.some((a) => a.id === fileId))

defineExpose({ scrollDown, remove, removingFile })
</script>

<template>
    <button v-if="pinnedNow" type="button" class="f-pinbar" :class="{ 't-main-hide-mobile': hidden }" @click="jump(pinnedNow.id); pinIndex++">
        <span class="ic">📌</span>
        <span class="body">
            <b>Закріплено<template v-if="pinned.length > 1"> · {{ (pinIndex % pinned.length) + 1 }} з {{ pinned.length }}</template></b>
            <span>{{ pinnedNow.author?.name }}: {{ pinnedNow.text || (pinnedNow.attachments.length ? '📎 ' + pinnedNow.attachments[0].name : '') }}</span>
        </span>
    </button>

    <div
        ref="chat"
        class="t-chat"
        :class="{ 't-main-hide-mobile': hidden }"
        @dragenter.prevent="dragEnter"
        @dragover.prevent
        @dragleave="dragLeave"
        @drop.prevent="drop"
    >
        <div v-if="dragging" class="t-drop">Відпустіть, щоб додати файли</div>
        <template v-for="row in feed" :key="row.key">
            <div v-if="row.type === 'day'" class="t-day">{{ row.label }}</div>
            <div v-else-if="row.type === 'unread'" class="f-unread"><span>Нові повідомлення</span></div>
            <div v-else-if="row.m.kind === 'event'" :id="'msg-' + row.m.id" class="t-event">
                <b>{{ row.m.author?.name ?? 'Система' }}</b> {{ row.m.text }} · {{ time(row.m.createdAt) }}
            </div>
            <MessageBubble
                v-else
                :m="row.m"
                :cont="row.cont"
                :readers="readersOf(row.m)"
                :highlighted="highlighted === row.m.id"
                :features="features"
                @reply="startReply"
                @edit="startEdit"
                @pin="togglePin"
                @star="toggleStar"
                @remove="remove"
                @open="emit('open', $event)"
                @jump="jump"
            />
        </template>
    </div>

    <form v-if="task.can.comment" class="t-composer" :class="{ 't-main-hide-mobile': hidden }" @submit.prevent="send">
        <div v-if="mentionList.length" class="t-mentions">
            <button v-for="(p, i) in mentionList" :key="p.id" type="button" :class="{ on: i === mentionIndex }" @mousedown.prevent="chooseMention(p)">
                <Avatar :person="p" :size="26" /> <span>{{ p.name }} <small>· {{ p.position ?? p.roleLabel }}</small></span>
            </button>
        </div>
        <div v-if="error" class="t-error">{{ error }}</div>
        <div v-if="replyTo || editing" class="f-compose-ctx">
            <span class="ic">{{ editing ? '✎' : '↩' }}</span>
            <button type="button" class="body" @click="jump((editing ?? replyTo)!.id)">
                <b>{{ editing ? 'Редагування' : 'Відповідь · ' + (replyTo!.author?.name ?? '') }}</b>
                <span>{{ (editing ?? replyTo)!.text || '📎 файл' }}</span>
            </button>
            <button type="button" class="x" aria-label="Скасувати" title="Скасувати (Esc)" @click="cancelCompose">✕</button>
        </div>
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
            <label v-if="!editing && task.can.attach !== false" class="t-btn ghost icon" title="Додати файли">
                📎<input type="file" multiple hidden @change="pick" />
            </label>
            <textarea
                ref="box"
                v-model="text"
                class="t-textarea"
                rows="1"
                :placeholder="feedApi.placeholder ?? 'Повідомлення…'"
                @input="onInput"
                @keydown="onKey"
                @paste="onPaste"
            />
            <button type="submit" class="t-btn primary icon" :disabled="sending || (!editing && !text.trim() && !pending.length)" :title="editing ? 'Зберегти (Ctrl+Enter)' : 'Надіслати (Ctrl+Enter)'">
                {{ sending ? '…' : editing ? '✓' : '➤' }}
            </button>
        </div>
    </form>
</template>
