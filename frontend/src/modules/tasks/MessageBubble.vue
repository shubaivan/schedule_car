<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import Avatar from './Avatar.vue'
import AttachmentPreview from './AttachmentPreview.vue'
import type { Message, Person } from './api'
import { time } from './format'
import { useTasks } from './store'
import './feed.css'

/**
 * Коментар у стрічці задачі — «робочий документ», як у ПланФіксі:
 * цитата відповіді, довгий текст згорнуто («Розгорнути»), файли мініатюрами,
 * хто прочитав (кружки), дії: відповісти, змінити своє, закріпити, ★, видалити.
 * Десктоп — панель дій при наведенні; телефон — довге натискання або «⋯».
 */
const props = defineProps<{ m: Message; cont: boolean; readers: Person[]; highlighted?: boolean }>()
const emit = defineEmits<{
    reply: [m: Message]
    edit: [m: Message]
    pin: [m: Message]
    star: [m: Message]
    remove: [m: Message]
    open: [fileId: number]
    jump: [messageId: number]
}>()

const store = useTasks()
const txt = ref<HTMLElement | null>(null)
const long = ref(false)
const collapsed = ref(false)
const sheet = ref(false)
const readList = ref(false)

/** ~12 рядків — далі згортаємо. */
const LIMIT_PX = 270

function escape(s: string): string {
    return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!)
}

/** Текст: екрановано, @згадки підсвічено. */
const html = computed(() => {
    let out = escape(props.m.text)
    for (const id of props.m.mentions) {
        const p = store.person(id)
        if (p) out = out.split('@' + escape(p.name)).join(`<span class="mention">@${escape(p.name)}</span>`)
    }
    return out
})

const readerNames = computed(() => props.readers.map((p) => p.name).join(', '))

async function measure() {
    await nextTick()
    const el = txt.value
    if (!el) return
    const isLong = el.scrollHeight > LIMIT_PX + 40
    if (isLong !== long.value) {
        long.value = isLong
        collapsed.value = isLong
    }
}

function act(kind: 'reply' | 'edit' | 'pin' | 'star' | 'remove') {
    sheet.value = false
    if (kind === 'reply') emit('reply', props.m)
    else if (kind === 'edit') emit('edit', props.m)
    else if (kind === 'pin') emit('pin', props.m)
    else if (kind === 'star') emit('star', props.m)
    else emit('remove', props.m)
}

function onContext(e: MouseEvent) {
    // Довге натискання на телефоні = contextmenu: відкриваємо свій лист дій.
    if (props.m.deleted || !window.matchMedia('(max-width: 760px)').matches) return
    e.preventDefault()
    sheet.value = true
}

watch(() => props.m.text, measure)
onMounted(measure)
</script>

<template>
    <div :id="'msg-' + m.id" class="t-msg f-msg" :class="{ mine: m.mine, cont, hl: highlighted }" @contextmenu="onContext">
        <Avatar :person="m.author" :size="32" />
        <div class="t-bubble" :class="{ deleted: m.deleted }">
            <div v-if="!m.mine && !cont" class="who" :style="{ color: m.author?.color }">
                {{ m.author?.name }}<small>{{ m.author?.position }}</small>
            </div>
            <div v-if="m.pinned && !m.deleted" class="f-pinmark">📌 Закріплено<template v-if="m.pinned.by"> · {{ m.pinned.by }}</template></div>
            <button v-if="m.replyTo && !m.deleted" type="button" class="f-quote" :style="{ borderColor: m.replyTo.authorColor ?? undefined }" @click="emit('jump', m.replyTo.id)">
                <b :style="{ color: m.replyTo.authorColor ?? undefined }">{{ m.replyTo.author ?? 'Система' }}</b>
                <span v-if="m.replyTo.deleted" class="muted">Повідомлення видалено</span>
                <span v-else>{{ m.replyTo.text || (m.replyTo.files ? '📎 файл' : '') }}</span>
            </button>
            <div v-if="m.deleted" class="txt">Повідомлення видалено</div>
            <template v-else>
                <div v-if="m.text" class="f-txtwrap" :class="{ clamp: collapsed }" :style="collapsed ? { maxHeight: LIMIT_PX + 'px' } : undefined">
                    <div ref="txt" class="txt" v-html="html" />
                </div>
                <button v-if="long" type="button" class="f-more" @click="collapsed = !collapsed">{{ collapsed ? 'Розгорнути ▾' : 'Згорнути ▴' }}</button>
                <AttachmentPreview v-if="m.attachments.length" :files="m.attachments" @open="emit('open', $event)" />
            </template>
            <div class="when">
                <span v-if="m.editedAt && !m.deleted" class="f-edited" :title="'Змінено ' + new Date(m.editedAt).toLocaleString('uk-UA')">змінено</span>
                <span v-if="m.starred && !m.deleted" class="f-star-on" title="В обраному">★</span>
                {{ time(m.createdAt) }}
                <span v-if="m.mine && !m.deleted" class="f-ticks" :class="{ read: readers.length }" :title="readers.length ? 'Прочитали: ' + readerNames : 'Надіслано'">{{ readers.length ? '✓✓' : '✓' }}</span>
                <button v-if="readers.length" type="button" class="f-readcount" :title="'Прочитали: ' + readerNames" @click="readList = !readList">👁 {{ readers.length }}</button>
                <button v-if="!m.deleted" type="button" class="f-dots" aria-label="Дії" @click="sheet = true">⋯</button>
            </div>
            <div v-if="!m.deleted" class="f-actions" role="toolbar" aria-label="Дії з повідомленням">
                <button type="button" title="Відповісти" @click="act('reply')">↩</button>
                <button v-if="m.can.edit" type="button" title="Змінити" @click="act('edit')">✎</button>
                <button type="button" :class="{ on: m.pinned }" :title="m.pinned ? 'Відкріпити' : 'Закріпити'" @click="act('pin')">📌</button>
                <button type="button" :class="{ on: m.starred }" :title="m.starred ? 'Прибрати з обраного' : 'В обране'" @click="act('star')">{{ m.starred ? '★' : '☆' }}</button>
                <button v-if="m.can.delete" type="button" title="Видалити" @click="act('remove')">🗑</button>
            </div>
            <div v-if="readList" class="f-readlist" @click="readList = false">
                <div class="h">Прочитали</div>
                <div v-for="p in readers" :key="p.id" class="r"><Avatar :person="p" :size="20" /> {{ p.name }}</div>
            </div>
        </div>
        <button v-if="readers.length" type="button" class="f-readers" :title="'Прочитали: ' + readerNames" @click="readList = !readList">
            <Avatar v-for="p in readers.slice(0, 5)" :key="p.id" :person="p" :size="18" />
            <span v-if="readers.length > 5" class="more">+{{ readers.length - 5 }}</span>
        </button>

        <Teleport to="body">
            <div v-if="sheet" class="f-sheet-wrap" @click.self="sheet = false">
                <div class="f-sheet" role="menu">
                    <div class="f-sheet-quote">{{ m.text.slice(0, 120) || (m.attachments.length ? '📎 ' + m.attachments[0].name : '') }}</div>
                    <button type="button" role="menuitem" @click="act('reply')">↩ Відповісти</button>
                    <button v-if="m.can.edit" type="button" role="menuitem" @click="act('edit')">✎ Змінити</button>
                    <button type="button" role="menuitem" @click="act('pin')">📌 {{ m.pinned ? 'Відкріпити' : 'Закріпити' }}</button>
                    <button type="button" role="menuitem" @click="act('star')">{{ m.starred ? '★ Прибрати з обраного' : '☆ В обране' }}</button>
                    <button v-if="m.can.delete" type="button" role="menuitem" class="danger" @click="act('remove')">🗑 Видалити</button>
                    <button type="button" class="cancel" @click="sheet = false">Скасувати</button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
