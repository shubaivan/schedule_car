<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useSession } from '../../app/store'
import Icon from '../tasks/shell/Icon.vue'
import { formatDate } from '../../shared/format'
import { notify } from '../../plan/undo'
import { cpApi, hryvnia, type CpCard, type CpMeta } from './api'
import { accessError } from './access'
import '../supply/supply.css'
import '../warehouse/warehouse.css'
import './counterparties.css'

/**
 * Картка контрагента — як позиція складу: шапка з діями, ліворуч реквізити й
 * контактні особи (телефони клікабельні), праворуч гроші, угоди, звʼязки з
 * постачанням/складом та історія змін (із журналу подій — хто і що міняв).
 * «Змінити» — сторінка /counterparties/:id/edit; «В архів» — одразу, з тостом
 * (повернути можна тут же кнопкою «Повернути з архіву»).
 */
const props = defineProps<{ id: string; embedded?: boolean }>()
const emit = defineEmits<{ close: []; changed: [] }>()
const session = useSession()
const route = useRoute()

const card = ref<CpCard | null>(null)
const meta = ref<CpMeta | null>(null)
const error = ref('')
const notFound = ref(false)
const busy = ref(false)
const tab = ref<'card' | 'deals'>('card')

const canEdit = computed(() => !!meta.value?.can.edit && !card.value?.archived)
const canFinance = computed(() => !!meta.value?.can.finance)
const address = computed(() => {
    const c = card.value
    if (!c) return []
    const out: { label: string; value: string }[] = []
    if (c.legalAddress) out.push({ label: 'Юридична', value: c.legalAddress })
    if (c.actualAddress && c.actualAddress !== c.legalAddress) out.push({ label: 'Фактична / доставка', value: c.actualAddress })
    return out
})

let seq = 0
async function load() {
    const mine = ++seq
    try {
        const [c, m] = await Promise.all([cpApi.get(Number(props.id)), cpApi.meta()])
        if (mine !== seq) return
        card.value = c
        meta.value = m
        notFound.value = false
        error.value = ''
    } catch (e) {
        if (mine !== seq) return
        const msg = accessError(e) || session.handle(e)
        notFound.value = /40[34]|не знайдено/i.test(msg) || !!accessError(e)
        error.value = msg
    }
}

async function toggleArchive() {
    const c = card.value
    if (!c || busy.value) return
    busy.value = true
    try {
        card.value = c.archived ? await cpApi.restore(c.id) : await cpApi.archive(c.id)
        notify(card.value.archived ? `«${c.displayName}» — в архіві` : `«${c.displayName}» повернуто з архіву`)
        emit('changed')
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        busy.value = false
    }
}

async function copy(text: string, what: string) {
    try {
        await navigator.clipboard.writeText(text)
        notify(`${what} скопійовано`)
    } catch {
        /* буфер недоступний — нічого */
    }
}

watch(
    () => props.id,
    () => {
        card.value = null
        load()
    },
)
onMounted(load)
</script>

<template>
    <div class="t-page c-view" :class="{ 'p-embedded': embedded }">
        <div v-if="notFound" class="t-panel t-empty">
            <p>{{ error || 'Контрагента не знайдено.' }}</p>
            <button v-if="embedded" type="button" class="t-btn" @click="emit('close')">← Назад</button>
            <router-link v-else :to="{ name: 'counterparties' }" class="t-btn">← До контрагентів</router-link>
        </div>
        <div v-else-if="!card" class="t-empty">{{ error || 'Завантаження…' }}</div>

        <div v-else class="t-task" :class="{ 'side-tab': tab !== 'card' }">
            <section class="t-task-main">
                <div class="p-thead">
                    <div class="p-thead-top">
                        <button v-if="embedded" type="button" class="p-icon-btn p-back" title="Назад (Esc)" aria-label="Назад" @click="emit('close')"><Icon name="back" /></button>
                        <router-link v-else :to="{ name: 'counterparties', query: route.query }" class="p-icon-btn p-back" title="До контрагентів" aria-label="До контрагентів">
                            <Icon name="back" />
                        </router-link>
                        <span class="c-kind" :class="'c-kind-' + card.kind">{{ card.kindLabel }}</span>
                        <span class="client">· {{ card.legalFormLabel }}</span>
                        <span class="sp" />
                        <router-link
                            v-if="canEdit"
                            :to="{ name: 'counterparty-edit', params: { id: card.id } }"
                            class="p-icon-btn"
                            title="Змінити картку"
                            aria-label="Змінити картку"
                            data-testid="cp-edit"
                        >
                            <Icon name="edit" :size="18" />
                        </router-link>
                        <router-link
                            v-if="embedded"
                            :to="{ name: 'counterparty-full', params: { id: card.id } }"
                            class="p-icon-btn"
                            title="Відкрити окремо"
                            aria-label="Відкрити окремо"
                        >
                            <Icon name="monitor" :size="18" />
                        </router-link>
                        <button v-if="embedded" type="button" class="p-icon-btn p-close" title="Закрити (Esc)" aria-label="Закрити" @click="emit('close')">
                            <Icon name="x" />
                        </button>
                    </div>
                    <h1 class="p-ttitle">
                        <span :class="{ closed: card.archived }">{{ card.displayName }}</span>
                    </h1>
                    <div class="p-thead-meta">
                        <span v-if="card.shortName" class="p-muted">{{ card.name }}</span>
                        <button v-if="card.code" type="button" class="p-chip c-code" :title="'Скопіювати ' + card.codeLabel" @click="copy(card.code, card.codeLabel)">
                            {{ card.codeLabel }} {{ card.code }}
                        </button>
                        <span v-if="card.vatLabel" class="st w-pill">{{ card.vatLabel }}</span>
                        <span v-if="card.archived" class="st w-pill w-st-written_off">В архіві</span>
                    </div>
                </div>

                <div v-if="error" class="t-error" style="margin: 6px 12px 0">{{ error }}</div>
                <div class="t-mobile-tabs">
                    <button type="button" :class="{ on: tab === 'card' }" @click="tab = 'card'">Картка</button>
                    <button type="button" :class="{ on: tab === 'deals' }" @click="tab = 'deals'">Угоди й історія · {{ card.tasks.length }}</button>
                </div>

                <div class="c-main" :class="{ 'hide-mobile': tab !== 'card' }">
                    <div class="t-panel">
                        <h2>
                            Контактні особи
                            <span class="count">{{ card.contacts.length }}</span>
                        </h2>
                        <div v-if="card.contacts.length" class="c-contacts">
                            <div v-for="p in card.contacts" :key="p.id ?? p.name" class="c-contact" :class="{ primary: p.primary }">
                                <div class="c-contact-head">
                                    <b>{{ p.name }}</b>
                                    <span v-if="p.role" class="p-muted">· {{ p.role }}</span>
                                    <span v-if="p.primary" class="c-badge">основний</span>
                                </div>
                                <div class="p-small">
                                    <a v-if="p.tel" :href="'tel:' + p.tel">{{ p.phone }}</a>
                                    <template v-if="p.tel && p.email">·</template>
                                    <a v-if="p.email" :href="'mailto:' + p.email">{{ p.email }}</a>
                                </div>
                            </div>
                        </div>
                        <p v-else class="t-hint" style="padding: 0">Контактних осіб ще немає{{ canEdit ? ' — додайте у «Змінити».' : '.' }}</p>
                        <div v-if="card.tel || card.email" class="p-small c-general">
                            Загальні:
                            <a v-if="card.tel" :href="'tel:' + card.tel">{{ card.phone }}</a>
                            <template v-if="card.tel && card.email">·</template>
                            <a v-if="card.email" :href="'mailto:' + card.email">{{ card.email }}</a>
                        </div>
                    </div>

                    <div class="t-panel">
                        <h2>Реквізити</h2>
                        <dl class="t-dl">
                            <dt>Повна назва</dt>
                            <dd>{{ card.name }}</dd>
                            <dt>{{ card.codeLabel }}</dt>
                            <dd>{{ card.code ?? '—' }}</dd>
                            <dt>ПДВ</dt>
                            <dd>
                                {{ card.vatLabel ?? 'не вказано' }}
                                <template v-if="card.vatNumber">, ІПН {{ card.vatNumber }}</template>
                            </dd>
                            <template v-for="a in address" :key="a.label">
                                <dt>{{ a.label }}</dt>
                                <dd>{{ a.value }}</dd>
                            </template>
                            <template v-if="card.iban">
                                <dt>Рахунок</dt>
                                <dd>
                                    <button type="button" class="p-link c-iban" title="Скопіювати IBAN" @click="copy(card.iban, 'IBAN')">{{ card.iban }}</button>
                                    <div v-if="card.bankName" class="p-muted p-small">{{ card.bankName }}</div>
                                </dd>
                            </template>
                            <template v-if="card.externalCode">
                                <dt>Код 1С</dt>
                                <dd>{{ card.externalCode }}</dd>
                            </template>
                        </dl>
                        <div v-if="card.note" class="t-desc" style="margin-top: 8px">{{ card.note }}</div>
                    </div>
                </div>
            </section>

            <aside class="t-side" :class="{ 'hide-mobile': tab === 'card' }">
                <div class="t-panel">
                    <h2>Гроші</h2>
                    <dl class="t-dl">
                        <dt>Умови оплати</dt>
                        <dd>{{ card.termsLabel ?? 'не вказано' }}</dd>
                        <dt>Кредитний ліміт</dt>
                        <dd>{{ hryvnia(card.creditLimit) }}</dd>
                        <dt>Менеджер</dt>
                        <dd>{{ card.manager?.name ?? '—' }}</dd>
                    </dl>
                    <p class="t-hint" style="padding: 6px 0 0">Борг і прострочення зʼявляться разом із документами (проведення).</p>
                </div>

                <div class="t-panel">
                    <h2>
                        Угоди
                        <span class="count">{{ card.tasks.length }}</span>
                    </h2>
                    <div v-if="card.tasks.length" class="w-history">
                        <router-link v-for="t in card.tasks" :key="t.id" :to="{ name: 'tasks-board', params: { id: t.id } }" class="w-hist">
                            <span :class="{ closed: t.closed }">№ {{ t.number }} · {{ t.title }}</span>
                            <small class="p-muted">{{ t.statusLabel }}{{ t.linked ? '' : ' · не привʼязана' }}</small>
                        </router-link>
                    </div>
                    <p v-else class="t-hint" style="padding: 0">Задач із цим контрагентом ще немає.</p>
                </div>

                <div v-if="card.links.suppliers.length || card.links.warehouseClients.length" class="t-panel">
                    <h2>Також у довідниках</h2>
                    <div v-for="s in card.links.suppliers" :key="'s' + s.id" class="p-small">Постачальник заявок: {{ s.name }}</div>
                    <div v-for="w in card.links.warehouseClients" :key="'w' + w.id" class="p-small">Клієнт складу: {{ w.name }}</div>
                </div>

                <div class="t-panel">
                    <h2>Історія</h2>
                    <div v-if="card.history.length" class="c-history">
                        <div v-for="(h, i) in card.history" :key="i" class="c-hist">
                            <small class="p-muted">
                                {{ formatDate(h.at) }}
                                <template v-if="h.who">· {{ h.who }}</template>
                            </small>
                            <span>{{ h.text }}</span>
                        </div>
                    </div>
                    <p v-else class="t-hint" style="padding: 0">Змін ще не було.</p>
                    <div class="p-small p-muted" style="margin-top: 6px">
                        Створено {{ formatDate(card.createdAt) }}
                        <template v-if="card.createdBy">· {{ card.createdBy.name }}</template>
                    </div>
                </div>

                <div v-if="canFinance" class="t-panel">
                    <button type="button" class="p-btn" :class="{ danger: !card.archived }" :disabled="busy" data-testid="cp-archive" @click="toggleArchive">
                        {{ card.archived ? 'Повернути з архіву' : 'Прибрати в архів' }}
                    </button>
                    <p class="t-hint" style="padding: 6px 0 0">Архів не видаляє: картка зникає зі списків вибору, історія й угоди лишаються.</p>
                </div>
            </aside>
        </div>
    </div>
</template>
