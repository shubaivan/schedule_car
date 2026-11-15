<script setup lang="ts">
import PersonName from '../../plan/PersonName.vue'
import { computed, onMounted, ref } from 'vue'
import { useSession } from '../../app/store'
import { auditApi, type AuditDetail, type AuditFilters } from './api'
import { auditStamp, CHANNELS, COMMENT_TYPES, GROUP_TONE, spaPath } from './format'

/**
 * Одна подія журналу — сторінка маршруту /admin/audit/:id (не модалка):
 * хто, коли, звідки, що саме (людською мовою і сирий payload), посилання на
 * суб'єкт і сусідні події того ж суб'єкта.
 */
const props = defineProps<{ id: number }>()
const emit = defineEmits<{ close: []; filter: [patch: AuditFilters] }>()

const session = useSession()
const event = ref<AuditDetail | null>(null)
const error = ref('')

const subjectPath = computed(() => spaPath(event.value?.subject.url ?? null))
const payloadText = computed(() => JSON.stringify(event.value?.payload ?? {}, null, 2))
const isComment = computed(() => !!event.value && COMMENT_TYPES.includes(event.value.type))

onMounted(async () => {
    try {
        event.value = await auditApi.event(props.id)
    } catch (e) {
        error.value = session.handle(e)
    }
})
</script>

<template>
    <article class="a-event" aria-live="polite">
        <header class="a-event-head">
            <button type="button" class="p-icon-btn a-back" title="До журналу ( Esc )" aria-label="До журналу" @click="emit('close')">←</button>
            <div v-if="event" class="a-event-title">
                <i class="a-badge" :class="'tone-' + (GROUP_TONE[event.group ?? ''] ?? 'slate')">{{ event.typeLabel }}</i>
                <h2>{{ event.subject.title ?? event.subject.key }}</h2>
            </div>
            <div v-else class="a-event-title"><h2>Подія #{{ id }}</h2></div>
        </header>

        <div v-if="error" class="t-error">{{ error }}</div>
        <div v-else-if="!event" class="t-empty">Завантаження…</div>

        <template v-else>
            <dl class="a-facts">
                <dt>Коли</dt>
                <dd>{{ auditStamp(event.at, true) }} <small class="muted">Київ</small></dd>

                <dt>Хто</dt>
                <dd>
                    <template v-if="event.actor">
                        {{ event.actor.name }}
                        <button type="button" class="p-link" @click="emit('filter', { actor: String(event.actor.id) })">усі дії</button>
                    </template>
                    <template v-else>{{ event.channel === 'cli' ? 'Система (консоль)' : 'невідомо' }}</template>
                </dd>

                <dt>Що</dt>
                <dd>
                    <small class="muted">{{ event.subject.kindLabel }} · {{ event.subject.key }}</small><br />
                    <router-link v-if="subjectPath" :to="subjectPath">{{ event.subject.title ?? 'відкрити' }}</router-link>
                    <a v-else-if="event.subject.url" :href="event.subject.url">{{ event.subject.title ?? 'відкрити' }}</a>
                    <span v-else>{{ event.subject.title ?? '—' }}</span>
                    <button type="button" class="p-link a-gap" @click="emit('filter', { subject: event.subject.key })">усі події</button>
                </dd>

                <template v-if="event.summary && !isComment">
                    <dt>Деталі</dt>
                    <dd>{{ event.summary }}</dd>
                </template>

                <template v-if="isComment || event.comment">
                    <dt>Коментар</dt>
                    <dd><blockquote class="a-quote">{{ isComment ? event.summary : event.comment }}</blockquote></dd>
                </template>

                <template v-if="event.people.length">
                    <dt>Люди</dt>
                    <dd><template v-for="(p, i) in event.people" :key="p.id"><template v-if="i">, </template><PersonName :person="p" /></template></dd>
                </template>

                <dt>Звідки</dt>
                <dd>
                    {{ CHANNELS[event.channel] ?? event.channel }}
                    <template v-if="event.ip">
                        · <button type="button" class="p-link" :title="'Усі події з ' + event.ip" @click="emit('filter', { ip: event.ip })">{{ event.ip }}</button>
                    </template>
                </dd>

                <template v-if="event.userAgent">
                    <dt>Браузер</dt>
                    <dd class="a-ua">{{ event.userAgent }}</dd>
                </template>

                <template v-if="event.backfill">
                    <dt>Джерело</dt>
                    <dd>перенесено з історії модуля (plan:events:backfill)</dd>
                </template>
            </dl>

            <section v-if="event.around.length > 1" class="a-around">
                <h3>Цей суб'єкт: події поруч</h3>
                <router-link
                    v-for="a in event.around"
                    :key="a.id"
                    :to="{ name: 'admin-audit', params: { id: String(a.id) }, query: $route.query }"
                    replace
                    class="a-around-row"
                    :class="{ here: a.id === event.id }"
                >
                    <span class="a-at">{{ auditStamp(a.at) }}</span>
                    <i class="a-badge" :class="'tone-' + (GROUP_TONE[a.group ?? ''] ?? 'slate')">{{ a.typeLabel }}</i>
                    <span class="a-around-text">
                        <PersonName v-if="a.actor" :person="a.actor" :link="false" /><template v-else>—</template><template v-if="a.summary"> · {{ a.summary }}</template>
                        <template v-if="a.comment"> · 💬 {{ a.comment }}</template>
                    </span>
                </router-link>
            </section>

            <details class="a-raw">
                <summary>Сирі дані події (payload)</summary>
                <pre>{{ payloadText }}</pre>
            </details>
        </template>
    </article>
</template>
