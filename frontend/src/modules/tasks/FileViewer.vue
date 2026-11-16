<script setup lang="ts">
import PersonName from '../../plan/PersonName.vue'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
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
 *
 * «Повернути» ⟲/⟳ (клавіші R / Shift+R) — поворот на 90° фото або всіх сторінок PDF.
 * Кут свій для кожного файлу; кут показаного файлу — в адресі ?rot=<id>:<градуси>
 * (replace, без нового кроку історії), тож оновлення сторінки й «Назад»/«Вперед» його тримають.
 */
const props = defineProps<{ files: Attachment[]; startId: number; shareOpen?: boolean }>()
const emit = defineEmits<{ close: []; change: [id: number] }>()
const route = useRoute()
const router = useRouter()

const index = ref(
    Math.max(
        0,
        props.files.findIndex((f) => f.id === props.startId),
    ),
)
const file = computed(() => props.files[index.value])
const zoom = ref(false)
const sharing = ref(false)
useHistoryLayer(sharing)
const driveUrl = computed(() => (file.value as Attachment & { driveUrl?: string | null }).driveUrl ?? null)
const pages = computed(() => (file.value?.urls.page && file.value.pages ? Array.from({ length: file.value.pages }, (_, i) => i + 1) : []))

// --- поворот ---
const rotations = reactive<Record<number, number>>({})
const ROT_RE = /^(\d+):(0|90|180|270)$/
const fromUrl = ROT_RE.exec(String(route.query.rot ?? ''))
if (fromUrl) rotations[Number(fromUrl[1])] = Number(fromUrl[2])
const rotation = computed(() => (file.value ? (rotations[file.value.id] ?? 0) : 0))
const sideways = computed(() => rotation.value % 180 !== 0)
const canRotate = computed(() => !!file.value && ((file.value.kind === 'image' && !!file.value.urls.view) || pages.value.length > 0))

function rotate(step: 1 | -1) {
    if (!canRotate.value) return
    const id = file.value.id
    const deg = (rotation.value + step * 90 + 360) % 360
    rotations[id] = deg
    void router.replace({ query: { ...route.query, rot: deg ? `${id}:${deg}` : undefined } })
}

// Співвідношення сторін сторінок PDF (ширина / висота): поки сторінка не завантажилась —
// з даних файлу або A4; для бокового повороту коробка сторінки міняє сторони.
const ratios = reactive<Record<string, number>>({})
const baseRatio = computed(() => (file.value?.width && file.value.height ? file.value.width / file.value.height : 0.7071))
function pageLoaded(n: number, e: Event) {
    const img = e.target as HTMLImageElement
    if (img.naturalWidth && img.naturalHeight) ratios[`${file.value.id}:${n}`] = img.naturalWidth / img.naturalHeight
}
function pageStyle(n: number) {
    return { '--r': String(ratios[`${file.value.id}:${n}`] ?? baseRatio.value), '--rot': `${rotation.value}deg` }
}

watch(index, () => {
    zoom.value = false
    sharing.value = false
    if (file.value && file.value.id !== props.startId) emit('change', file.value.id)
})
// «Назад»/«Вперед» між файлами — адреса змінилась, показуємо той файл.
watch(
    () => props.startId,
    (id) => {
        const i = props.files.findIndex((f) => f.id === id)
        if (i >= 0 && i !== index.value) index.value = i
    },
)

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
    if ((e.key === 'r' || e.key === 'R' || e.code === 'KeyR') && !e.ctrlKey && !e.metaKey && !e.altKey) rotate(e.shiftKey ? -1 : 1)
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
    // ?share=1 — відкрити панель уже ПІСЛЯ підписки useHistoryLayer: на телефоні вона
    // отримує свій крок історії, і «Назад» згортає її, а не закриває сторінку.
    if (props.shareOpen) sharing.value = true
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
                    <template v-if="file.pages">· {{ pagesLabel(file.pages) }}</template>
                    <template v-if="file.uploadedBy">
                        ·
                        <PersonName :name="file.uploadedBy" />
                    </template>
                    <template v-if="files.length > 1">· {{ index + 1 }} з {{ files.length }}</template>
                </div>
            </div>
            <template v-if="canRotate">
                <button type="button" class="rot" title="Повернути ліворуч (Shift+R)" aria-label="Повернути ліворуч" @click="rotate(-1)">
                    ⟲
                    <span class="hide-sm">Повернути</span>
                </button>
                <button type="button" class="rot" title="Повернути праворуч (R)" aria-label="Повернути праворуч" @click="rotate(1)">
                    ⟳
                    <span class="hide-sm">Повернути</span>
                </button>
            </template>
            <a v-if="file.kind === 'pdf'" :href="file.urls.inline" target="_blank" rel="noopener" title="Відкрити у браузері">
                <span class="show-sm" aria-hidden="true">⧉</span>
                <span class="hide-sm">Відкрити</span>
            </a>
            <a v-if="driveUrl" :href="driveUrl" target="_blank" rel="noopener noreferrer" class="hide-sm">Google Drive</a>
            <a :href="file.urls.download">
                ⬇
                <span class="hide-sm">Скачати</span>
            </a>
            <button type="button" class="share" :class="{ on: sharing }" :aria-pressed="sharing" @click="sharing = !sharing">
                ↗
                <span class="hide-sm">Поділитися</span>
            </button>
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
                    :class="{ zoom, sideways }"
                    :style="{ cursor: zoom ? 'zoom-out' : 'zoom-in', transform: rotation ? `rotate(${rotation}deg)` : undefined }"
                    @click="zoom = !zoom"
                />

                <div v-else-if="pages.length" :key="'p' + file.id" class="scroller" @click.self="emit('close')">
                    <template v-for="n in pages" :key="n">
                        <div class="pagebox" :class="{ sideways }" :style="pageStyle(n)">
                            <img class="page" :src="(file.urls.page ?? '') + n" :alt="'Сторінка ' + n" loading="lazy" @load="pageLoaded(n, $event)" />
                        </div>
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
                :id="file.id"
                :key="'s' + file.id"
                :type="fileTypeOf(file)"
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
.t-viewer-body {
    flex: 1;
    min-height: 0;
    display: flex;
    position: relative;
}
.t-viewer-body > .t-viewer-stage {
    flex: 1;
    min-width: 0;
}
/* Поворот: сцена — контейнер, тож боком фото вписується за поміняними сторонами (cqw/cqh). */
.t-viewer-body > .t-viewer-stage {
    container-type: size;
}
.t-viewer-stage > img {
    transition: transform 0.15s;
}
.t-viewer-stage > img.sideways:not(.zoom) {
    max-width: 100cqh;
    max-height: 100cqw;
}
/* Сторінка PDF у коробці: 0°/180° — як була; 90°/270° — коробка з поміняними сторонами,
   а сама сторінка (ширина = висота коробки) повернута по центру. */
.t-viewer-stage .scroller .pagebox {
    width: min(900px, 100%);
    flex: none;
    position: relative;
}
.t-viewer-stage .scroller .pagebox img.page {
    display: block;
    width: 100%;
    transform: rotate(var(--rot, 0deg));
}
.t-viewer-stage .scroller .pagebox.sideways {
    aspect-ratio: 1 / var(--r);
}
.t-viewer-stage .scroller .pagebox.sideways img.page {
    position: absolute;
    left: 50%;
    top: 50%;
    width: calc(100% * var(--r));
    max-width: none;
    transform: translate(-50%, -50%) rotate(var(--rot));
}
.t-viewer-bar button.share.on {
    background: rgba(255, 255, 255, 0.3);
}
.t-viewer-bar .show-sm {
    display: none;
}
@media (max-width: 760px) {
    .t-viewer-bar .hide-sm {
        display: none;
    }
    .t-viewer-bar .show-sm {
        display: inline;
    }
    /* Телефон: кнопки вужчі, щоб назві файлу лишалось місце. */
    .t-viewer-bar {
        gap: 0.4rem;
        padding: 0.5rem 0.6rem;
    }
    .t-viewer-bar a,
    .t-viewer-bar button {
        padding: 0.45rem 0.6rem;
    }
}
</style>
