<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { call } from '../../app/api'
import { useSession } from '../../app/store'

/**
 * «Google Диск» — суперадміну й адміністратору: скільки файлів «Плану» вже на Диску,
 * у черзі, зі збоєм (6 невдалих спроб), чи не зупинено копіювання (відкликаний токен,
 * квота). «Повторити» — один файл або всі збої; «Відновити» — після нового токена.
 * Стан у адресі (?state=failed), тож «Назад» і посилання працюють.
 */
type State = 'pending' | 'done' | 'failed' | 'skipped'
interface Item { type: 'task' | 'supply' | 'wh'; id: number; name: string; state: State; attempts: number; error: string | null; nextAt: string | null; driveUrl: string | null }
interface Status {
    layout: 'plan' | 'stand'
    configured: boolean
    fake: boolean
    status: { authBrokenAt: string | null; pausedUntil: string | null; lastRunAt: string | null; lastOkAt: string | null; lastError: string | null }
    counts: Record<'task' | 'supply' | 'wh', Record<State, number>>
    state: State
    items: Item[]
    maxAttempts: number
}

const route = useRoute()
const router = useRouter()
const session = useSession()
const data = ref<Status | null>(null)
const error = ref('')
const busy = ref('')

const STATES: { value: State; label: string }[] = [
    { value: 'failed', label: 'Збої' },
    { value: 'pending', label: 'У черзі' },
    { value: 'done', label: 'На Диску' },
    { value: 'skipped', label: 'Пропущені' },
]
const TYPES = { task: 'Задачі', supply: 'Заявки', wh: 'Склад' } as const

const state = computed<State>(() => (STATES.some((s) => s.value === route.query.state) ? (route.query.state as State) : 'failed'))
const total = (s: State) => (data.value ? Object.values(data.value.counts).reduce((n, c) => n + (c[s] ?? 0), 0) : 0)

async function load() {
    try {
        data.value = await call<Status>(`/api/plan/drive?state=${state.value}`)
        error.value = ''
    } catch (e) {
        error.value = session.handle(e) || 'Сторінка доступна лише адміністраторам.'
    }
}

async function act(label: string, url: string, body: unknown) {
    busy.value = label
    try {
        await call(url, { method: 'POST', body: JSON.stringify(body) })
        await load()
    } catch (e) {
        error.value = session.handle(e) || (e as Error).message
    } finally {
        busy.value = ''
    }
}

function fileRoute(item: Item) {
    return item.type === 'task' ? { name: 'file', params: { id: String(item.id) } } : { name: 'file-typed', params: { type: item.type, id: String(item.id) } }
}

function when(at: string | null): string {
    // «2026-11-14 18:56:00» → «14.11 18:56»
    const m = at?.match(/^\d{4}-(\d{2})-(\d{2})[ T](\d{2}:\d{2})/)
    return m ? `${m[2]}.${m[1]} ${m[3]}` : '—'
}

watch(state, load)
onMounted(load)
</script>

<template>
    <div class="p-profile p-drive">
        <section class="p-card">
            <h2>Google Диск</h2>
            <p class="p-muted">Копії всіх файлів «Плану» — деревом План / рік / Задачі · Заявки · Склад / № назва. Тільки в один бік: видалення в «Плані» на Диску нічого не стирає.</p>

            <p v-if="error" class="p-drive-alert" role="alert">{{ error }}</p>

            <template v-if="data">
                <p v-if="data.status.authBrokenAt" class="p-drive-alert" role="alert">
                    Google відкликав доступ {{ when(data.status.authBrokenAt) }} — копіювання зупинено. Потрібен новий токен Диска на сервері, потім:
                    <button type="button" class="p-btn" :disabled="busy === 'resume'" @click="act('resume', '/api/plan/drive/resume', {})">Відновити</button>
                </p>
                <p v-else-if="data.status.pausedUntil" class="p-drive-warn">Пауза через квоту Google до {{ when(data.status.pausedUntil) }}.</p>
                <p v-if="!data.configured" class="p-drive-warn">Ключі Диска на сервері ще не налаштовані — файли чекають у черзі й нікуди не губляться.</p>
                <p v-if="data.layout !== 'plan'" class="p-drive-warn">Цей стенд копіює старим деревом заявок (supply:drive-sync).</p>
                <p v-if="data.fake" class="p-drive-warn">Локальний стенд: замість Google — тестовий Диск у теці сервера.</p>

                <div class="p-drive-stats">
                    <div><b>{{ total('done') }}</b><span>на Диску</span></div>
                    <div><b>{{ total('pending') }}</b><span>у черзі</span></div>
                    <div :class="{ bad: total('failed') > 0 }"><b>{{ total('failed') }}</b><span>збоїв</span></div>
                    <div><b>{{ when(data.status.lastOkAt) }}</b><span>останнє копіювання</span></div>
                </div>
                <p v-if="data.status.lastError" class="p-muted p-small">Остання помилка: {{ data.status.lastError }}</p>

                <nav class="p-drive-tabs" aria-label="Стан файлів">
                    <router-link v-for="s in STATES" :key="s.value" :to="{ query: { state: s.value } }" replace :class="{ on: s.value === state }">{{ s.label }} ({{ total(s.value) }})</router-link>
                </nav>

                <button v-if="state === 'failed' && total('failed') > 0" type="button" class="p-btn" :disabled="busy === 'all'" @click="act('all', '/api/plan/drive/retry', { all: true })">Повторити всі збої</button>

                <ul class="p-drive-list">
                    <li v-for="item in data.items" :key="item.type + item.id">
                        <div class="main">
                            <router-link :to="fileRoute(item)" class="nm">{{ item.name }}</router-link>
                            <span class="p-muted p-small">{{ TYPES[item.type] }}<template v-if="item.state !== 'done'"> · спроб {{ item.attempts }}/{{ data.maxAttempts }}</template><template v-if="item.nextAt"> · наступна {{ when(item.nextAt) }}</template></span>
                            <span v-if="item.error" class="err">{{ item.error }}</span>
                        </div>
                        <a v-if="item.driveUrl" :href="item.driveUrl" target="_blank" rel="noopener noreferrer" class="p-btn">Диск</a>
                        <button v-if="item.state === 'failed' || item.state === 'pending'" type="button" class="p-btn" :disabled="busy === item.type + item.id" @click="act(item.type + item.id, '/api/plan/drive/retry', { type: item.type, id: item.id })">Повторити</button>
                    </li>
                    <li v-if="!data.items.length" class="p-muted">Порожньо.</li>
                </ul>
            </template>
            <p v-else-if="!error" class="p-muted">Завантаження…</p>
        </section>
    </div>
</template>

<style>
.p-drive { margin: 16px auto; }
.p-drive h2 { margin: 0 0 6px; font-size: 18px; }
.p-drive-alert { background: #fde8e8; color: #9b1c1c; padding: 10px 12px; border-radius: 8px; }
.p-drive-warn { background: #fff5d6; color: #7a5300; padding: 8px 12px; border-radius: 8px; font-size: 14px; }
.p-drive-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; margin: 12px 0; }
.p-drive-stats > div { border: 1px solid var(--border); border-radius: 10px; padding: 10px; display: flex; flex-direction: column; }
.p-drive-stats b { font-size: 18px; }
.p-drive-stats span { font-size: 12px; opacity: .7; }
.p-drive-stats .bad b { color: #b91c1c; }
.p-drive-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 12px 0 8px; }
.p-drive-tabs a { padding: 6px 10px; border-radius: 8px; border: 1px solid var(--border); text-decoration: none; color: inherit; font-size: 14px; }
.p-drive-tabs a.on { background: var(--accent); border-color: var(--accent); color: #fff; }
.p-drive-list { list-style: none; padding: 0; margin: 8px 0 0; display: flex; flex-direction: column; }
.p-drive-list li { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px solid var(--border); }
.p-drive-list .main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.p-drive-list .nm { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: inherit; }
.p-drive-list .err { font-size: 12px; color: #b91c1c; overflow-wrap: anywhere; }
</style>
