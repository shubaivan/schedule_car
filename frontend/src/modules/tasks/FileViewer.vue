<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { Attachment } from './api'
import { extClass, pagesLabel } from './files'
import SharePanel from '../../plan/SharePanel.vue'
import { fileTypeOf } from '../../plan/files'
import { useHistoryLayer } from '../../plan/useHistoryLayer'
import { typing } from '../../plan/layers'

/**
 * Перегляд файлів задачі на весь екран: фото — лайтбокс зі свайпом,
 * PDF і офісні — сторінки одна під одною (як у читалці), решта — скачати.
 * Гортається по всіх файлах задачі; Esc закриває, ←/→ — сусідні.
 *
 * Не модалка: відкритий файл живе в адресі (?f=… або /files/:id), тож «Назад»
 * закриває переглядач. change — показано інший файл (сторінка оновлює ?f= без нового кроку історії).
 *
 * «Поділитися» — панель праворуч (на телефоні — на весь екран поверх файлу) у тій самій
 * читалці; на телефоні вона — свій крок історії (useHistoryLayer), «Назад» згортає її.
 * Файли задач, заявок і складу — тип береться з самого Attachment (fileTypeOf).
 */
const props = defineProps<{ files: Attachment[]; startId: number; shareOpen?: boolean }>()
const emit = defineEmits<{ close: []; change: [id: number] }>()

const index = ref(Math.max(0, props.files.findIndex((f) => f.id === props.startId)))
const file = computed(() => props.files[index.value])
const zoom = ref(false)
const sharing = ref(!!props.shareOpen)
useHistoryLayer(sharing)
const driveUrl = computed(() => (file.value as Attachment & { driveUrl?: string | null }).driveUrl ?? null)
const pages = computed(() => (file.value?.urls.page && file.value.pages ? Array.from({ length: file.value.pages }, (_, i) => i + 1) : []))

watch(index, () => {
    zoom.value = false
    sharing.value = false
    if (file.value && file.value.id !== props.startId) emit('change', file.value.id)
})
// «Назад»/«Вперед» між файлами — адреса змінилась, показуємо той файл.
watch(() => props.startId, (id) => {
    const i = props.files.findIndex((f) => f.id === id)
    if (i >= 0 && i !== index.value) index.value = i
})

function go(step: number) {
    if (props.files.length < 2) return
    index.value = (index.value + step + props.files.length) % props.files.length
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        if (sharing.value) sharing.value = false
        else emit('close')
        return
    }
    if (typing(e.target)) return
    if (e.key === 'ArrowRight') go(1)
    if (e.key === 'ArrowLeft') go(-1)
}

let touchX = 0
let touchY = 0
function touchStart(e: TouchEvent) {
    touchX = e.touches[0].clientX
    touchY = e.touches[0].clientY
}
function touchEnd(e: TouchEvent) {
    if (pages.value.length) return
    const dx = e.changedTouches[0].clientX - touchX
    const dy = e.changedTouches[0].clientY - touchY
    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy)) go(dx < 0 ? 1 : -1)
    else if (dy > 90 && Math.abs(dy) > Math.abs(dx)) emit('close')
}

onMounted(() => {
    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'
})
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey)
    document.body.style.overflow = ''
})
</script>

<template>
    <div class="t-viewer" role="region" aria-label="Перегляд файлу" @click.self="emit('close')">
        <div class="t-viewer-bar">
            <div class="sp">
                <div class="name">{{ file.name }}</div>
                <div class="meta">
                    {{ file.sizeLabel }}
                    <template v-if="file.pages"> · {{ pagesLabel(file.pages) }}</template>
                    <template v-if="file.uploadedBy"> · {{ file.uploadedBy }}</template>
                    <template v-if="files.length > 1"> · {{ index + 1 }} з {{ files.length }}</template>
                </div>
            </div>
            <a v-if="file.kind === 'pdf'" :href="file.urls.inline" target="_blank" rel="noopener">Відкрити</a>
            <a v-if="driveUrl" :href="driveUrl" target="_blank" rel="noopener noreferrer" class="hide-sm">Google Drive</a>
            <a :href="file.urls.download">⬇ <span class="hide-sm">Скачати</span></a>
            <button type="button" class="share" :class="{ on: sharing }" :aria-pressed="sharing" @click="sharing = !sharing">↗ <span class="hide-sm">Поділитися</span></button>
            <button type="button" aria-label="Закрити" @click="emit('close')">✕</button>
        </div>

        <div class="t-viewer-body">
        <div class="t-viewer-stage" @touchstart.passive="touchStart" @touchend="touchEnd" @click.self="emit('close')">
            <button v-if="files.length > 1" class="t-nav prev" type="button" aria-label="Попередній" @click="go(-1)">‹</button>

            <img
                v-if="file.kind === 'image' && file.urls.view"
                :key="file.id"
                :src="file.urls.view"
                :alt="file.name"
                :class="{ zoom }"
                :style="zoom ? { cursor: 'zoom-out' } : { cursor: 'zoom-in' }"
                @click="zoom = !zoom"
            />

            <div v-else-if="pages.length" :key="'p' + file.id" class="scroller" @click.self="emit('close')">
                <template v-for="n in pages" :key="n">
                    <img class="page" :src="(file.urls.page ?? '') + n" :alt="'Сторінка ' + n" loading="lazy" />
                    <div class="pg">{{ n }} / {{ pages.length }}</div>
                </template>
            </div>

            <div v-else class="nopv">
                <div class="big" :class="extClass(file)">{{ file.ext || 'FILE' }}</div>
                <div v-if="file.preview === 'pending'">Готуємо превʼю…</div>
                <div v-else>Для цього файлу перегляду немає — його можна скачати.</div>
                <a class="t-btn primary" :href="file.urls.download">⬇ Скачати {{ file.sizeLabel }}</a>
            </div>

            <button v-if="files.length > 1" class="t-nav next" type="button" aria-label="Наступний" @click="go(1)">›</button>
        </div>
        <SharePanel
            v-if="sharing"
            :key="'s' + file.id"
            :type="fileTypeOf(file)"
            :id="file.id"
            :name="file.name"
            :mime="file.mime"
            :fetch-url="file.urls.inline"
            @close="sharing = false"
        />
        </div>

        <div v-if="files.length > 1" class="t-viewer-strip">
            <button v-for="(f, i) in files" :key="f.id" type="button" :class="{ on: i === index }" :title="f.name" @click="index = i">
                <img v-if="f.urls.thumb" :src="f.urls.thumb" alt="" />
                <span v-else class="ico" :class="extClass(f)">{{ f.ext }}</span>
            </button>
        </div>
    </div>
</template>

<style>
.t-viewer-body { flex: 1; min-height: 0; display: flex; position: relative; }
.t-viewer-body > .t-viewer-stage { flex: 1; min-width: 0; }
.t-viewer-bar button.share.on { background: rgba(255, 255, 255, .3); }
@media (max-width: 760px) {
    .t-viewer-bar .hide-sm { display: none; }
}
</style>
