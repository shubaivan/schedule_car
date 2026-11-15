<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSession } from '../../app/store'
import { goBack } from '../../plan/layers'
import { notify } from '../../plan/undo'
import Icon from '../tasks/shell/Icon.vue'
import { canSeeJoinRequests } from './access'
import { joinApi, type JoinRequestList, type JoinRequestRow } from './api'
import { auditStamp } from './format'

/**
 * Заявки на приєднання — люди, які відкрили бота «Плану», поділились номером, а такого
 * номера серед співробітників немає. Суперадмін затверджує (роль, відділ, ім'я) або
 * відхиляє; людина отримує відповідь у боті й далі входить через Telegram.
 *
 * Картка заявки — маршрут /admin/join/:id (десктоп — праворуч, телефон — на весь екран).
 */
const session = useSession()
const route = useRoute()
const router = useRouter()
const allowed = computed(() => canSeeJoinRequests(session.user))

const data = ref<JoinRequestList | null>(null)
const error = ref('')
const busy = ref(false)
const showDecided = ref(false)
const form = reactive({ role: 'production', departmentId: 0, firstName: '', lastName: '', position: '', reason: '' })

const openId = computed(() => (route.params.id ? Number(route.params.id) : null))
const open = computed<JoinRequestRow | null>(() => data.value?.items.find((r) => r.id === openId.value) ?? null)
const rows = computed(() => (data.value?.items ?? []).filter((r) => showDecided.value || r.status === 'pending'))
const decidedCount = computed(() => (data.value?.items ?? []).filter((r) => r.status !== 'pending').length)

async function load() {
    try {
        data.value = await joinApi.list()
    } catch (e) {
        error.value = session.handle(e)
    }
}

function select(row: JoinRequestRow) {
    const to = { name: 'admin-join', params: { id: String(row.id) } }
    if (openId.value) router.replace(to)
    else router.push(to)
}

function close() {
    goBack(router, { name: 'admin-join' })
}

async function approve() {
    if (!open.value) return
    busy.value = true
    try {
        const { reason: _reason, ...card } = form
        await joinApi.approve(open.value.id, card)
        notify(`${form.firstName || open.value.name} — у Плані. Бот повідомив людину.`)
        await load()
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        busy.value = false
    }
}

async function reject() {
    if (!open.value) return
    busy.value = true
    try {
        await joinApi.reject(open.value.id, form.reason)
        notify('Заявку відхилено. Бот повідомив людину.')
        await load()
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        busy.value = false
    }
}

watch(
    open,
    (row) => {
        error.value = ''
        Object.assign(form, { role: 'production', departmentId: 0, firstName: row?.firstName ?? '', lastName: row?.lastName ?? '', position: '', reason: '' })
    },
    { immediate: true },
)

onMounted(() => {
    if (allowed.value) load()
})
</script>

<template>
    <div v-if="!allowed" class="t-page a-denied">
        <h1>Заявки на приєднання</h1>
        <p class="muted">Цей розділ бачить лише суперадмін.</p>
    </div>

    <div v-else class="p-split p-staff p-join" :class="{ split: openId !== null }">
        <div class="t-page p-split-left">
            <div class="t-head">
                <div>
                    <h1>Заявки на приєднання</h1>
                    <div class="t-sub">{{ data ? (data.pending ? `Чекають рішення: ${data.pending}` : 'Нових заявок немає') : 'Завантаження…' }}</div>
                </div>
                <div class="t-actions">
                    <router-link class="t-btn small ghost" :to="{ name: 'staff-new' }">＋ Додати за телефоном</router-link>
                    <router-link class="t-btn small ghost" :to="{ name: 'admin-audit', query: { group: 'login' } }">Журнал входів</router-link>
                </div>
            </div>
            <p class="t-hint" style="padding: 0">
                Людина натиснула «Увійти через Telegram» і поділилась номером, якого ще немає серед співробітників.
                Затвердіть — і вона одразу зможе входити через Telegram. Знайомих людей простіше додати заздалегідь: «Додати за телефоном».
            </p>

            <div v-if="error && !open" class="t-error">{{ error }}</div>

            <table class="t-table responsive">
                <thead>
                    <tr><th>Хто</th><th>Телефон</th><th>Коли</th><th>Стан</th></tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id" :class="{ sel: r.id === openId }">
                        <td>
                            <a :href="'/crm/admin/join/' + r.id" @click.prevent="select(r)"><b>{{ r.name }}</b></a>
                            <div v-if="r.username" class="t-hint" style="padding: 0">@{{ r.username }}</div>
                        </td>
                        <td style="white-space: nowrap">{{ r.phone }}</td>
                        <td style="white-space: nowrap">{{ auditStamp(r.createdAt) }}</td>
                        <td>
                            <span class="t-status" :class="r.status === 'pending' ? 'tone-amber' : r.status === 'approved' ? 'tone-green' : 'tone-slate'">{{ r.statusLabel }}</span>
                            <div v-if="r.decidedBy" class="t-hint" style="padding: 0">{{ r.decidedBy }}</div>
                        </td>
                    </tr>
                    <tr v-if="data && !rows.length"><td colspan="4" class="t-empty">Немає заявок, що чекають рішення.</td></tr>
                </tbody>
            </table>
            <button v-if="decidedCount" type="button" class="p-link" @click="showDecided = !showDecided">
                {{ showDecided ? 'Сховати розглянуті' : `Показати розглянуті (${decidedCount})` }}
            </button>
        </div>

        <section v-if="openId !== null" class="p-split-right p-staff-card">
            <div v-if="!open && data" class="t-panel t-empty">
                <p>Заявку не знайдено.</p>
                <button type="button" class="t-btn" @click="close">← До списку</button>
            </div>
            <form v-else-if="open" class="t-formcard p-form p-form-page" aria-labelledby="p-join-title" @submit.prevent="approve" @keydown.esc="close">
                <header>
                    <button type="button" class="p-icon-btn p-form-back" aria-label="Назад" @click="close"><Icon name="back" /></button>
                    <h2 id="p-join-title">Заявка №{{ open.id }}</h2>
                    <button type="button" class="p-icon-btn p-form-x" aria-label="Закрити" title="Закрити (Esc)" @click="close"><Icon name="x" /></button>
                </header>
                <div class="body">
                    <div v-if="error" class="t-error">{{ error }}</div>
                    <dl class="p-join-facts">
                        <dt>Telegram</dt><dd>{{ open.name }}<template v-if="open.username"> · @{{ open.username }}</template></dd>
                        <dt>Телефон</dt><dd>{{ open.phone }} <span class="t-hint" style="padding: 0">(підтвердив Telegram)</span></dd>
                        <dt>Подано</dt><dd>{{ auditStamp(open.createdAt) }}</dd>
                        <template v-if="open.status !== 'pending'">
                            <dt>Рішення</dt>
                            <dd>
                                {{ open.statusLabel }}<template v-if="open.decidedBy"> — {{ open.decidedBy }}</template><template v-if="open.decidedAt">, {{ auditStamp(open.decidedAt) }}</template>
                                <template v-if="open.userRole"> · {{ open.userRole }}</template>
                                <template v-if="open.reason"> · {{ open.reason }}</template>
                            </dd>
                        </template>
                    </dl>

                    <fieldset v-if="open.status === 'pending'" class="p-fieldset" :disabled="busy">
                        <div class="t-two">
                            <label class="t-field"><span>Імʼя *</span><input v-model="form.firstName" class="t-input" required /></label>
                            <label class="t-field"><span>Прізвище</span><input v-model="form.lastName" class="t-input" /></label>
                        </div>
                        <div class="t-two">
                            <label class="t-field"><span>Роль</span>
                                <select v-model="form.role" class="t-select"><option v-for="r in data?.roles" :key="r.value" :value="r.value">{{ r.label }}</option></select>
                            </label>
                            <label class="t-field"><span>Відділ</span>
                                <select v-model.number="form.departmentId" class="t-select"><option :value="0">— без відділу —</option><option v-for="d in data?.departments" :key="d.id" :value="d.id">{{ d.name }}</option></select>
                            </label>
                        </div>
                        <label class="t-field"><span>Посада</span><input v-model="form.position" class="t-input" placeholder="Технолог, майстер дільниці…" /></label>
                        <label class="t-field"><span>Причина відмови (необовʼязково, людина її побачить)</span><input v-model="form.reason" class="t-input" maxlength="200" /></label>
                    </fieldset>
                    <p v-else-if="open.userId" class="t-hint" style="padding: 0">
                        <router-link :to="{ name: 'staff-card', params: { id: String(open.userId) } }">Відкрити картку співробітника →</router-link>
                    </p>
                </div>
                <footer v-if="open.status === 'pending'">
                    <button type="button" class="t-btn ghost" :disabled="busy" @click="reject">✖ Відхилити</button>
                    <button type="submit" class="t-btn primary" :disabled="busy || !form.firstName.trim()">✅ Затвердити</button>
                </footer>
                <footer v-else>
                    <button type="button" class="t-btn ghost" @click="close">Закрити</button>
                </footer>
            </form>
            <div v-else class="t-empty">Завантаження…</div>
        </section>
    </div>
</template>

<style scoped>
.p-join-facts { display: grid; grid-template-columns: max-content 1fr; gap: 6px 14px; margin: 0 0 14px; font-size: .92rem; }
.p-join-facts dt { color: var(--text-muted); }
.p-join-facts dd { margin: 0; }
tr.sel td { background: var(--surface-2, rgba(0, 0, 0, .04)); }
</style>
