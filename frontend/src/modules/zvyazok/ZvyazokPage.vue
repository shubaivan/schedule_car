<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Avatar from '../tasks/Avatar.vue'
import Icon from '../tasks/shell/Icon.vue'
import type { Person } from '../tasks/api'
import { useSession } from '../../app/store'
import {
    batches,
    drafts,
    draftsOwner,
    shrinkPhoto,
    zvApi,
    ZV_ACCEPT,
    ZV_MAX_BYTES,
    ZV_MAX_FILES,
    type ZvChat,
    type ZvChatRow,
    type ZvFile,
    type ZvMember,
    type ZvMessage,
} from './api'
import './zvyazok.css'

/**
 * «Звʼязок» (ТЗ §21; Олександр 15.11): месенджер «Плану» без прив'язки до задачі —
 * людина-людина й людина-команда. Ліворуч — чати (закріплені вгорі, непрочитані, @, чернетки,
 * архів, пошук), праворуч — розмова: прокручується лише стрічка, шапка й поле вводу на місці.
 * Без модалок: новий чат і учасники — панелі в самій колонці.
 * Файли (Олександр 17.11): скріпка, вставка з буфера, перетягування; фото стискаються ще тут
 * (shrinkPhoto) і ще раз на сервері; до 20 МБ кожен. Сповіщень у Telegram немає — лише лічильник.
 */
const route = useRoute()
const router = useRouter()
const session = useSession()

const chatId = computed(() => (route.params.id ? Number(route.params.id) : null))
const me = computed(() => session.user?.id ?? 0)
watch(me, (id) => draftsOwner(id), { immediate: true })

// ---- ліва колонка
const rows = ref<ZvChatRow[]>([])
const q = ref('')
const archived = ref(false)
const listError = ref('')
const loadedList = ref(false)

async function loadList() {
    try {
        const out = await zvApi.chats(q.value, archived.value)
        rows.value = out.items
        listError.value = ''
    } catch (e) {
        listError.value = session.handle(e)
    } finally {
        loadedList.value = true
    }
}
let qTimer: number | undefined
watch(q, () => {
    window.clearTimeout(qTimer)
    qTimer = window.setTimeout(loadList, 250)
})
watch(archived, loadList)

function open(id: number) {
    router.push({ name: 'zvyazok', params: { id: String(id) } })
}
function back() {
    router.push({ name: 'zvyazok' })
}
function time(iso: string | null): string {
    if (!iso) return ''
    const d = new Date(iso)
    const now = new Date()
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' })
    return d.toLocaleDateString('uk-UA', { day: 'numeric', month: 'short' })
}
const draftOf = (id: number) => drafts.get(id)

// ---- новий чат
const creating = ref<null | 'direct' | 'group'>(null)
const people = ref<Person[]>([])
const pick = ref('')
const groupTitle = ref('')
const groupMembers = ref<number[]>([])
const createError = ref('')

async function startCreate(kind: 'direct' | 'group') {
    creating.value = kind
    createError.value = ''
    pick.value = ''
    groupTitle.value = ''
    groupMembers.value = []
    if (!people.value.length) people.value = (await zvApi.people()).items
}
const pickable = computed(() => {
    const needle = pick.value.trim().toLowerCase()
    return people.value.filter((p) => !needle || `${p.name} ${p.position ?? ''} ${p.department ?? ''} ${p.phone ?? ''}`.toLowerCase().includes(needle))
})
const departments = computed(() => [...new Set(people.value.map((p) => p.department).filter((d): d is string => !!d))].sort())
function toggleMember(id: number) {
    groupMembers.value = groupMembers.value.includes(id) ? groupMembers.value.filter((x) => x !== id) : [...groupMembers.value, id]
}
function addDepartment(dep: string) {
    const ids = people.value.filter((p) => p.department === dep).map((p) => p.id)
    groupMembers.value = [...new Set([...groupMembers.value, ...ids])]
    if (!groupTitle.value) groupTitle.value = dep
}
async function createDirect(p: Person) {
    try {
        const chat = await zvApi.direct(p.id)
        creating.value = null
        await loadList()
        open(chat.id)
    } catch (e) {
        createError.value = session.handle(e)
    }
}
async function createGroup() {
    try {
        const chat = await zvApi.group(groupTitle.value, groupMembers.value)
        creating.value = null
        await loadList()
        open(chat.id)
    } catch (e) {
        createError.value = session.handle(e)
    }
}

// ---- розмова
const chat = ref<ZvChat | null>(null)
const messages = ref<ZvMessage[]>([])
const more = ref(false)
const chatError = ref('')
const feed = ref<HTMLElement | null>(null)
const text = ref('')
const box = ref<HTMLTextAreaElement | null>(null)
const replyTo = ref<ZvMessage | null>(null)
const editing = ref<ZvMessage | null>(null)
const sending = ref(false)
const showMembers = ref(false)
const history = ref<Record<number, { text: string; at: string | null }[]>>({})
const flash = ref<number | null>(null)
const mentioned = ref<Map<string, number>>(new Map())

// ---- файли до відправки
type Pending = { key: number; file: File; preview: string | null; busy: boolean }
const pending = ref<Pending[]>([])
const fileInput = ref<HTMLInputElement | null>(null)
const dragging = ref(false)
let pendingKey = 0
const sizeLabel = (n: number) => (n >= 1048576 ? (n / 1048576).toFixed(1).replace('.0', '') + ' МБ' : Math.max(1, Math.round(n / 1024)) + ' КБ')
function clearPending() {
    pending.value.forEach((p) => p.preview && URL.revokeObjectURL(p.preview))
    pending.value = []
}
async function addFiles(list: FileList | File[]) {
    chatError.value = ''
    for (const raw of Array.from(list)) {
        if (pending.value.length >= ZV_MAX_FILES * 3) {
            chatError.value = `За раз — до ${ZV_MAX_FILES * 3} файлів.`
            break
        }
        if (raw.size > ZV_MAX_BYTES) {
            chatError.value = `«${raw.name}» більший за 20 МБ.`
            continue
        }
        const item = reactiveItem(raw)
        pending.value = [...pending.value, item]
        if (raw.type.startsWith('image/')) {
            const small = await shrinkPhoto(raw)
            const found = pending.value.find((p) => p.key === item.key)
            if (found) {
                found.file = small
                found.preview = URL.createObjectURL(small)
                found.busy = false
            }
        }
    }
}
function reactiveItem(file: File): Pending {
    return { key: ++pendingKey, file, preview: null, busy: file.type.startsWith('image/') }
}
function unpend(key: number) {
    const p = pending.value.find((x) => x.key === key)
    if (p?.preview) URL.revokeObjectURL(p.preview)
    pending.value = pending.value.filter((x) => x.key !== key)
}
function pickFiles(e: Event) {
    const input = e.target as HTMLInputElement
    if (input.files) void addFiles(input.files)
    input.value = ''
}
function onPaste(e: ClipboardEvent) {
    if (e.clipboardData?.files.length && !editing.value) {
        void addFiles(e.clipboardData.files)
        e.preventDefault()
    }
}
let dragDepth = 0
function dragEnter(e: DragEvent) {
    if (!chat.value || editing.value || !e.dataTransfer?.types.includes('Files')) return
    dragDepth++
    dragging.value = true
}
function dragLeave() {
    dragDepth = Math.max(0, dragDepth - 1)
    if (!dragDepth) dragging.value = false
}
function onDrop(e: DragEvent) {
    dragDepth = 0
    dragging.value = false
    if (e.dataTransfer?.files.length && !editing.value) void addFiles(e.dataTransfer.files)
}
const images = (m: ZvMessage): ZvFile[] => m.files.filter((f) => f.kind === 'image' && f.thumbUrl)
const documents = (m: ZvMessage): ZvFile[] => m.files.filter((f) => !(f.kind === 'image' && f.thumbUrl))
const fileIcon = (f: ZvFile) => (f.kind === 'pdf' ? 'PDF' : (f.name.split('.').pop() ?? 'файл').toUpperCase().slice(0, 4))

const nearBottom = () => !feed.value || feed.value.scrollHeight - feed.value.scrollTop - feed.value.clientHeight < 80
function toBottom() {
    nextTick(() => feed.value && (feed.value.scrollTop = feed.value.scrollHeight))
}

async function loadChat(id: number) {
    chat.value = null
    messages.value = []
    replyTo.value = null
    editing.value = null
    showMembers.value = false
    history.value = {}
    chatError.value = ''
    text.value = drafts.get(id)
    mentioned.value = new Map()
    clearPending()
    try {
        const [c, m] = await Promise.all([zvApi.chat(id), zvApi.messages(id)])
        if (chatId.value !== id) return
        chat.value = c
        messages.value = m.items
        more.value = m.more
        toBottom()
        await markRead()
        nextTick(() => box.value?.focus())
    } catch (e) {
        chatError.value = session.handle(e) || 'Чат не знайдено.'
    }
}
async function older() {
    if (!chat.value || !messages.value.length) return
    const el = feed.value
    const before = el ? el.scrollHeight : 0
    const m = await zvApi.messages(chat.value.id, { before: messages.value[0].id })
    messages.value = [...m.items, ...messages.value]
    more.value = m.more
    nextTick(() => el && (el.scrollTop = el.scrollHeight - before))
}
/** Свіжа сторінка: нові повідомлення, галочки, правки й видалення інших. */
async function refreshChat() {
    if (!chat.value || document.hidden) return
    const id = chat.value.id
    const stick = nearBottom()
    const m = await zvApi.messages(id).catch(() => null)
    if (!m || chatId.value !== id) return
    const byId = new Map(messages.value.map((x) => [x.id, x]))
    m.items.forEach((x) => byId.set(x.id, x))
    const before = messages.value.length ? messages.value[messages.value.length - 1].id : 0
    messages.value = [...byId.values()].sort((a, b) => a.id - b.id)
    const last = messages.value.length ? messages.value[messages.value.length - 1].id : 0
    if (last > before && stick) toBottom()
    if (stick) await markRead()
}
async function markRead() {
    if (!chat.value || !messages.value.length || document.hidden) return
    const last = messages.value[messages.value.length - 1].id
    if (last <= chat.value.lastReadId) return
    chat.value.lastReadId = (await zvApi.read(chat.value.id, last)).lastReadId
    const row = rows.value.find((r) => r.id === chat.value?.id)
    if (row) {
        row.unread = 0
        row.mentions = 0
    }
}

watch(
    chatId,
    (id) => {
        if (id) void loadChat(id)
        else chat.value = null
    },
    { immediate: true },
)
// Чернетка пишеться лише в чат, який уже відкрито (не в попередній під час перемикання).
watch(text, (v) => chat.value && chat.value.id === chatId.value && !editing.value && drafts.set(chat.value.id, v))

// ---- @згадки: список учасників під полем, мишею й клавіатурою
const mention = ref<{ query: string; start: number } | null>(null)
const mentionIndex = ref(0)
const mentionList = computed(() => {
    if (!mention.value || !chat.value) return []
    const needle = mention.value.query.toLowerCase()
    return chat.value.members.filter((p) => p.id !== me.value && p.name.toLowerCase().includes(needle)).slice(0, 8)
})
function onInput() {
    const el = box.value
    if (!el) return
    el.style.height = 'auto'
    el.style.height = Math.min(el.scrollHeight, 180) + 'px'
    const upto = text.value.slice(0, el.selectionStart)
    const m = /(?:^|\s)@([^\s@]{0,30})$/u.exec(upto)
    mention.value = m ? { query: m[1], start: upto.length - m[1].length - 1 } : null
    mentionIndex.value = 0
}
function chooseMention(p: Person) {
    const el = box.value
    if (!el || !mention.value) return
    const end = el.selectionStart
    const tag = '@' + p.name
    text.value = text.value.slice(0, mention.value.start) + tag + ' ' + text.value.slice(end)
    mentioned.value.set(tag, p.id)
    mention.value = null
    nextTick(() => {
        const pos = (text.value.indexOf(tag + ' ') >= 0 ? text.value.indexOf(tag + ' ') : 0) + tag.length + 1
        el.focus()
        el.setSelectionRange(pos, pos)
    })
}
function onKey(e: KeyboardEvent) {
    if (mentionList.value.length) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            mentionIndex.value = (mentionIndex.value + (e.key === 'ArrowDown' ? 1 : -1) + mentionList.value.length) % mentionList.value.length
            e.preventDefault()
            return
        }
        if (e.key === 'Enter' || e.key === 'Tab') {
            chooseMention(mentionList.value[mentionIndex.value])
            e.preventDefault()
            return
        }
        if (e.key === 'Escape') {
            mention.value = null
            e.preventDefault()
            return
        }
    }
    if (e.key === 'Escape' && (replyTo.value || editing.value)) {
        cancelCompose()
        e.preventDefault()
    } else if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault()
        void send()
    } else if (e.key === 'ArrowUp' && !text.value && !editing.value) {
        const mine = [...messages.value].reverse().find((m) => m.mine && !m.deleted)
        if (mine) {
            startEdit(mine)
            e.preventDefault()
        }
    }
}
function mentionIds(body: string): number[] {
    return [...mentioned.value.entries()].filter(([tag]) => body.includes(tag)).map(([, id]) => id)
}

async function send() {
    const body = text.value.trim()
    const files = pending.value.map((p) => p.file)
    if (!chat.value || sending.value || pending.value.some((p) => p.busy)) return
    if (!body && !(files.length && !editing.value)) return
    sending.value = true
    try {
        if (editing.value) {
            const saved = await zvApi.edit(editing.value.id, body)
            messages.value = messages.value.map((m) => (m.id === saved.id ? saved : m))
            delete history.value[saved.id]
            editing.value = null
            text.value = drafts.get(chat.value.id)
        } else {
            const chatNow = chat.value
            const sent: ZvMessage[] = []
            if (files.length) {
                // Великі пачки — кількома повідомленнями; текст, відповідь і згадки — з першим.
                const groups = batches(files)
                for (let i = 0; i < groups.length; i++) {
                    sent.push(await zvApi.postFiles(chatNow.id, i === 0 ? body : '', groups[i], i === 0 ? (replyTo.value?.id ?? null) : null, i === 0 ? mentionIds(body) : []))
                    const done = new Set(groups[i])
                    pending.value.filter((p) => done.has(p.file)).forEach((p) => unpend(p.key))
                }
            } else {
                sent.push(await zvApi.post(chatNow.id, body, replyTo.value?.id ?? null, mentionIds(body)))
            }
            // Спершу стираємо чернетку, потім текст: запізнілий autosave не поверне відправлене.
            drafts.set(chatNow.id, '')
            text.value = ''
            replyTo.value = null
            mentioned.value = new Map()
            const ids = new Set(sent.map((m) => m.id))
            messages.value = [...messages.value.filter((m) => !ids.has(m.id)), ...sent]
            chatNow.lastReadId = sent[sent.length - 1].id
            toBottom()
            void loadList()
        }
        nextTick(onInput)
    } catch (e) {
        chatError.value = session.handle(e)
        // Частина пачок могла піти — показуємо, що вже в чаті.
        void refreshChat()
    } finally {
        sending.value = false
    }
}
function startReply(m: ZvMessage) {
    editing.value = null
    replyTo.value = m
    box.value?.focus()
}
function startEdit(m: ZvMessage) {
    replyTo.value = null
    editing.value = m
    text.value = m.text
    nextTick(() => {
        box.value?.focus()
        onInput()
    })
}
function cancelCompose() {
    if (editing.value && chat.value) text.value = drafts.get(chat.value.id)
    editing.value = null
    replyTo.value = null
}
/** Без діалогів поверх сторінки: перший клік — «Видалити для всіх?», другий — видаляє. */
const confirmDelete = ref<number | null>(null)
async function remove(m: ZvMessage) {
    if (confirmDelete.value !== m.id) {
        confirmDelete.value = m.id
        window.setTimeout(() => confirmDelete.value === m.id && (confirmDelete.value = null), 4000)
        return
    }
    confirmDelete.value = null
    await zvApi.remove(m.id)
    await refreshChat()
}
async function toggleHistory(m: ZvMessage) {
    if (history.value[m.id]) delete history.value[m.id]
    else history.value[m.id] = (await zvApi.history(m.id)).items
}
function jump(id: number) {
    const el = feed.value?.querySelector<HTMLElement>(`[data-msg="${id}"]`)
    if (!el) return
    el.scrollIntoView({ block: 'center', behavior: 'smooth' })
    flash.value = id
    window.setTimeout(() => (flash.value = null), 1600)
}

/**
 * Текст повідомлення — сегментами (без v-html): звичайний текст і @Імʼя учасника.
 * Один прохід по сирому тексту, довші імена першими — «@Іван» не з'їсть «@Іван Петренко».
 */
type Segment = { text: string; person?: ZvMember }
function segments(m: ZvMessage): Segment[] {
    const people = (chat.value?.members ?? []).filter((p) => m.mentions.includes(p.id)).sort((x, y) => y.name.length - x.name.length)
    if (!people.length) return [{ text: m.text }]
    const esc = (v: string) => v.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    const re = new RegExp('(' + people.map((p) => esc('@' + p.name)).join('|') + ')', 'gu')
    return m.text
        .split(re)
        .filter((part) => part !== '')
        .map((part) => ({ text: part, person: people.find((p) => '@' + p.name === part) }))
}
const ticks = (m: ZvMessage) => (m.readOf > 0 && m.readBy >= m.readOf ? '✓✓' : '✓')
const tickTitle = (m: ZvMessage) =>
    m.readOf === 0 ? 'Надіслано' : m.readBy >= m.readOf ? 'Прочитали всі' : m.readOf > 1 ? `Прочитали ${m.readBy} з ${m.readOf}` : 'Доставлено, ще не прочитано'

async function setFlag(flag: 'pinned' | 'archived') {
    if (!chat.value) return
    chat.value = await zvApi.flag(chat.value.id, { [flag]: !chat.value[flag] })
    await loadList()
    if (flag === 'archived' && chat.value.archived) back()
}
const confirmLeave = ref(false)
async function leave() {
    if (!chat.value) return
    if (!confirmLeave.value) {
        confirmLeave.value = true
        return
    }
    confirmLeave.value = false
    await zvApi.leave(chat.value.id)
    await loadList()
    back()
}
// Учасники групи: власник додає, прибирає, перейменовує.
const addPick = ref('')
const addable = computed(() => {
    const inChat = new Set(chat.value?.members.map((m) => m.id))
    const needle = addPick.value.trim().toLowerCase()
    return people.value.filter((p) => !inChat.has(p.id) && (!needle || p.name.toLowerCase().includes(needle))).slice(0, 12)
})
async function openMembers() {
    showMembers.value = !showMembers.value
    if (showMembers.value && !people.value.length) people.value = (await zvApi.people()).items
}
async function changeMembers(body: { add?: number[]; remove?: number[]; title?: string }) {
    if (!chat.value) return
    try {
        chat.value = await zvApi.update(chat.value.id, body)
        await loadList()
    } catch (e) {
        chatError.value = session.handle(e)
    }
}
const newTitle = ref<string | null>(null)
async function rename() {
    if (newTitle.value === null) {
        newTitle.value = chat.value?.title ?? ''
        return
    }
    if (newTitle.value.trim()) await changeMembers({ title: newTitle.value })
    newTitle.value = null
}

// ---- опитування: список раз на 8 с, відкрита розмова — раз на 4 с
let listTimer: number | undefined
let chatTimer: number | undefined
onMounted(() => {
    void loadList()
    listTimer = window.setInterval(() => !document.hidden && loadList(), 8000)
    chatTimer = window.setInterval(() => void refreshChat(), 4000)
    document.addEventListener('visibilitychange', onVisible)
})
onBeforeUnmount(() => {
    window.clearInterval(listTimer)
    window.clearInterval(chatTimer)
    document.removeEventListener('visibilitychange', onVisible)
})
function onVisible() {
    if (!document.hidden) {
        void loadList()
        void refreshChat()
    }
}
</script>

<template>
    <div class="zv" :class="{ 'zv-open': chatId }">
        <aside class="zv-list" aria-label="Чати">
            <div class="zv-list-head">
                <div class="zv-search">
                    <Icon name="search" :size="16" />
                    <input v-model="q" type="search" placeholder="Пошук: чат, людина, текст" aria-label="Пошук чатів" />
                </div>
                <button type="button" class="zv-new" title="Новий особистий чат" data-testid="zv-new-direct" @click="startCreate('direct')">
                    <Icon name="user" :size="18" />
                </button>
                <button type="button" class="zv-new" title="Новий груповий чат" data-testid="zv-new-group" @click="startCreate('group')">
                    <Icon name="users" :size="18" />
                </button>
            </div>
            <div class="zv-tabs" role="tablist">
                <button type="button" :class="{ on: !archived }" @click="archived = false">Чати</button>
                <button type="button" :class="{ on: archived }" @click="archived = true">
                    <Icon name="archive" :size="14" />
                    Архів
                </button>
            </div>

            <section v-if="creating" class="zv-create" :aria-label="creating === 'direct' ? 'Новий особистий чат' : 'Новий груповий чат'">
                <header>
                    <b>{{ creating === 'direct' ? 'Написати людині' : 'Новий груповий чат' }}</b>
                    <button type="button" class="zv-x" aria-label="Закрити" @click="creating = null"><Icon name="x" :size="16" /></button>
                </header>
                <input v-if="creating === 'group'" v-model="groupTitle" class="t-input" maxlength="120" placeholder="Назва: тема, обʼєкт, команда" data-testid="zv-group-title" />
                <div v-if="creating === 'group' && departments.length" class="zv-deps">
                    <span>Додати відділ:</span>
                    <button v-for="d in departments" :key="d" type="button" @click="addDepartment(d)">{{ d }}</button>
                </div>
                <input v-model="pick" class="t-input" placeholder="Імʼя, посада, відділ…" />
                <ul class="zv-people">
                    <li v-for="p in pickable" :key="p.id">
                        <button type="button" :class="{ on: groupMembers.includes(p.id) }" @click="creating === 'direct' ? createDirect(p) : toggleMember(p.id)">
                            <Avatar :person="p" :size="28" />
                            <span>
                                <b>{{ p.name }}</b>
                                <small>{{ p.position ?? p.roleLabel }}{{ p.department ? ' · ' + p.department : '' }}</small>
                            </span>
                            <Icon v-if="groupMembers.includes(p.id)" name="check" :size="16" />
                        </button>
                    </li>
                    <li v-if="!pickable.length" class="zv-muted">Нікого не знайдено.</li>
                </ul>
                <p v-if="createError" class="t-error">{{ createError }}</p>
                <button
                    v-if="creating === 'group'"
                    type="button"
                    class="t-btn primary"
                    :disabled="!groupTitle.trim() || !groupMembers.length"
                    data-testid="zv-group-create"
                    @click="createGroup"
                >
                    Створити чат ({{ groupMembers.length + 1 }})
                </button>
            </section>

            <ul v-else class="zv-rows">
                <li v-for="r in rows" :key="r.id">
                    <button type="button" class="zv-row" :class="{ on: r.id === chatId, unread: r.unread }" @click="open(r.id)">
                        <Avatar v-if="r.person" :person="r.person" :size="40" />
                        <span v-else class="zv-group-ava"><Icon name="users" :size="18" /></span>
                        <span class="zv-row-main">
                            <span class="zv-row-top">
                                <b>{{ r.title }}</b>
                                <Icon v-if="r.pinned" name="pin" :size="13" class="zv-pin" />
                                <time>{{ time(r.last?.at ?? r.activityAt) }}</time>
                            </span>
                            <span class="zv-row-bottom">
                                <span v-if="r.id !== chatId && draftOf(r.id)" class="zv-draft">Чернетка: {{ draftOf(r.id) }}</span>
                                <span v-else-if="r.last" class="zv-last">
                                    {{ r.last.mine ? 'Ви: ' : r.kind === 'group' && r.last.author ? r.last.author + ': ' : '' }}{{ r.last.text }}
                                </span>
                                <span v-else class="zv-last zv-muted">{{ r.kind === 'group' ? r.members + ' учасники' : 'Ще немає повідомлень' }}</span>
                                <b v-if="r.mentions" class="zv-at" title="Вас згадали">@</b>
                                <b v-if="r.unread" class="zv-badge">{{ r.unread > 99 ? '99+' : r.unread }}</b>
                            </span>
                        </span>
                    </button>
                </li>
                <li v-if="loadedList && !rows.length" class="zv-empty">
                    <template v-if="q">Нічого не знайдено.</template>
                    <template v-else-if="archived">В архіві порожньо.</template>
                    <template v-else>
                        Чатів ще немає.
                        <br />
                        <button type="button" class="t-btn primary" @click="startCreate('direct')">Написати колезі</button>
                        <button type="button" class="t-btn" @click="startCreate('group')">Створити груповий чат</button>
                    </template>
                </li>
                <li v-if="listError" class="t-error">{{ listError }}</li>
            </ul>
        </aside>

        <section class="zv-chat" aria-label="Розмова" @dragenter.prevent="dragEnter" @dragover.prevent @dragleave="dragLeave" @drop.prevent="onDrop">
            <div v-if="dragging" class="zv-drop">Відпустіть, щоб додати файли</div>
            <div v-if="!chatId" class="zv-placeholder">
                <Icon name="chat" :size="40" />
                <p>Оберіть чат ліворуч або почніть новий.</p>
                <p class="zv-muted">Особисті розмови й групи на будь-яку тему. Бачать лише учасники.</p>
            </div>
            <div v-else-if="chatError && !chat" class="zv-placeholder">
                <p>{{ chatError }}</p>
                <button type="button" class="t-btn" @click="back">До списку</button>
            </div>
            <template v-else-if="chat">
                <header class="zv-head">
                    <button type="button" class="zv-back" aria-label="До списку" @click="back"><Icon name="back" /></button>
                    <Avatar v-if="chat.person" :person="chat.person" :size="34" />
                    <span v-else class="zv-group-ava sm"><Icon name="users" :size="16" /></span>
                    <span class="zv-head-main">
                        <b>{{ chat.title }}</b>
                        <small v-if="chat.person">
                            {{ chat.person.position ?? chat.person.roleLabel }}
                            <a v-if="chat.person.tel" :href="'tel:' + chat.person.tel">{{ chat.person.phone }}</a>
                        </small>
                        <small v-else>
                            <button type="button" class="zv-link" data-testid="zv-members" @click="openMembers">{{ chat.members.length }} учасники</button>
                        </small>
                    </span>
                    <button type="button" class="zv-icon" :class="{ on: chat.pinned }" :title="chat.pinned ? 'Відкріпити' : 'Закріпити вгорі'" @click="setFlag('pinned')">
                        <Icon name="pin" :size="18" />
                    </button>
                    <button type="button" class="zv-icon" :title="chat.archived ? 'Повернути з архіву' : 'В архів'" @click="setFlag('archived')">
                        <Icon name="archive" :size="18" />
                    </button>
                </header>

                <div v-if="showMembers && chat.kind === 'group'" class="zv-members">
                    <div class="zv-members-head">
                        <b>Учасники</b>
                        <button v-if="chat.owner" type="button" class="zv-link" @click="rename">Перейменувати</button>
                        <button type="button" class="zv-link danger" @click="leave">{{ confirmLeave ? 'Точно вийти? Нових повідомлень не побачите' : 'Вийти з чату' }}</button>
                    </div>
                    <form v-if="newTitle !== null" class="zv-rename" @submit.prevent="rename">
                        <input v-model="newTitle" class="t-input" maxlength="120" aria-label="Нова назва чату" />
                        <button type="submit" class="t-btn primary">Зберегти</button>
                        <button type="button" class="t-btn" @click="newTitle = null">Скасувати</button>
                    </form>
                    <ul>
                        <li v-for="p in chat.members" :key="p.id">
                            <Avatar :person="p" :size="24" />
                            <span>
                                {{ p.name }}
                                <small v-if="p.owner">· власник</small>
                                <small v-if="p.phone">· {{ p.phone }}</small>
                            </span>
                            <button v-if="chat.owner && p.id !== me" type="button" class="zv-link danger" @click="changeMembers({ remove: [p.id] })">прибрати</button>
                        </li>
                    </ul>
                    <template v-if="chat.owner">
                        <input v-model="addPick" class="t-input" placeholder="Додати людину…" />
                        <div class="zv-add">
                            <button v-for="p in addable" :key="p.id" type="button" @click="changeMembers({ add: [p.id] })">+ {{ p.name }}</button>
                        </div>
                    </template>
                </div>

                <div ref="feed" class="zv-feed" data-testid="zv-feed">
                    <button v-if="more" type="button" class="zv-older" @click="older">Попередні повідомлення</button>
                    <p v-if="!messages.length" class="zv-muted zv-start">Напишіть перше повідомлення.</p>
                    <article v-for="m in messages" :key="m.id" :data-msg="m.id" class="zv-msg" :class="{ mine: m.mine, flash: flash === m.id, mentioned: m.mentions.includes(me) }">
                        <Avatar v-if="!m.mine && chat.kind === 'group'" :person="m.author" :size="28" />
                        <div class="zv-bubble">
                            <b v-if="!m.mine && chat.kind === 'group'" class="zv-author">{{ m.author?.name }}</b>
                            <button v-if="m.reply" type="button" class="zv-quote" @click="jump(m.reply.id)">
                                <b>{{ m.reply.author }}</b>
                                <span>{{ m.reply.text }}</span>
                            </button>
                            <div v-if="!m.deleted && m.text" class="zv-text">
                                <template v-for="(seg, i) in segments(m)" :key="i">
                                    <span
                                        v-if="seg.person"
                                        class="zv-mention"
                                        :class="{ me: seg.person.id === me }"
                                        :title="(seg.person.position ?? seg.person.name) + (seg.person.phone ? ' · ' + seg.person.phone : '')"
                                    >
                                        {{ seg.text }}
                                    </span>
                                    <template v-else>{{ seg.text }}</template>
                                </template>
                            </div>
                            <div v-else-if="m.deleted" class="zv-text zv-muted"><i>Повідомлення видалено</i></div>
                            <div v-if="images(m).length" class="zv-photos" :class="{ one: images(m).length === 1 }">
                                <a v-for="f in images(m)" :key="f.id" :href="f.viewUrl" target="_blank" rel="noopener" :title="f.name" data-testid="zv-photo">
                                    <img :src="f.thumbUrl ?? ''" :alt="f.name" loading="lazy" />
                                </a>
                            </div>
                            <ul v-if="documents(m).length" class="zv-docs">
                                <li v-for="f in documents(m)" :key="f.id" data-testid="zv-doc">
                                    <a class="zv-doc" :href="f.kind === 'pdf' ? f.viewUrl : f.downloadUrl" :target="f.kind === 'pdf' ? '_blank' : undefined" rel="noopener">
                                        <span class="zv-doc-ext" :class="f.kind">{{ fileIcon(f) }}</span>
                                        <span class="zv-doc-main">
                                            <b>{{ f.name }}</b>
                                            <small>{{ sizeLabel(f.size) }}</small>
                                        </span>
                                    </a>
                                    <a class="zv-doc-dl" :href="f.downloadUrl" title="Скачати" aria-label="Скачати"><Icon name="down" :size="16" /></a>
                                </li>
                            </ul>
                            <div v-if="history[m.id]" class="zv-history">
                                <div v-for="(h, i) in history[m.id]" :key="i">
                                    <small>{{ time(h.at) }} — було:</small>
                                    {{ h.text }}
                                </div>
                            </div>
                            <footer>
                                <button v-if="m.edited && !m.deleted" type="button" class="zv-link" title="Попередні версії" @click="toggleHistory(m)">змінено</button>
                                <time>{{ new Date(m.at).toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' }) }}</time>
                                <span v-if="m.mine && !m.deleted" class="zv-ticks" :class="{ read: m.readOf > 0 && m.readBy >= m.readOf }" :title="tickTitle(m)">
                                    {{ ticks(m) }}
                                    <small v-if="chat.kind === 'group' && m.readOf > 1 && m.readBy < m.readOf">{{ m.readBy }}/{{ m.readOf }}</small>
                                </span>
                            </footer>
                        </div>
                        <div v-if="!m.deleted" class="zv-actions">
                            <button type="button" title="Відповісти" aria-label="Відповісти" @click="startReply(m)"><Icon name="reply" :size="15" /></button>
                            <button v-if="m.mine" type="button" title="Змінити" aria-label="Змінити" @click="startEdit(m)"><Icon name="edit" :size="15" /></button>
                            <button
                                v-if="m.mine"
                                type="button"
                                :class="{ danger: confirmDelete === m.id }"
                                :title="confirmDelete === m.id ? 'Натисніть ще раз — видалити для всіх' : 'Видалити для всіх'"
                                aria-label="Видалити"
                                @click="remove(m)"
                            >
                                <Icon name="x" :size="15" />
                                <small v-if="confirmDelete === m.id">Видалити?</small>
                            </button>
                        </div>
                    </article>
                </div>

                <footer class="zv-compose">
                    <div v-if="replyTo || editing" class="zv-compose-ctx">
                        <Icon :name="editing ? 'edit' : 'reply'" :size="15" />
                        <span>
                            <b>{{ editing ? 'Зміна повідомлення' : replyTo?.author?.name }}</b>
                            {{ (editing ?? replyTo)?.text.slice(0, 120) }}
                        </span>
                        <button type="button" class="zv-x" aria-label="Скасувати" @click="cancelCompose"><Icon name="x" :size="15" /></button>
                    </div>
                    <ul v-if="mentionList.length" class="zv-mentions" role="listbox">
                        <li v-for="(p, i) in mentionList" :key="p.id" :class="{ on: i === mentionIndex }" role="option" @mousedown.prevent="chooseMention(p)">
                            <Avatar :person="p" :size="22" />
                            {{ p.name }}
                            <small>{{ p.position }}</small>
                        </li>
                    </ul>
                    <p v-if="chatError" class="t-error">{{ chatError }}</p>
                    <ul v-if="pending.length" class="zv-pending" aria-label="Файли до відправки">
                        <li v-for="p in pending" :key="p.key">
                            <img v-if="p.preview" :src="p.preview" alt="" />
                            <span v-else class="zv-doc-ext">{{ p.busy ? '…' : (p.file.name.split('.').pop() ?? '').toUpperCase().slice(0, 4) }}</span>
                            <span class="zv-pending-name">
                                {{ p.file.name }}
                                <small>{{ p.busy ? 'стискаємо…' : sizeLabel(p.file.size) }}</small>
                            </span>
                            <button type="button" class="zv-x" :aria-label="'Прибрати ' + p.file.name" @click="unpend(p.key)"><Icon name="x" :size="14" /></button>
                        </li>
                    </ul>
                    <div class="zv-compose-row">
                        <input ref="fileInput" type="file" multiple :accept="ZV_ACCEPT" hidden data-testid="zv-file" @change="pickFiles" />
                        <button
                            type="button"
                            class="zv-clip"
                            :disabled="!!editing"
                            title="Додати фото чи документ (до 20 МБ)"
                            aria-label="Додати файл"
                            data-testid="zv-attach"
                            @click="fileInput?.click()"
                        >
                            <Icon name="clip" :size="20" />
                        </button>
                        <textarea
                            ref="box"
                            v-model="text"
                            rows="1"
                            maxlength="4000"
                            placeholder="Повідомлення… @ — згадати"
                            aria-label="Повідомлення"
                            data-testid="zv-input"
                            @input="onInput"
                            @keydown="onKey"
                            @click="onInput"
                            @paste="onPaste"
                        />
                        <button
                            type="button"
                            class="zv-send"
                            :disabled="(!text.trim() && !(pending.length && !editing)) || sending || pending.some((p) => p.busy)"
                            aria-label="Надіслати"
                            data-testid="zv-send"
                            @click="send"
                        >
                            {{ sending && pending.length ? 'Надсилаємо…' : 'Надіслати' }}
                        </button>
                    </div>
                </footer>
            </template>
        </section>
    </div>
</template>
