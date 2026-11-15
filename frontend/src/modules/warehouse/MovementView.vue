<script setup lang="ts">
import PersonName from '../../plan/PersonName.vue'
import { money } from '../../plan/uk'
import { onMounted, ref, watch } from 'vue'
import { useSession } from '../../app/store'
import Icon from '../tasks/shell/Icon.vue'
import { formatDate, formatDateTime } from '../../shared/format'
import { whApi, type WhMovement } from './api'
import { accessError } from './access'
import './warehouse.css'

/** Рух повністю: звідки → куди, позиції, накладна, журнал. Рухи не редагуються — як і в Twig-складі. */
const props = defineProps<{ id: string; embedded?: boolean }>()
const emit = defineEmits<{ close: [] }>()
const session = useSession()

const movement = ref<WhMovement | null>(null)
const error = ref('')

let seq = 0
async function load() {
    const mine = ++seq
    try {
        const m = await whApi.movement(Number(props.id))
        if (mine !== seq) return
        movement.value = m
        error.value = ''
    } catch (e) {
        if (mine !== seq) return
        error.value = accessError(e) || session.handle(e)
    }
}

watch(
    () => props.id,
    (id) => {
        movement.value = null
        load()
        whApi.movementViewed(Number(id)).catch(() => undefined)
    },
)
onMounted(() => {
    load()
    // Один раз на відкриття — як і картка позиції.
    whApi.movementViewed(Number(props.id)).catch(() => undefined)
})
</script>

<template>
    <div class="t-page w-move-view" :class="{ 'p-embedded': embedded }">
        <div v-if="!movement" class="t-empty">
            <p>{{ error || 'Завантаження…' }}</p>
            <button v-if="error && embedded" type="button" class="t-btn" @click="emit('close')">← Назад</button>
        </div>
        <div v-else class="w-move">
            <div class="p-thead">
                <div class="p-thead-top">
                    <button v-if="embedded" type="button" class="p-icon-btn p-back" title="Назад (Esc)" aria-label="Назад" @click="emit('close')"><Icon name="back" /></button>
                    <router-link v-else :to="{ name: 'warehouse-movements' }" class="p-icon-btn p-back" title="До рухів" aria-label="До рухів"><Icon name="back" /></router-link>
                    <span class="num">{{ movement.emoji }} {{ movement.typeLabel }}</span>
                    <span class="sp" />
                    <router-link
                        v-if="embedded"
                        :to="{ name: 'warehouse-movement-full', params: { id: movement.id } }"
                        class="p-icon-btn"
                        title="Відкрити окремо"
                        aria-label="Відкрити окремо"
                    >
                        <Icon name="monitor" :size="18" />
                    </router-link>
                    <button v-if="embedded" type="button" class="p-icon-btn p-close" title="Закрити (Esc)" aria-label="Закрити" @click="emit('close')"><Icon name="x" /></button>
                </div>
                <h1 class="p-ttitle">{{ movement.title }}</h1>
                <div class="p-thead-meta">
                    <b class="w-big">{{ movement.route }}</b>
                    <span v-if="movement.client" class="p-muted">· {{ movement.client }}</span>
                    <span v-if="movement.supplier" class="p-muted">· від {{ movement.supplier }}</span>
                </div>
            </div>

            <div class="w-move-body">
                <section class="t-panel">
                    <h2>
                        Позиції
                        <span class="count">{{ movement.items.length }}</span>
                    </h2>
                    <table class="w-lines">
                        <tbody>
                            <tr v-for="l in movement.items" :key="l.id">
                                <td>
                                    <router-link :to="{ name: 'warehouse-items', params: { id: l.item.id } }">{{ l.item.inventoryNumber }}</router-link>
                                </td>
                                <td>{{ l.item.name }}</td>
                                <td class="n">{{ l.quantity.toLocaleString('uk-UA') }} {{ l.item.unit }}</td>
                                <td v-if="movement.items.some((x) => x.rentalRate)" class="n p-muted">{{ l.rentalRate ? money(l.rentalRate) + '/доба' : '' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="t-panel">
                    <h2>Деталі</h2>
                    <dl class="t-dl">
                        <dt>Дата</dt>
                        <dd>{{ formatDate(movement.occurredAt) }}</dd>
                        <dt>Звідки</dt>
                        <dd>{{ movement.from?.label ?? 'ззовні' }}</dd>
                        <dt>Куди</dt>
                        <dd>{{ movement.to?.label ?? 'списано' }}</dd>
                        <template v-if="movement.documentNumber">
                            <dt>Документ</dt>
                            <dd>№ {{ movement.documentNumber }}</dd>
                        </template>
                        <template v-if="movement.createdBy">
                            <dt>Записав</dt>
                            <dd><PersonName :person="movement.createdBy" /></dd>
                        </template>
                    </dl>
                    <div v-if="movement.note" class="t-desc" style="margin-top: 8px">{{ movement.note }}</div>
                </section>

                <section class="t-panel">
                    <h2>
                        Документи
                        <span class="count">{{ movement.documents.length }}</span>
                    </h2>
                    <div v-if="movement.documents.length" class="p-docs">
                        <div v-for="d in movement.documents" :key="d.id" class="p-doc">
                            <div class="p-doc-info">
                                <a :href="d.url" target="_blank" rel="noopener" class="p-doc-name">{{ d.name }}</a>
                                <div class="p-muted p-small">
                                    {{ d.typeLabel }} · {{ d.sizeLabel }} ·
                                    <a :href="d.download">Скачати</a>
                                    <template v-if="d.driveUrl">
                                        ·
                                        <a :href="d.driveUrl" target="_blank" rel="noopener">Google Drive</a>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="t-hint" style="padding: 0">Накладної немає.</p>
                </section>

                <section class="t-panel">
                    <h2>Журнал</h2>
                    <div v-for="j in movement.journal" :key="j.id" class="w-journal-row">
                        <span>
                            {{ j.emoji }}
                            <PersonName v-if="j.user" :person="j.user" bold />
                            <b v-else>Система</b>
                            {{ j.actionLabel.toLowerCase() }}
                            <template v-if="j.details">· {{ j.details }}</template>
                        </span>
                        <small class="p-muted">{{ formatDateTime(j.at) }} · {{ j.channelLabel }}</small>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
