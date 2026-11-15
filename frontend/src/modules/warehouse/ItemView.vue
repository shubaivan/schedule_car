<script setup lang="ts">
import PersonName from '../../plan/PersonName.vue'
import { money } from '../../plan/uk'
import { vStickyHead } from '../../plan/stickyHead'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useSession } from '../../app/store'
import TaskChat from '../tasks/TaskChat.vue'
import Icon from '../tasks/shell/Icon.vue'
import type { FeedSubject } from '../tasks/feedApi'
import { formatDate } from '../../shared/format'
import { itemFeed, qty, whApi, type WhDocument, type WhItem, type WhMeta } from './api'
import { accessError } from './access'
import { notify, undoable } from '../../plan/undo'
import '../supply/supply.css'
import './warehouse.css'

/**
 * Картка позиції складу — як задача: шапка, стрічка подій (той самий TaskChat, лише
 * події: рухи, зміни, документи, скани QR), праворуч залишки, деталі, історія рухів,
 * згадки в задачах і заявках, QR і документи.
 *
 * Дії — не вікна, а сторінки: «Відвантажити» відкриває /sklad/movements/new?type=…&item=…,
 * «Змінити» — /sklad/items/:id/edit. Прибрати документ — тост «Скасувати», без вікна підтвердження.
 * «Відкрив картку» пишемо в журнал ОДИН раз на відкриття (сервер ще й зрізає повтори) —
 * не на кожен рендер чи опитування стрічки.
 */
const props = defineProps<{ id: string; embedded?: boolean }>()
const emit = defineEmits<{ close: []; changed: [] }>()
const session = useSession()
const route = useRoute()

const item = ref<WhItem | null>(null)
const meta = ref<WhMeta | null>(null)
const error = ref('')
const notFound = ref(false)
const tab = ref<'feed' | 'details' | 'files'>('feed')
const sideOpen = ref(false)
const uploading = ref(false)
const docType = ref('photo')
const removing = ref<Set<number>>(new Set())

const subject = reactive<FeedSubject>({ id: Number(props.id), status: '', messages: [], files: [], reads: [], readUpTo: null, can: { comment: false } })
const feedReady = ref(false)
const feedApi = computed(() => itemFeed(Number(props.id)))

const canEdit = computed(() => !!meta.value?.can.edit)
const docs = computed(() => (item.value?.documents ?? []).filter((d) => !removing.value.has(d.id)))
const photos = computed(() => docs.value.filter((d) => d.photo))
const files = computed(() => docs.value.filter((d) => !d.photo))
const off = computed(() => item.value?.state === 'written_off')
const here = computed(() => item.value?.places[0]?.site ?? null)

/** Що можна зробити з позицією — кожна дія веде на сторінку нового руху з уже вибраною позицією. */
const actions = computed(() => {
    const i = item.value
    if (!i || off.value) return []
    const unitSite = i.tracking === 'unit' ? here.value : null
    // «Звідки» підставляємо, коли воно однозначне: поштучна — там, де є; кількістю — єдине місце потрібного роду.
    const only = (kind?: 'warehouse' | 'site') => {
        const at = i.places.filter((p) => !kind || p.site.kind === kind)
        return at.length === 1 ? at[0].site.id : undefined
    }
    const on = (kind: 'warehouse' | 'site') => i.places.some((p) => p.site.kind === kind)
    const out: { type: string; label: string; from?: number }[] = []
    if (i.tracking === 'bulk' || !unitSite) out.push({ type: 'receipt', label: 'Надходження' })
    if (on('warehouse')) out.push({ type: 'shipment', label: 'Відвантажити', from: only('warehouse') })
    if (on('site')) out.push({ type: 'return', label: 'Повернути', from: only('site') })
    if (i.places.length) out.push({ type: 'transfer', label: 'Перемістити', from: only() })
    if (i.places.length) out.push({ type: 'writeoff', label: 'Списати', from: only() })
    return out
})
const moveTo = (a: { type: string; from?: number }) => ({ name: 'warehouse-movement-new', query: { type: a.type, item: String(props.id), ...(a.from ? { from: String(a.from) } : {}) } })

// Кожне завантаження має номер: відповідь про попередню позицію (/full, гортання) не перезапише нову.
let seq = 0
async function load() {
    const mine = ++seq
    try {
        const [i, f, m] = await Promise.all([whApi.item(Number(props.id)), whApi.feed(Number(props.id)), whApi.meta()])
        if (mine !== seq) return
        item.value = i
        meta.value = m
        subject.status = f.status
        subject.now = f.now
        subject.messages.splice(0, subject.messages.length, ...f.messages)
        feedReady.value = true
        notFound.value = false
        error.value = ''
    } catch (e) {
        if (mine !== seq) return
        const msg = accessError(e) || session.handle(e)
        if (/40[34]|не знайдено/i.test(msg) || accessError(e)) notFound.value = true
        error.value = msg
    }
}

async function upload(e: Event) {
    const input = e.target as HTMLInputElement
    const list = Array.from(input.files ?? [])
    input.value = ''
    if (!list.length) return
    uploading.value = true
    error.value = ''
    try {
        item.value = await whApi.uploadDocuments(Number(props.id), list, docType.value)
        emit('changed')
    } catch (err) {
        error.value = session.handle(err)
        // Частина файлів могла лягти — показуємо картку як є.
        whApi.item(Number(props.id)).then((i) => (item.value = i)).catch(() => undefined)
    } finally {
        uploading.value = false
    }
}

/** Прибрати документ: зникає одразу, запит — через 8 секунд, якщо не натиснули «Скасувати». */
function removeDoc(d: WhDocument) {
    removing.value = new Set([...removing.value, d.id])
    undoable(`«${d.name}» прибрано`, {
        undo: () => {
            const next = new Set(removing.value)
            next.delete(d.id)
            removing.value = next
        },
        commit: async () => {
            try {
                await whApi.removeDocument(d.id)
                if (item.value) item.value.documents = item.value.documents.filter((x) => x.id !== d.id)
            } catch (e) {
                // Не вдалось — документ на місці, показуємо його знову (тост помилки покаже undo.ts).
                const next = new Set(removing.value)
                next.delete(d.id)
                removing.value = next
                throw e
            }
        },
    })
}

async function share() {
    const url = item.value?.shareUrl
    if (!url) return
    try {
        if (navigator.share) await navigator.share({ title: item.value?.label, url })
        else {
            await navigator.clipboard.writeText(url)
            notify('Посилання на картку в боті скопійовано')
        }
    } catch {
        /* людина закрила вікно «Поділитися» */
    }
}

const statusTone = (s: string) => (s === 'closed' || s === 'done' || s === 'rejected' ? 'closed' : '')

watch(() => props.id, (id) => {
    feedReady.value = false
    subject.id = Number(id)
    subject.messages.splice(0)
    item.value = null
    load()
    whApi.viewed(Number(id)).catch(() => undefined)
})

onMounted(() => {
    load()
    // Один раз на відкриття картки — див. ViewJournal на бекенді.
    whApi.viewed(Number(props.id)).catch(() => undefined)
})
// Телефон: панель вкладки починається одразу під смугою вкладок — прокрутку стрічки скидаємо.
watch(tab, (t) => {
    if (t !== 'feed' && window.matchMedia('(max-width: 760px)').matches) window.scrollTo({ top: 0 })
})
</script>

<template>
    <div class="t-page w-item-view" :class="{ 'p-embedded': embedded }">
        <div v-if="notFound" class="t-panel t-empty">
            <p>{{ error || 'Позицію не знайдено.' }}</p>
            <button v-if="embedded" type="button" class="t-btn" @click="emit('close')">← Назад</button>
            <router-link v-else :to="{ name: 'warehouse-items' }" class="t-btn">← До складу</router-link>
        </div>
        <div v-else-if="!item || !feedReady" class="t-empty">{{ error || 'Завантаження…' }}</div>

        <div v-else class="t-task" :class="{ 'side-tab': tab !== 'feed' }">
            <section v-sticky-head class="t-task-main">
                <div class="p-thead">
                    <div class="p-thead-top">
                        <button v-if="embedded" type="button" class="p-icon-btn p-back" title="Назад (Esc)" aria-label="Назад" @click="emit('close')"><Icon name="back" /></button>
                        <router-link v-else :to="{ name: 'warehouse-items', query: route.query }" class="p-icon-btn p-back" title="До складу" aria-label="До складу"><Icon name="back" /></router-link>
                        <span class="num">{{ item.inventoryNumber }}</span>
                        <span class="client">· {{ item.category.emoji }} {{ item.category.name }}</span>
                        <span class="sp" />
                        <router-link v-if="canEdit" :to="{ name: 'warehouse-item-edit', params: { id: item.id } }" class="p-icon-btn" title="Змінити картку" aria-label="Змінити картку"><Icon name="edit" :size="18" /></router-link>
                        <router-link v-if="embedded" :to="{ name: 'warehouse-item-full', params: { id: item.id } }" class="p-icon-btn" title="Відкрити окремо" aria-label="Відкрити окремо"><Icon name="monitor" :size="18" /></router-link>
                        <button type="button" class="p-icon-btn p-side-toggle" :class="{ on: sideOpen }" title="Залишки, деталі, документи" aria-label="Деталі" @click="sideOpen = !sideOpen"><Icon name="info" :size="18" /></button>
                        <button v-if="embedded" type="button" class="p-icon-btn p-close" title="Закрити (Esc)" aria-label="Закрити" @click="emit('close')"><Icon name="x" /></button>
                    </div>
                    <h1 class="p-ttitle"><span :class="{ closed: off }">{{ item.name }}</span></h1>
                    <div class="p-thead-meta">
                        <span class="st w-pill" :class="'w-st-' + item.state">{{ item.stateLabel }}</span>
                        <b class="w-big">{{ item.tracking === 'unit' ? (here ? here.label : 'не на складі') : 'Усього: ' + qty(item.total, item.unit) }}</b>
                        <span class="p-muted">{{ item.trackingLabel }}</span>
                    </div>
                    <div v-if="actions.length && canEdit" class="p-moves w-actions" role="group" aria-label="Записати рух">
                        <router-link v-for="a in actions" :key="a.type" :to="moveTo(a)" class="p-chip">{{ a.label }}</router-link>
                    </div>
                </div>

                <div v-if="error" class="t-error" style="margin: 6px 12px 0">{{ error }}</div>
                <div class="t-mobile-tabs">
                    <button type="button" :class="{ on: tab === 'feed' }" @click="tab = 'feed'">🕘 Історія</button>
                    <button type="button" :class="{ on: tab === 'details' }" @click="tab = 'details'">📦 Залишки</button>
                    <button type="button" :class="{ on: tab === 'files' }" @click="tab = 'files'">📎 Файли · {{ docs.length }}</button>
                </div>

                <div v-if="!subject.messages.length && tab === 'feed'" class="t-empty w-feed-empty">Подій ще немає — тут з'являться рухи, зміни картки й скани наклейки.</div>
                <TaskChat :key="subject.id" :task="subject" :meta="null" :api="feedApi" :hidden="tab !== 'feed'" />
            </section>

            <aside class="t-side" :class="{ 'hide-mobile': tab === 'feed', open: sideOpen }">
                <div v-if="tab !== 'files'" class="t-panel">
                    <h2>Де зараз <span class="count">{{ item.tracking === 'unit' ? '' : qty(item.total, item.unit) }}</span></h2>
                    <div v-if="item.places.length" class="w-places">
                        <div v-for="p in item.places" :key="p.site.id" class="w-place">
                            <span>{{ p.site.label }}<small v-if="p.since" class="p-muted"> · з {{ formatDate(p.since) }}</small></span>
                            <b v-if="item.tracking === 'bulk'">{{ qty(p.quantity, item.unit) }}</b>
                        </div>
                    </div>
                    <p v-else class="t-hint" style="padding: 0">{{ off ? 'Списано.' : 'Ніде: ще не надійшло або все роздано.' }}</p>
                </div>

                <div v-if="tab !== 'files'" class="t-panel">
                    <h2>Деталі</h2>
                    <dl class="t-dl">
                        <dt>Облік</dt><dd>{{ item.trackingLabel }}, {{ item.unit }}</dd>
                        <template v-for="(v, k) in item.attributes" :key="k"><dt>{{ k }}</dt><dd>{{ v }}</dd></template>
                        <template v-if="item.serialNumber"><dt>Серійний №</dt><dd>{{ item.serialNumber }}</dd></template>
                        <template v-if="item.supplier"><dt>Постачальник</dt><dd>{{ item.supplier.name }}</dd></template>
                        <template v-if="item.purchasePrice"><dt>Ціна</dt><dd>{{ money(item.purchasePrice) }}</dd></template>
                        <template v-if="item.purchasedAt"><dt>Куплено</dt><dd>{{ formatDate(item.purchasedAt) }}</dd></template>
                        <template v-if="item.rentalRate"><dt>Оренда</dt><dd>{{ money(item.rentalRate) }}/доба</dd></template>
                        <template v-if="item.createdBy"><dt>Завів</dt><dd><PersonName :person="item.createdBy" /></dd></template>
                    </dl>
                    <div v-if="item.description" class="t-desc" style="margin-top: 8px">{{ item.description }}</div>
                </div>

                <div v-if="tab !== 'files'" class="t-panel">
                    <h2>Рухи <span class="count">{{ item.history.length }}</span></h2>
                    <div v-if="item.history.length" class="w-history">
                        <router-link v-for="m in item.history.slice(0, 12)" :key="m.id" :to="{ name: 'warehouse-movements', params: { id: m.id } }" class="w-hist">
                            <span>{{ m.emoji }} {{ formatDate(m.occurredAt) }} · {{ m.route }}</span>
                            <b v-if="m.quantity !== null">{{ item.tracking === 'bulk' ? qty(m.quantity, item.unit) : '' }}</b>
                        </router-link>
                        <router-link v-if="item.history.length > 12" :to="{ name: 'warehouse-movements', query: { item: String(item.id) } }" class="p-link">Усі рухи позиції</router-link>
                    </div>
                    <p v-else class="t-hint" style="padding: 0">Рухів ще не було.</p>
                </div>

                <div v-if="tab !== 'files' && item.mentions && (item.mentions.tasks.length || item.mentions.supply.length)" class="t-panel">
                    <h2>Згадки</h2>
                    <p class="t-hint" style="padding: 0 0 6px">Задачі й заявки, де є «{{ item.inventoryNumber }}» або марка з назви.</p>
                    <router-link v-for="t in item.mentions.tasks" :key="'t' + t.id" :to="{ name: 'tasks-board', params: { id: t.id } }" class="w-hist">
                        <span :class="{ closed: t.closed }">📋 {{ t.title }}</span>
                    </router-link>
                    <router-link v-for="r in item.mentions.supply" :key="'s' + r.id" :to="{ name: 'supply-board', params: { id: r.id } }" class="w-hist">
                        <span :class="statusTone(r.status)">🛒 № {{ r.number }} · {{ r.title }}</span>
                    </router-link>
                </div>

                <div v-if="tab !== 'files'" class="t-panel w-qr">
                    <h2>QR-наклейка</h2>
                    <img :src="item.qr" :alt="'QR ' + item.inventoryNumber" width="132" height="132" />
                    <p class="t-hint" style="padding: 0">Скан відкриває картку в боті — і потрапляє в історію.</p>
                    <div class="p-small">
                        <a :href="item.labels" target="_blank" rel="noopener">Друк наклейки</a>
                        <template v-if="item.shareUrl"> · <button type="button" class="p-link" @click="share">Поділитися</button></template>
                    </div>
                </div>

                <div v-if="tab !== 'details'" class="t-panel">
                    <h2>Фото й документи <span class="count">{{ docs.length }}</span></h2>
                    <div v-if="photos.length" class="w-photos">
                        <a v-for="d in photos" :key="d.id" :href="d.url" target="_blank" rel="noopener" class="w-photo" :title="d.name">
                            <img :src="d.url" :alt="d.name" loading="lazy" />
                            <button v-if="canEdit" type="button" class="w-photo-x" title="Прибрати" aria-label="Прибрати фото" @click.prevent="removeDoc(d)"><Icon name="x" :size="14" /></button>
                        </a>
                    </div>
                    <div v-if="files.length" class="p-docs">
                        <div v-for="d in files" :key="d.id" class="p-doc">
                            <div class="p-doc-info">
                                <a :href="d.url" target="_blank" rel="noopener" class="p-doc-name">{{ d.name }}</a>
                                <div class="p-muted p-small">{{ d.typeLabel }} · {{ d.sizeLabel }}<template v-if="d.uploadedBy"> · <PersonName :name="d.uploadedBy" /></template></div>
                                <div class="p-small">
                                    <a :href="d.download">Скачати</a>
                                    <template v-if="d.driveUrl"> · <a :href="d.driveUrl" target="_blank" rel="noopener">Google Drive</a></template>
                                    <template v-if="canEdit"> · <button type="button" class="p-link danger" @click="removeDoc(d)">Прибрати</button></template>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="!docs.length" class="t-empty">Фото й документів ще немає.</div>
                    <div v-if="canEdit" class="p-upload">
                        <select v-model="docType" class="t-select" aria-label="Тип документа">
                            <option v-for="t in meta?.documentTypes ?? []" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                        <label class="p-btn">
                            <Icon name="clip" :size="16" />{{ uploading ? 'Завантаження…' : 'Додати' }}
                            <input type="file" multiple hidden accept=".pdf,.jpg,.jpeg,.png,.heic,.webp,.doc,.docx,.xls,.xlsx" :disabled="uploading" @change="upload" />
                        </label>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>
