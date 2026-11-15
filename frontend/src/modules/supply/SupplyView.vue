<script setup lang="ts">
import PersonName from '../../plan/PersonName.vue'
import { money, quantity } from '../../plan/uk'
import { vStickyHead } from '../../plan/stickyHead'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, call, ATTACHMENT_TYPES, type ApiSupplier, type PurchasePayload, type SupplyRequest } from '../../app/api'
import { useSession } from '../../app/store'
import TaskChat from '../tasks/TaskChat.vue'
import FileViewer from '../tasks/FileViewer.vue'
import Icon from '../tasks/shell/Icon.vue'
import SupplierPicker from '../../shared/SupplierPicker.vue'
import { extClass } from '../tasks/files'
import { formatDate, formatDateTime } from '../../shared/format'
import { supplyFeed, supplyFeedApi, type SupplyFeedResponse, type SupplyFile } from './feed'
import type { FeedSubject } from '../tasks/feedApi'
import { DECISION_FROM, tone } from './status'
import { closeLayer, openLayer } from '../../plan/layers'
import { undoable } from '../../plan/undo'
import { useHistoryLayer } from '../../plan/useHistoryLayer'
import './supply.css'

/**
 * Заявка в «Плані» — як задача: шапка зі статусом, стрічка (той самий TaskChat
 * і MessageBubble через адаптер SupplyFeed), праворуч деталі, закупівля й документи.
 * Права ті самі, що в боті й старій картці: статуси рухає менеджер, рішення про гроші —
 * директор (allowedTransitions уже відфільтровані бекендом під роль), коментує автор,
 * менеджер і директор, документи носять автор і менеджер.
 *
 * embedded — права половина розділеного екрана. Відкритий документ — ?f= в адресі.
 */
const props = defineProps<{ id: string; embedded?: boolean }>()
const emit = defineEmits<{ close: []; changed: [r: SupplyRequest] }>()
const session = useSession()
const route = useRoute()
const router = useRouter()

const request = ref<SupplyRequest | null>(null)
const error = ref('')
const notFound = ref(false)
const busy = ref(false)
const tab = ref<'chat' | 'details' | 'files'>('chat')
const sideOpen = ref(false)
const movesOpen = ref(false)
// Телефон: «Назад» спершу згортає рядок статусів.
useHistoryLayer(movesOpen)
const rejecting = ref(false)
const reason = ref('')
const note = ref('')

/** Суб'єкт стрічки для TaskChat — заповнює й оновлює GET …/feed. */
const subject = reactive<FeedSubject & { can: SupplyFeedResponse['can'] }>({
    id: Number(props.id),
    status: '',
    messages: [],
    files: [],
    reads: [],
    readUpTo: null,
    can: { comment: false, attach: false, removeFiles: false },
})
const feedReady = ref(false)
const chatRef = ref<InstanceType<typeof TaskChat> | null>(null)
/** Документ, видалений у стрічці чи в панелі, ховаємо тут теж, поки тост дає шанс скасувати. */
const files = computed(() => (subject.files as SupplyFile[]).filter((f) => !chatRef.value?.removingFile(f.id)))

function applyFeed(f: SupplyFeedResponse) {
    // Статус змінився деінде (інший менеджер, директор у боті) — перечитуємо картку.
    const moved = feedReady.value && !!subject.status && f.status !== subject.status
    subject.status = f.status
    subject.now = f.now
    subject.can = f.can
    if (!feedReady.value) {
        subject.messages.splice(0, subject.messages.length, ...f.messages)
        subject.files.splice(0, subject.files.length, ...f.files)
        feedReady.value = true
    }
    if (moved) onChatReload()
}
const feedApi = computed(() => supplyFeed(Number(props.id), { onFeed: applyFeed }))

const canManage = computed(() => session.isManager())
const transitions = computed(() => request.value?.allowedTransitions ?? [])
const isDecision = computed(() => !!request.value && DECISION_FROM.includes(request.value.status))
const decisions = computed(() => (isDecision.value ? transitions.value : []))
const moves = computed(() => (isDecision.value ? [] : transitions.value))
const accents = computed(() => (session.meta?.accents ?? []).filter((a) => a.value !== 'none'))

async function load() {
    try {
        const [r, f] = await Promise.all([api.request(Number(props.id)), supplyFeedApi.get(Number(props.id))])
        request.value = r
        applyFeed(f)
        notFound.value = false
        error.value = ''
    } catch (e) {
        const msg = session.handle(e)
        if (/40[34]|не знайдено/i.test(msg)) notFound.value = true
        error.value = msg
    }
}

/** Після дії: картку — з відповіді, стрічку — свіжим запитом (там з'явилась подія). */
async function refreshFeed() {
    try {
        const f = await supplyFeedApi.get(Number(props.id))
        subject.messages.splice(0, subject.messages.length, ...f.messages)
        subject.files.splice(0, subject.files.length, ...f.files)
        applyFeed(f)
    } catch {
        /* наступне опитування підтягне */
    }
}

async function run(action: () => Promise<SupplyRequest>) {
    busy.value = true
    error.value = ''
    try {
        request.value = await action()
        emit('changed', request.value)
        await refreshFeed()
        return true
    } catch (e) {
        error.value = session.handle(e)
        return false
    } finally {
        busy.value = false
    }
}

async function move(to: string) {
    if (to === 'rejected') {
        rejecting.value = true
        return
    }
    movesOpen.value = false
    await run(() => api.changeStatus(Number(props.id), to, note.value.trim() || undefined))
    note.value = ''
}

async function reject() {
    if (!reason.value.trim()) return
    if (await run(() => api.changeStatus(Number(props.id), 'rejected', reason.value.trim()))) {
        rejecting.value = false
        movesOpen.value = false
        reason.value = ''
    }
}

const setAccent = (value: string) => run(() => api.setAccent(Number(props.id), value))

// --- закупівля ---
const suppliers = ref<ApiSupplier[]>([])
const addingPurchase = ref(false)
const blankPurchase = (): PurchasePayload => ({ supplierId: 0, totalAmount: '', quantity: '', pricePerUnit: '', payment: 'bank', vatIncluded: true, invoiceNumber: '' })
const purchase = ref<PurchasePayload>(blankPurchase())

async function openPurchase() {
    addingPurchase.value = true
    purchase.value = blankPurchase()
    try {
        suppliers.value = (await api.suppliers({ active: true })).items
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function savePurchase() {
    if (!purchase.value.supplierId) return
    if (await run(() => api.addPurchase(Number(props.id), purchase.value))) addingPurchase.value = false
}

/** Прибрати закупівлю — з тостом «Скасувати»: запит піде за 8 секунд. */
function removePurchase(purchaseId: number) {
    const r = request.value
    if (!r) return
    const kept = r.purchases ?? []
    r.purchases = kept.filter((p) => p.id !== purchaseId)
    undoable('Закупівлю прибрано', {
        undo: () => {
            if (request.value === r) r.purchases = kept
        },
        // keepalive: запит доживе, навіть якщо вкладку закривають під час «Скасувати».
        commit: () => run(() => call<SupplyRequest>(`/api/supply/requests/${props.id}/purchases/${purchaseId}`, { method: 'DELETE', keepalive: true })),
    })
}

// --- документи ---
const attachmentType = ref('invoice')
const uploading = ref(false)

async function upload(e: Event) {
    const input = e.target as HTMLInputElement
    const list = Array.from(input.files ?? [])
    input.value = ''
    if (!list.length) return
    uploading.value = true
    error.value = ''
    try {
        const f = await supplyFeedApi.post(Number(props.id), '', list, attachmentType.value)
        subject.messages.splice(0, subject.messages.length, ...f.messages)
        subject.files.splice(0, subject.files.length, ...f.files)
        applyFeed(f)
    } catch (err) {
        error.value = session.handle(err)
    } finally {
        uploading.value = false
    }
}

/** Прибрати з панелі «Документи» — той самий шлях, що й 🗑 у стрічці (один тост, одне видалення). */
function removeFile(file: SupplyFile) {
    const m = subject.messages.find((x) => x.attachments.some((a) => a.id === file.id))
    if (m) chatRef.value?.remove(m)
}

// --- перегляд файлу: ?f= в адресі, «Назад» закриває ---
const viewer = computed(() => {
    const id = Number(route.query.f)
    return id && files.value.some((f) => f.id === id) ? id : null
})
const openFile = (id: number) => openLayer(router, route, 'f', id)
const closeFile = () => closeLayer(router, route, 'f')
const showFile = (id: number) => router.replace({ query: { ...route.query, f: String(id) } })

function onChatReload() {
    api.request(Number(props.id))
        .then((r) => {
            request.value = r
            emit('changed', r)
        })
        .catch(() => undefined)
}

// Та сама сторінка, інша заявка (/supply/:id/full): стрічка й адаптер — уже нової заявки.
watch(
    () => props.id,
    (id) => {
        feedReady.value = false
        subject.id = Number(id)
        subject.status = ''
        subject.messages.splice(0)
        subject.files.splice(0)
        load()
    },
)

// ?f= на файл, якого немає (видалили, чуже посилання) — прибираємо з адреси, щоб не глушив клавіші.
watch([() => route.query.f, feedReady, () => subject.files.length], () => {
    if (feedReady.value && route.query.f && viewer.value === null) router.replace({ query: { ...route.query, f: undefined } })
})
// Телефон: панель вкладки починається одразу під смугою вкладок — прокрутку стрічки скидаємо.
watch(tab, (t) => {
    if (t !== 'chat' && window.matchMedia('(max-width: 760px)').matches) window.scrollTo({ top: 0 })
})
onMounted(load)
</script>

<template>
    <div class="t-page p-supply-view" :class="{ 'p-embedded': embedded }">
        <div v-if="notFound" class="t-panel t-empty">
            <p>Заявку не знайдено.</p>
            <button v-if="embedded" type="button" class="t-btn" @click="emit('close')">← Назад</button>
            <router-link v-else :to="{ name: 'supply-board' }" class="t-btn">← До заявок</router-link>
        </div>
        <div v-else-if="!request || !feedReady" class="t-empty">{{ error || 'Завантаження…' }}</div>

        <div v-else class="t-task" :class="{ 'side-tab': tab !== 'chat' }">
            <section v-sticky-head class="t-task-main">
                <div class="p-thead">
                    <div class="p-thead-top">
                        <button v-if="embedded" type="button" class="p-icon-btn p-back" title="Назад (Esc)" aria-label="Назад" @click="emit('close')"><Icon name="back" /></button>
                        <router-link v-else :to="{ name: 'supply-board' }" class="p-icon-btn p-back" title="До заявок" aria-label="До заявок"><Icon name="back" /></router-link>
                        <span class="num">Заявка № {{ request.number }}</span>
                        <span v-if="request.department" class="client">· {{ request.department }}</span>
                        <span class="sp" />
                        <router-link
                            v-if="embedded"
                            :to="{ name: 'supply-full', params: { id: request.id } }"
                            class="p-icon-btn"
                            title="Відкрити окремо"
                            aria-label="Відкрити окремо"
                        >
                            <Icon name="monitor" :size="18" />
                        </router-link>
                        <button
                            type="button"
                            class="p-icon-btn p-side-toggle"
                            :class="{ on: sideOpen }"
                            title="Деталі, закупівля, документи"
                            aria-label="Деталі"
                            @click="sideOpen = !sideOpen"
                        >
                            <Icon name="info" :size="18" />
                        </button>
                        <button v-if="embedded" type="button" class="p-icon-btn p-close" title="Закрити (Esc)" aria-label="Закрити" @click="emit('close')">
                            <Icon name="x" />
                        </button>
                    </div>
                    <h1 class="p-ttitle">
                        <span v-if="request.accent !== 'none'" class="p-accent" :title="request.accentLabel">{{ request.accentEmoji }}</span>
                        <span :class="{ closed: request.status === 'rejected' }">{{ request.item }} — {{ quantity(request) }}</span>
                    </h1>
                    <div class="p-thead-meta">
                        <div class="p-status">
                            <button
                                type="button"
                                class="p-status-btn"
                                :class="tone(request.status)"
                                :disabled="!moves.length || busy"
                                :aria-expanded="movesOpen"
                                :title="moves.length ? 'Змінити статус' : 'Статус'"
                                @click="movesOpen = !movesOpen"
                            >
                                {{ request.statusLabel }}
                                <Icon v-if="moves.length" name="down" :size="14" :stroke="2.2" />
                            </button>
                        </div>
                        <span v-if="request.urgent" class="p-urgent">терміново</span>
                        <span v-if="request.needBy" class="p-due" :class="{ over: request.overdue }">
                            <Icon name="clock" :size="14" :stroke="2" />
                            {{ request.overdue ? 'прострочено · ' : 'до ' }}{{ formatDate(request.needBy) }}
                        </span>
                        <span class="p-muted"><PersonName :person="request.author" /></span>
                    </div>

                    <!-- Переходи менеджера — рядком у шапці (не спливаюче меню). -->
                    <div v-if="movesOpen && moves.length" class="p-moves" role="group" aria-label="Змінити статус">
                        <button v-for="t in moves" :key="t.value" type="button" class="p-chip" :disabled="busy" @click="move(t.value)">
                            <span class="dot" :class="tone(t.value)" />
                            {{ t.label }}
                        </button>
                        <button type="button" class="p-link" @click="movesOpen = false">Згорнути</button>
                    </div>

                    <div v-if="decisions.length" class="t-decision">
                        <span>Рішення директора</span>
                        <input v-model="note" class="t-input p-note" placeholder="Коментар до рішення (необовʼязково)" />
                        <div class="t-actions">
                            <button
                                v-for="d in decisions"
                                :key="d.value"
                                type="button"
                                class="t-btn small"
                                :class="d.value === 'approved' ? 'primary' : d.value === 'rejected' ? 'danger' : ''"
                                :disabled="busy"
                                @click="move(d.value)"
                            >
                                {{ d.label }}
                            </button>
                        </div>
                    </div>
                    <div v-else-if="isDecision" class="t-decision">⏳ Чекає рішення директора</div>

                    <!-- Відхилення без причини не пропускає бек — причина піде заявнику. -->
                    <div v-if="rejecting" class="p-reject">
                        <textarea v-model="reason" class="t-textarea" rows="2" placeholder="Причина відхилення — вона піде заявнику" />
                        <div class="t-actions">
                            <button type="button" class="t-btn small danger" :disabled="busy || !reason.trim()" @click="reject">Відхилити</button>
                            <button type="button" class="t-btn small ghost" :disabled="busy" @click="rejecting = false">Скасувати</button>
                        </div>
                    </div>
                </div>

                <div v-if="error" class="t-error" style="margin: 6px 12px 0">{{ error }}</div>
                <div class="t-mobile-tabs">
                    <button type="button" :class="{ on: tab === 'chat' }" @click="tab = 'chat'">💬 Стрічка</button>
                    <button type="button" :class="{ on: tab === 'details' }" @click="tab = 'details'">ℹ️ Деталі</button>
                    <button type="button" :class="{ on: tab === 'files' }" @click="tab = 'files'">📎 Документи · {{ files.length }}</button>
                </div>

                <TaskChat :key="subject.id" ref="chatRef" :task="subject" :meta="null" :api="feedApi" :hidden="tab !== 'chat'" @open="openFile" @reload="onChatReload" />
            </section>

            <aside class="t-side" :class="{ 'hide-mobile': tab === 'chat', open: sideOpen }">
                <div v-if="tab !== 'files'" class="t-panel">
                    <h2>Деталі</h2>
                    <dl class="t-dl">
                        <dt>Кількість</dt>
                        <dd>{{ quantity(request) }}</dd>
                        <dt>Потрібно до</dt>
                        <dd>{{ request.needBy ? formatDate(request.needBy) : '—' }}</dd>
                        <dt>Обʼєкт</dt>
                        <dd>{{ request.site ?? '—' }}</dd>
                        <dt>Заявник</dt>
                        <dd>
                            {{ request.author.name }}
                            <template v-if="request.author.phone">
                                <br />
                                <a :href="'tel:+' + request.author.phone.replace(/^\+/, '')">+{{ request.author.phone.replace(/^\+/, '') }}</a>
                            </template>
                        </dd>
                        <dt>Підрозділ</dt>
                        <dd>{{ request.department ?? '—' }}</dd>
                        <dt>Подано</dt>
                        <dd>{{ formatDateTime(request.createdAt) }}</dd>
                    </dl>
                    <template v-if="request.note">
                        <h2 style="margin-top: 1rem">Примітка</h2>
                        <div class="t-desc">{{ request.note }}</div>
                    </template>
                    <template v-if="canManage">
                        <h2 style="margin-top: 1rem">Мітка для керівника</h2>
                        <div class="p-chips wrap">
                            <button
                                v-for="a in accents"
                                :key="a.value"
                                type="button"
                                class="p-chip"
                                :class="{ on: request.accent === a.value }"
                                :disabled="busy"
                                @click="setAccent(request.accent === a.value ? 'none' : a.value)"
                            >
                                {{ a.emoji }} {{ a.label }}
                            </button>
                        </div>
                    </template>
                </div>

                <div v-if="tab !== 'files'" class="t-panel">
                    <h2>
                        Закупівля
                        <span v-if="request.purchases?.length" class="count">{{ money(request.purchaseTotal ?? 0) }}</span>
                    </h2>
                    <p v-if="!request.purchases?.length" class="t-hint" style="padding: 0">Ще не вказано, у кого купили. Без цього заявку не перевести на затвердження.</p>
                    <div v-for="p in request.purchases" :key="p.id" class="p-purchase">
                        <div>
                            <b>{{ p.supplier.name }}</b>
                            · {{ p.totalLabel }}
                            <span v-if="!p.vatIncluded" class="p-muted">без ПДВ</span>
                        </div>
                        <div class="p-muted p-small">
                            {{ p.paymentLabel }}
                            <template v-if="p.invoiceNumber">· накладна {{ p.invoiceNumber }}</template>
                            <template v-if="p.quantity">· {{ p.quantity }}</template>
                        </div>
                        <button v-if="canManage" type="button" class="p-link danger" :disabled="busy" @click="removePurchase(p.id)">Прибрати</button>
                    </div>
                    <template v-if="canManage">
                        <div v-if="addingPurchase" class="p-purchase-form">
                            <SupplierPicker v-model="purchase.supplierId" :suppliers="suppliers" @created="suppliers.push($event)" @error="error = $event" />
                            <div class="t-two">
                                <input v-model="purchase.totalAmount" class="t-input" type="text" inputmode="decimal" placeholder="Сума, ₴" aria-label="Сума" />
                                <input v-model="purchase.invoiceNumber" class="t-input" type="text" placeholder="Накладна" aria-label="Накладна" />
                            </div>
                            <div class="t-two">
                                <input v-model="purchase.quantity" class="t-input" type="text" inputmode="decimal" placeholder="Кількість" aria-label="Кількість" />
                                <input v-model="purchase.pricePerUnit" class="t-input" type="text" inputmode="decimal" placeholder="Ціна за одиницю" aria-label="Ціна за одиницю" />
                            </div>
                            <div class="t-two">
                                <select v-model="purchase.payment" class="t-select" aria-label="Оплата">
                                    <option value="bank">Безготівка</option>
                                    <option value="cash">Готівка</option>
                                </select>
                                <label class="p-check">
                                    <input v-model="purchase.vatIncluded" type="checkbox" />
                                    з ПДВ
                                </label>
                            </div>
                            <div class="t-actions">
                                <button type="button" class="t-btn small primary" :disabled="busy || !purchase.supplierId" @click="savePurchase">Зберегти</button>
                                <button type="button" class="t-btn small ghost" @click="addingPurchase = false">Скасувати</button>
                            </div>
                        </div>
                        <button v-else type="button" class="p-link" @click="openPurchase">{{ request.purchases?.length ? '+ Ще постачальник' : '+ Вказати постачальника' }}</button>
                    </template>
                </div>

                <div v-if="tab !== 'details'" class="t-panel">
                    <h2>
                        Документи
                        <span class="count">{{ files.length }}</span>
                    </h2>
                    <div v-if="files.length" class="p-docs">
                        <div v-for="f in files" :key="f.id" class="p-doc">
                            <button type="button" class="p-doc-open" :title="f.name" @click="openFile(f.id)">
                                <img v-if="f.urls.thumb" :src="f.urls.thumb" :alt="f.name" loading="lazy" />
                                <span v-else class="ico" :class="extClass(f)">{{ f.ext }}</span>
                            </button>
                            <div class="p-doc-info">
                                <button type="button" class="p-doc-name" @click="openFile(f.id)">{{ f.name }}</button>
                                <div class="p-muted p-small">
                                    {{ f.typeLabel }} · {{ f.sizeLabel }}
                                    <template v-if="f.uploadedBy">
                                        ·
                                        <PersonName :name="f.uploadedBy" />
                                    </template>
                                </div>
                                <div class="p-small">
                                    <a v-if="f.driveUrl" :href="f.driveUrl" target="_blank" rel="noopener">Google Drive</a>
                                    <span v-else class="p-muted">Drive: у черзі</span>
                                    ·
                                    <a :href="f.urls.download">Скачати</a>
                                    <template v-if="subject.can.removeFiles">
                                        ·
                                        <button type="button" class="p-link danger" @click="removeFile(f)">Прибрати</button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="t-empty">Накладних, рахунків і договорів ще немає.</div>
                    <div v-if="subject.can.attach" class="p-upload">
                        <select v-model="attachmentType" class="t-select" aria-label="Тип документа">
                            <option v-for="t in ATTACHMENT_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                        <label class="p-btn">
                            <Icon name="clip" :size="16" />
                            {{ uploading ? 'Завантаження…' : 'Додати документ' }}
                            <input type="file" multiple hidden accept=".pdf,.jpg,.jpeg,.png,.heic,.webp,.doc,.docx,.xls,.xlsx" :disabled="uploading" @change="upload" />
                        </label>
                    </div>
                </div>
            </aside>
        </div>

        <FileViewer v-if="viewer !== null" :files="files" :start-id="viewer" @close="closeFile" @change="showFile" />
    </div>
</template>
