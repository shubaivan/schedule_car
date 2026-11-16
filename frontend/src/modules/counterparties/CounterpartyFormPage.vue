<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useSession } from '../../app/store'
import Icon from '../tasks/shell/Icon.vue'
import { goBack } from '../../plan/layers'
import { CpError, cpApi, type CpMeta, type CpPayload } from './api'
import { accessError } from './access'
import '../warehouse/warehouse.css'
import './counterparties.css'

/**
 * Новий контрагент (/counterparties/new) і правка (/counterparties/:id/edit) — сторінкою.
 * Перевіряє сервер (CounterpartyService): контрольна цифра ЄДРПОУ/РНОКПП, IBAN,
 * дублі за кодом і назвою (тоді — посилання на наявну картку). Умови оплати й ліміт
 * без права «гроші» лише показуються.
 */
const props = defineProps<{ id?: string }>()
const session = useSession()
const router = useRouter()

type Contact = CpPayload['contacts'][number]
const blankContact = (): Contact => ({ id: null, name: '', role: '', phone: '', email: '', primary: false })

const meta = ref<CpMeta | null>(null)
const error = ref('')
const errorField = ref<string | null>(null)
const existingId = ref<number | null>(null)
const saving = ref(false)
const ready = ref(false)
const draft = ref<CpPayload>({
    kind: 'client',
    legalForm: 'company',
    name: '',
    shortName: '',
    code: '',
    vatStatus: '',
    vatNumber: '',
    legalAddress: '',
    actualAddress: '',
    phone: '',
    email: '',
    iban: '',
    bankName: '',
    paymentTerms: '',
    deferralDays: '',
    creditLimit: '',
    managerId: null,
    note: '',
    externalCode: '',
    contacts: [blankContact()],
})

const editing = computed(() => !!props.id)
const finance = computed(() => !!meta.value?.can.finance)
const codeLabel = computed(() => meta.value?.legalForms.find((f) => f.value === draft.value.legalForm)?.codeLabel ?? 'ЄДРПОУ')
const codeLength = computed(() => (draft.value.legalForm === 'company' ? 8 : 10))
const back = computed(() => (props.id ? { name: 'counterparties', params: { id: props.id } } : { name: 'counterparties' }))
const bad = (field: string) => errorField.value === field
/** Менеджер картки, якого вже немає в списку (в архіві), — щоб поле не виглядало порожнім. */
const currentManager = ref<{ id: number; name: string } | null>(null)
const managers = computed(() => {
    const list = meta.value?.managers ?? []
    const m = currentManager.value
    return m && !list.some((x) => x.id === m.id) ? [...list, { ...m, name: m.name + ' (в архіві)' }] : list
})

function setPrimary(i: number) {
    draft.value.contacts.forEach((c, j) => (c.primary = j === i ? !c.primary : false))
}

function removeContact(i: number) {
    draft.value.contacts.splice(i, 1)
    if (!draft.value.contacts.length) draft.value.contacts.push(blankContact())
}

async function submit() {
    if (saving.value) return
    error.value = ''
    errorField.value = null
    existingId.value = null
    saving.value = true
    try {
        const payload: Partial<CpPayload> = { ...draft.value, contacts: draft.value.contacts.filter((c) => c.name || c.phone || c.email || c.role) }
        // Без права «гроші» ці поля не надсилаємо зовсім — сервер їх і так не змінить.
        if (!finance.value) {
            delete payload.paymentTerms
            delete payload.deferralDays
            delete payload.creditLimit
        }
        const saved = props.id ? await cpApi.update(Number(props.id), payload) : await cpApi.create(payload as CpPayload)
        cpApi.meta(true).catch(() => undefined)
        if (props.id) goBack(router, back.value)
        else router.replace({ name: 'counterparties', params: { id: String(saved.id) } })
    } catch (e) {
        if (e instanceof CpError) {
            error.value = e.message
            errorField.value = e.field
            existingId.value = e.existingId
        } else error.value = accessError(e) || session.handle(e)
    } finally {
        saving.value = false
    }
}

onMounted(async () => {
    try {
        meta.value = await cpApi.meta()
        if (props.id) {
            const c = await cpApi.get(Number(props.id))
            currentManager.value = c.manager
            draft.value = {
                kind: c.kind,
                legalForm: c.legalForm,
                name: c.name,
                shortName: c.shortName ?? '',
                code: c.code ?? '',
                vatStatus: c.vatStatus ?? '',
                vatNumber: c.vatNumber ?? '',
                legalAddress: c.legalAddress ?? '',
                actualAddress: c.actualAddress ?? '',
                phone: c.phone ?? '',
                email: c.email ?? '',
                iban: c.iban ?? '',
                bankName: c.bankName ?? '',
                paymentTerms: c.paymentTerms ?? '',
                deferralDays: c.deferralDays === null ? '' : String(c.deferralDays),
                creditLimit: c.creditLimit ?? '',
                managerId: c.manager?.id ?? null,
                note: c.note ?? '',
                externalCode: c.externalCode ?? '',
                version: c.version,
                contacts: c.contacts.length
                    ? c.contacts.map((x) => ({ id: x.id, name: x.name, role: x.role ?? '', phone: x.phone ?? '', email: x.email ?? '', primary: x.primary }))
                    : [blankContact()],
            }
        } else if (session.user?.workRole === 'manager') {
            draft.value.managerId = meta.value.me
        }
        ready.value = true
    } catch (e) {
        error.value = accessError(e) || session.handle(e)
    }
})

function onKey(e: KeyboardEvent) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault()
        void submit()
    } else if (e.key === 'Escape') {
        e.preventDefault()
        goBack(router, back.value)
    }
}
</script>

<template>
    <div class="p-formpage w-form-page">
        <form class="t-formcard p-form p-form-page w-form c-form" aria-labelledby="c-form-title" novalidate @submit.prevent="submit" @keydown="onKey">
            <header>
                <button type="button" class="p-icon-btn p-form-back" title="Назад (Esc)" aria-label="Назад" @click="goBack(router, back)"><Icon name="back" /></button>
                <h2 id="c-form-title">{{ editing ? 'Змінити контрагента' : 'Новий контрагент' }}</h2>
                <button type="button" class="p-icon-btn p-form-x" aria-label="Закрити" title="Закрити (Esc)" @click="goBack(router, back)"><Icon name="x" /></button>
            </header>
            <div v-if="!ready" class="body t-empty">{{ error || 'Завантаження…' }}</div>
            <div v-else-if="meta" class="body">
                <div class="p-chips wrap" role="radiogroup" aria-label="Хто це">
                    <button
                        v-for="k in meta.kinds"
                        :key="k.value"
                        type="button"
                        role="radio"
                        class="p-chip"
                        :class="{ on: draft.kind === k.value }"
                        :aria-checked="draft.kind === k.value"
                        @click="draft.kind = k.value"
                    >
                        {{ k.label }}
                    </button>
                </div>
                <div class="t-two">
                    <label class="w-field">
                        <span>Форма</span>
                        <select v-model="draft.legalForm" class="t-select">
                            <option v-for="f in meta.legalForms" :key="f.value" :value="f.value">{{ f.label }}</option>
                        </select>
                    </label>
                    <label class="w-field" :class="{ bad: bad('code') }">
                        <span>{{ codeLabel }}</span>
                        <input
                            v-model="draft.code"
                            class="t-input"
                            type="text"
                            inputmode="numeric"
                            :maxlength="codeLength + 4"
                            :placeholder="codeLength + ' цифр'"
                            data-testid="cp-code"
                        />
                    </label>
                </div>
                <label class="w-field" :class="{ bad: bad('name') }">
                    <span>Повна назва *</span>
                    <input v-model="draft.name" class="t-input" type="text" required placeholder="напр. ТОВ «Житлобуд-Черкаси»" data-testid="cp-name" />
                </label>
                <label class="w-field">
                    <span>Коротко (як кажуть у цеху)</span>
                    <input v-model="draft.shortName" class="t-input" type="text" placeholder="Житлобуд" />
                </label>
                <div class="t-two">
                    <label class="w-field">
                        <span>ПДВ</span>
                        <select v-model="draft.vatStatus" class="t-select">
                            <option value="">Не вказано</option>
                            <option v-for="v in meta.vatStatuses" :key="v.value" :value="v.value">{{ v.label }}</option>
                        </select>
                    </label>
                    <label v-if="draft.vatStatus === 'payer'" class="w-field" :class="{ bad: bad('vatNumber') }">
                        <span>ІПН платника ПДВ</span>
                        <input v-model="draft.vatNumber" class="t-input" type="text" inputmode="numeric" maxlength="14" placeholder="12 цифр" />
                    </label>
                </div>

                <fieldset class="c-set">
                    <legend>Контактні особи</legend>
                    <div v-for="(c, i) in draft.contacts" :key="i" class="c-contact-edit">
                        <div class="t-two">
                            <input v-model="c.name" class="t-input" type="text" placeholder="Імʼя" :aria-label="'Контакт ' + (i + 1) + ': імʼя'" />
                            <input v-model="c.role" class="t-input" type="text" placeholder="Посада: директор, виконроб…" :aria-label="'Контакт ' + (i + 1) + ': посада'" />
                        </div>
                        <div class="t-two">
                            <input v-model="c.phone" class="t-input" type="tel" inputmode="tel" placeholder="Телефон" :aria-label="'Контакт ' + (i + 1) + ': телефон'" />
                            <input v-model="c.email" class="t-input" type="email" placeholder="Email" :aria-label="'Контакт ' + (i + 1) + ': email'" />
                        </div>
                        <div class="c-contact-actions p-small">
                            <button type="button" class="p-link" :class="{ on: c.primary }" @click="setPrimary(i)">{{ c.primary ? '★ основний' : '☆ зробити основним' }}</button>
                            <button type="button" class="p-link danger" @click="removeContact(i)">Прибрати</button>
                        </div>
                    </div>
                    <button type="button" class="p-link" :class="{ bad: bad('contacts') }" @click="draft.contacts.push(blankContact())">+ Контактна особа</button>
                </fieldset>

                <div class="t-two">
                    <label class="w-field" :class="{ bad: bad('phone') }">
                        <span>Загальний телефон</span>
                        <input v-model="draft.phone" class="t-input" type="tel" inputmode="tel" placeholder="приймальня, диспетчер" />
                    </label>
                    <label class="w-field" :class="{ bad: bad('email') }">
                        <span>Email</span>
                        <input v-model="draft.email" class="t-input" type="email" />
                    </label>
                </div>
                <label class="w-field">
                    <span>Юридична адреса</span>
                    <input v-model="draft.legalAddress" class="t-input" type="text" />
                </label>
                <label class="w-field">
                    <span>Фактична адреса / доставка</span>
                    <input v-model="draft.actualAddress" class="t-input" type="text" placeholder="якщо інша — куди возити" />
                </label>
                <div class="t-two">
                    <label class="w-field" :class="{ bad: bad('iban') }">
                        <span>IBAN</span>
                        <input v-model="draft.iban" class="t-input" type="text" placeholder="UA…" autocapitalize="characters" />
                    </label>
                    <label class="w-field">
                        <span>Банк</span>
                        <input v-model="draft.bankName" class="t-input" type="text" />
                    </label>
                </div>

                <fieldset class="c-set" :disabled="!finance">
                    <legend>Гроші{{ finance ? '' : ' — встановлює бухгалтер або директор' }}</legend>
                    <div class="t-two">
                        <label class="w-field" :class="{ bad: bad('paymentTerms') }">
                            <span>Умови оплати</span>
                            <select v-model="draft.paymentTerms" class="t-select">
                                <option value="">Не вказано</option>
                                <option v-for="t in meta.paymentTerms" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </label>
                        <label v-if="draft.paymentTerms === 'deferral'" class="w-field" :class="{ bad: bad('deferralDays') }">
                            <span>Відстрочка, днів</span>
                            <input v-model="draft.deferralDays" class="t-input" type="text" inputmode="numeric" placeholder="14" />
                        </label>
                    </div>
                    <label class="w-field" :class="{ bad: bad('creditLimit') }">
                        <span>Кредитний ліміт, ₴</span>
                        <input v-model="draft.creditLimit" class="t-input" type="text" inputmode="decimal" placeholder="порожньо — без кредиту" />
                    </label>
                </fieldset>

                <div class="t-two">
                    <label class="w-field" :class="{ bad: bad('managerId') }">
                        <span>Менеджер</span>
                        <select v-model="draft.managerId" class="t-select">
                            <option :value="null">—</option>
                            <option v-for="m in managers" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                    </label>
                    <label class="w-field" :class="{ bad: bad('externalCode') }">
                        <span>Код у 1С</span>
                        <input v-model="draft.externalCode" class="t-input" type="text" placeholder="для імпорту й звірки" />
                    </label>
                </div>
                <label class="w-field">
                    <span>Примітки</span>
                    <textarea v-model="draft.note" class="t-textarea" rows="3" />
                </label>

                <div v-if="error" class="t-error" role="alert" data-testid="cp-error">
                    {{ error }}
                    <router-link v-if="existingId" :to="{ name: 'counterparties', params: { id: existingId } }" class="p-link">Відкрити наявну картку</router-link>
                </div>
            </div>
            <footer v-if="ready && meta">
                <span class="p-hint-keys">{{ editing ? 'Ctrl+Enter — зберегти' : 'Ctrl+Enter — створити' }}</span>
                <button type="button" class="p-btn ghost" :disabled="saving" @click="goBack(router, back)">Скасувати</button>
                <button type="submit" class="p-btn primary" :disabled="saving" data-testid="cp-save">{{ saving ? 'Зберігаю…' : editing ? 'Зберегти' : 'Створити' }}</button>
            </footer>
        </form>
    </div>
</template>
