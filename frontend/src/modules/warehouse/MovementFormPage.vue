<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, type ApiSupplier } from '../../app/api'
import { useSession } from '../../app/store'
import Icon from '../tasks/shell/Icon.vue'
import SupplierPicker from '../../shared/SupplierPicker.vue'
import { qty, whApi, type MovementPayload, type WhItemRow, type WhMeta, type WhSite } from './api'
import { accessError } from './access'
import { goBack } from '../../plan/layers'
import { useHistoryLayer } from '../../plan/useHistoryLayer'
import './warehouse.css'

/**
 * Новий рух — сторінкою /sklad/movements/new (не вікном): «Назад» закриває форму.
 * ?type=shipment&item=12&from=3 — з картки позиції («Відвантажити», «Повернути»…).
 * Записує той самий RecordMovement, що й Twig-форма: усі перевірки (звідки/куди за
 * типом, залишок, поштучне «там, де воно є») — на сервері, помилка — банером тут же.
 * Накладна — у тому самому надсиланні: рух без неї, якщо файл не ліг, не запишеться.
 */
const session = useSession()
const route = useRoute()
const router = useRouter()

const meta = ref<WhMeta | null>(null)
const items = ref<WhItemRow[]>([])
const suppliers = ref<ApiSupplier[]>([])
const error = ref('')
const saving = ref(false)
const files = ref<File[]>([])
const newSite = ref(false)
const newSiteName = ref('')
const newClient = ref('')
const clientId = ref('')
const supplierId = ref(0)
// Телефон: «Назад» спершу згортає поля нового об'єкта, а не закриває форму.
useHistoryLayer(newSite)

const str = (v: unknown) => (typeof v === 'string' ? v : '')
const draft = ref<MovementPayload>({
    type: str(route.query.type) || 'shipment',
    occurredAt: '',
    fromId: str(route.query.from),
    toId: str(route.query.to),
    toClientId: '',
    supplierId: '',
    documentNumber: '',
    documentType: 'waybill',
    note: '',
    lines: route.query.item ? [{ itemId: Number(route.query.item), quantity: '1', rate: '' }] : [{ itemId: 0, quantity: '1', rate: '' }],
})

const type = computed(() => meta.value?.movementTypes.find((t) => t.value === draft.value.type) ?? null)
const sitesOf = (kind: 'warehouse' | 'site' | null) => (meta.value?.sites ?? []).filter((s) => s.active && (kind === null || s.kind === kind))
const fromSites = computed(() => sitesOf(type.value?.fromKind ?? null).filter((s) => String(s.id) !== draft.value.toId))
const toSites = computed(() => sitesOf(type.value?.toKind ?? null).filter((s) => String(s.id) !== draft.value.fromId))
const fromSite = computed<WhSite | null>(() => meta.value?.sites.find((s) => String(s.id) === draft.value.fromId) ?? null)
const toSite = computed<WhSite | null>(() => meta.value?.sites.find((s) => String(s.id) === draft.value.toId) ?? null)
/** Ставка оренди — лише коли майно їде до клієнта (як у RecordMovement). */
const withRate = computed(() => (toSite.value?.client ?? null) !== null || (newSite.value && (type.value?.toKind ?? 'site') === 'site'))
const canNewSite = computed(() => (type.value?.needsTo ?? false) && (type.value?.toKind ?? 'site') === 'site')
const movable = computed(() => items.value.filter((i) => i.state !== 'written_off'))

const itemById = (id: number) => items.value.find((i) => i.id === id) ?? null
/** Скільки позиції є там, звідки везуть, — підказка біля кількості (перевіряє все одно сервер). */
function available(id: number): string {
    const i = itemById(id)
    const from = fromSite.value
    if (!i || !from) return ''
    const here = i.places.find((p) => p.site === from.label)
    if (i.tracking === 'unit') return here ? 'тут' : 'не тут'
    return 'є ' + qty(here?.quantity ?? 0, i.unit)
}

function addLine() {
    draft.value.lines.push({ itemId: 0, quantity: '1', rate: '' })
}
function removeLine(i: number) {
    draft.value.lines.splice(i, 1)
    if (!draft.value.lines.length) addLine()
}

function pickFiles(e: Event) {
    const input = e.target as HTMLInputElement
    files.value = [...files.value, ...Array.from(input.files ?? [])]
    input.value = ''
}

function cancel() {
    goBack(router, route.query.item ? { name: 'warehouse-items', params: { id: str(route.query.item) } } : { name: 'warehouse-movements' })
}

watch(() => draft.value.type, () => {
    // Інший тип — інші правила місць: прибираємо те, що вже не підходить.
    if (draft.value.fromId && !fromSites.value.some((s) => String(s.id) === draft.value.fromId)) draft.value.fromId = ''
    if (draft.value.toId && !toSites.value.some((s) => String(s.id) === draft.value.toId)) draft.value.toId = ''
    if (!canNewSite.value) newSite.value = false
})

async function submit() {
    if (saving.value) return
    error.value = ''
    const t = type.value
    if (!t) return
    const lines = draft.value.lines.filter((l) => l.itemId > 0)
    if (!lines.length) {
        error.value = 'Додайте хоча б одну позицію.'
        return
    }
    saving.value = true
    try {
        const payload: MovementPayload = {
            ...draft.value,
            fromId: t.needsFrom ? draft.value.fromId : '',
            toId: !t.needsTo ? '' : newSite.value ? 'new:' + newSiteName.value.trim() : draft.value.toId,
            toClientId: newSite.value ? (clientId.value === 'new' ? 'new:' + newClient.value.trim() : clientId.value) : '',
            supplierId: !t.needsFrom && supplierId.value ? String(supplierId.value) : '',
            lines: lines.map((l) => ({ ...l, rate: withRate.value ? l.rate : '' })),
        }
        const created = await whApi.createMovement(payload, files.value)
        if (newSite.value) whApi.meta(true).catch(() => undefined)
        // Форму в історії заміняє сам рух: «Назад» із руху веде до списку, а не знову у форму.
        router.replace({ name: 'warehouse-movements', params: { id: String(created.id) } })
    } catch (e) {
        error.value = accessError(e) || session.handle(e)
    } finally {
        saving.value = false
    }
}

onMounted(async () => {
    try {
        const [m, list] = await Promise.all([whApi.meta(), whApi.items({})])
        meta.value = m
        items.value = list.items
        draft.value.occurredAt = m.today
        if (!m.movementTypes.some((t) => t.value === draft.value.type)) draft.value.type = 'shipment'
        // Поштучна позиція з картки — «звідки» там, де вона є.
        const first = itemById(draft.value.lines[0]?.itemId ?? 0)
        if (!draft.value.fromId && first?.currentSite && fromSites.value.some((s) => s.id === first.currentSite?.id)) draft.value.fromId = String(first.currentSite.id)
    } catch (e) {
        error.value = accessError(e) || session.handle(e)
    }
    api.suppliers({ active: true }).then((r) => (suppliers.value = r.items)).catch(() => undefined)
})
</script>

<template>
    <div class="t-page w-form-page">
        <form class="t-panel w-form" @submit.prevent="submit">
            <div class="w-form-head">
                <button type="button" class="p-icon-btn" title="Назад" aria-label="Назад" @click="cancel"><Icon name="back" /></button>
                <h1>Новий рух</h1>
            </div>

            <div v-if="!meta" class="t-empty">{{ error || 'Завантаження…' }}</div>
            <template v-else>
                <div class="p-chips wrap" role="radiogroup" aria-label="Тип руху">
                    <button v-for="t in meta.movementTypes" :key="t.value" type="button" role="radio" class="p-chip" :class="{ on: draft.type === t.value }" :aria-checked="draft.type === t.value" @click="draft.type = t.value">{{ t.emoji }} {{ t.label }}</button>
                </div>

                <div class="t-two">
                    <label class="w-field"><span>Дата</span><input v-model="draft.occurredAt" class="t-input" type="date" required /></label>
                    <label class="w-field"><span>№ документа</span><input v-model="draft.documentNumber" class="t-input" type="text" placeholder="напр. ВН-118" /></label>
                </div>

                <div class="t-two">
                    <label v-if="type?.needsFrom" class="w-field">
                        <span>Звідки</span>
                        <select v-model="draft.fromId" class="t-select" required>
                            <option value="" disabled>Оберіть місце</option>
                            <option v-for="s in fromSites" :key="s.id" :value="String(s.id)">{{ s.label }}</option>
                        </select>
                    </label>
                    <div v-else class="w-field">
                        <span>Постачальник</span>
                        <SupplierPicker v-model="supplierId" :suppliers="suppliers" @created="suppliers.push($event)" @error="error = $event" />
                    </div>
                    <div v-if="type?.needsTo" class="w-field">
                        <span>Куди</span>
                        <select v-if="!newSite" v-model="draft.toId" class="t-select" required>
                            <option value="" disabled>Оберіть місце</option>
                            <option v-for="s in toSites" :key="s.id" :value="String(s.id)">{{ s.label }}</option>
                        </select>
                        <div v-else class="w-newsite">
                            <input v-model="newSiteName" class="t-input" type="text" placeholder="Назва нового обʼєкта" required />
                            <select v-model="clientId" class="t-select" required aria-label="Чий обʼєкт">
                                <option value="" disabled>Клієнт</option>
                                <option v-for="c in meta.clients.filter((c) => c.active)" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                                <option value="new">+ Новий клієнт…</option>
                            </select>
                            <input v-if="clientId === 'new'" v-model="newClient" class="t-input" type="text" placeholder="Назва клієнта" required />
                        </div>
                        <button v-if="canNewSite" type="button" class="p-link" @click="newSite = !newSite">{{ newSite ? '← Вибрати зі списку' : '+ Новий обʼєкт' }}</button>
                    </div>
                    <p v-else class="t-hint">Списане нікуди не їде.</p>
                </div>

                <div class="w-lines-edit">
                    <div class="w-lines-head"><b>Позиції</b><span class="p-muted p-small">кількість — цілим числом</span></div>
                    <div v-for="(l, i) in draft.lines" :key="i" class="w-line">
                        <select v-model.number="l.itemId" class="t-select" :aria-label="'Позиція ' + (i + 1)">
                            <option :value="0" disabled>Оберіть позицію</option>
                            <option v-for="it in movable" :key="it.id" :value="it.id">{{ it.inventoryNumber }} · {{ it.name }}</option>
                        </select>
                        <input v-model="l.quantity" class="t-input w-qty" type="text" inputmode="numeric" :disabled="itemById(l.itemId)?.tracking === 'unit'" :aria-label="'Кількість ' + (i + 1)" />
                        <span class="w-unit">{{ itemById(l.itemId)?.unit ?? '' }}</span>
                        <input v-if="withRate" v-model="l.rate" class="t-input w-rate" type="text" inputmode="decimal" placeholder="₴/доба" :aria-label="'Ставка оренди ' + (i + 1)" />
                        <button type="button" class="p-icon-btn" title="Прибрати рядок" aria-label="Прибрати рядок" @click="removeLine(i)"><Icon name="x" :size="16" /></button>
                        <small v-if="l.itemId && type?.needsFrom" class="w-avail p-muted">{{ available(l.itemId) }}</small>
                    </div>
                    <button type="button" class="p-link" @click="addLine">+ Ще позиція</button>
                </div>

                <label class="w-field"><span>Примітка</span><textarea v-model="draft.note" class="t-textarea" rows="2" placeholder="Хто віз, номер машини, що не так" /></label>

                <div class="w-field">
                    <span>Накладна, акт, фото</span>
                    <div class="p-upload">
                        <select v-model="draft.documentType" class="t-select" aria-label="Тип документа">
                            <option v-for="t in meta.documentTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                        <label class="p-btn"><Icon name="clip" :size="16" />Додати файл<input type="file" multiple hidden accept=".pdf,.jpg,.jpeg,.png,.heic,.webp,.doc,.docx,.xls,.xlsx" @change="pickFiles" /></label>
                    </div>
                    <div v-for="(f, i) in files" :key="i" class="w-file">📎 {{ f.name }} <button type="button" class="p-link danger" @click="files.splice(i, 1)">прибрати</button></div>
                </div>

                <div v-if="error" class="t-error" role="alert">{{ error }}</div>
                <div class="t-actions">
                    <button type="submit" class="t-btn primary" :disabled="saving">{{ saving ? 'Записую…' : 'Записати рух' }}</button>
                    <button type="button" class="t-btn ghost" :disabled="saving" @click="cancel">Скасувати</button>
                </div>
            </template>
        </form>
    </div>
</template>
