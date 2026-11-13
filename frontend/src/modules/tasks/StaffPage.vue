<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import Avatar from './Avatar.vue'
import { tasksApi, type StaffDepartment, type StaffRow, type Person } from './api'
import { ago } from './format'
import { useTasks } from './store'
import { useSession } from '../../app/store'

/**
 * Співробітники — як розділ налаштувань ПланФікса (скріни клієнта 13.11.2026):
 * усі співробітники, відділи, структура компанії (з друком), кошик.
 * Людей не видаляємо назавжди — кошик, щоб через роки знайти їхні повідомлення й файли.
 */
const session = useSession()
const store = useTasks()
const tab = ref<'all' | 'deps' | 'org' | 'trash'>('all')
const rows = ref<StaffRow[]>([])
const trash = ref<StaffRow[]>([])
const deps = ref<StaffDepartment[]>([])
const unassigned = ref<Person[]>([])
const q = ref('')
const error = ref('')
const editing = ref<StaffRow | 'new' | null>(null)
const newDep = ref('')

const isAdmin = computed(() => !!store.meta?.me.can.staff)
const filtered = computed(() => {
    const s = q.value.trim().toLowerCase()
    return rows.value.filter((r) => !s || [r.name, r.position, r.department, r.email, r.phone].some((v) => (v ?? '').toLowerCase().includes(s)))
})
const direction = computed(() => deps.value.find((d) => /дирекц/i.test(d.name)))
const branches = computed(() => deps.value.filter((d) => d !== direction.value))

const form = reactive({ firstName: '', lastName: '', position: '', email: '', phone: '', role: 'production', departmentId: 0, password: '' })

async function load() {
    try {
        await store.load()
        const [all, d] = await Promise.all([tasksApi.staff(), tasksApi.departments()])
        rows.value = all.items
        deps.value = d.items
        unassigned.value = d.unassigned
        if (isAdmin.value) trash.value = (await tasksApi.staff(true)).items
    } catch (e) {
        error.value = session.handle(e)
    }
}

function open(row: StaffRow | 'new') {
    editing.value = row
    const r = row === 'new' ? null : row
    Object.assign(form, {
        firstName: r?.firstName ?? '', lastName: r?.lastName ?? '', position: r?.position ?? '', email: r?.email ?? '',
        phone: r?.phone ?? '', role: r?.role ?? 'production', departmentId: r?.departmentId ?? 0, password: '',
    })
    error.value = ''
}

async function save() {
    try {
        if (editing.value === 'new') await tasksApi.createStaff({ ...form })
        else if (editing.value) await tasksApi.updateStaff(editing.value.id, { ...form })
        editing.value = null
        await store.load(true)
        await load()
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function toTrash(r: StaffRow) {
    if (!confirm(`Перенести «${r.name}» у кошик? Повідомлення й файли лишаться.`)) return
    try {
        await tasksApi.trashStaff(r.id)
        await load()
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function restore(r: StaffRow) {
    await tasksApi.restoreStaff(r.id)
    await load()
}

async function addDep() {
    if (!newDep.value.trim()) return
    try {
        await tasksApi.createDepartment(newDep.value)
        newDep.value = ''
        await store.load(true)
        await load()
    } catch (e) {
        error.value = session.handle(e)
    }
}

const depColor = (i: number) => ['#6366f1', '#eab308', '#94a3b8', '#3b82f6', '#a78bfa', '#10b981', '#38bdf8', '#facc15', '#6b7280'][i % 9]

const printOrg = () => window.print()

onMounted(load)
</script>

<template>
    <div class="t-page">
        <div class="t-head">
            <div>
                <h1>Співробітники</h1>
                <div class="t-sub">{{ rows.length }} осіб · {{ deps.length }} відділів</div>
            </div>
            <div class="t-actions t-noprint">
                <button v-if="tab === 'org'" type="button" class="t-btn" @click="printOrg">🖨 Друк</button>
                <button v-if="isAdmin" type="button" class="t-btn primary" @click="open('new')">＋ Новий співробітник</button>
            </div>
        </div>

        <div class="t-seg t-noprint">
            <button type="button" :class="{ on: tab === 'all' }" @click="tab = 'all'">Усі співробітники</button>
            <button type="button" :class="{ on: tab === 'deps' }" @click="tab = 'deps'">Відділи</button>
            <button type="button" :class="{ on: tab === 'org' }" @click="tab = 'org'">Структура компанії</button>
            <button v-if="isAdmin" type="button" :class="{ on: tab === 'trash' }" @click="tab = 'trash'">🗑 Кошик · {{ trash.length }}</button>
        </div>

        <div v-if="error && !editing" class="t-error">{{ error }}</div>

        <template v-if="tab === 'all'">
            <label class="t-search"><input v-model="q" class="t-input" placeholder="Пошук: імʼя, посада, відділ, email" /></label>
            <table class="t-table responsive">
                <thead>
                    <tr><th /><th>Співробітник</th><th>Відділ</th><th>Роль</th><th>Контакти</th><th>Активність</th><th v-if="isAdmin" /></tr>
                </thead>
                <tbody>
                    <tr v-for="r in filtered" :key="r.id">
                        <td class="av"><Avatar :person="r" :size="36" /></td>
                        <td><b>{{ r.name }}</b> <span v-if="r.telegram" title="Підключений до Telegram-бота: отримує сповіщення">✈️</span><div class="t-hint" style="padding: 0">{{ r.position ?? '—' }}</div></td>
                        <td>{{ r.department ?? '—' }}</td>
                        <td><span class="t-status tone-slate">{{ r.roleLabel }}</span></td>
                        <td class="hide-sm" style="font-size: .85rem">{{ r.email ?? '' }}<div class="t-hint" style="padding: 0">{{ r.phone ? '+' + r.phone : '' }}</div></td>
                        <td class="hide-sm" style="font-size: .85rem; color: var(--text-muted)">{{ r.lastSeenAt ? ago(r.lastSeenAt) : 'не заходив(ла)' }}</td>
                        <td v-if="isAdmin" style="white-space: nowrap; text-align: right">
                            <button type="button" class="t-btn small ghost" @click="open(r)">✏️</button>
                            <button v-if="!r.me" type="button" class="t-btn small ghost" title="У кошик" @click="toTrash(r)">🗑</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </template>

        <template v-else-if="tab === 'deps'">
            <div v-if="isAdmin" class="t-toolbar">
                <input v-model="newDep" class="t-input" style="max-width: 320px" placeholder="Новий відділ, напр. «10. Відділ якості»" @keydown.enter="addDep" />
                <button type="button" class="t-btn" @click="addDep">Додати</button>
            </div>
            <div class="t-hint" style="padding: 0">Обрали відділ у задачі — до неї додаються всі його люди.</div>
            <div class="t-deps">
                <div v-for="(d, i) in deps" :key="d.id" class="t-dep">
                    <h3><span class="ico" :style="{ background: depColor(i) }">{{ d.name.replace(/^\d+\.\s*/, '').charAt(0) }}</span>{{ d.name }}<span class="cnt">{{ d.people.length }}</span></h3>
                    <div class="t-people">
                        <div v-for="p in d.people" :key="p.id" class="t-person">
                            <Avatar :person="p" :size="28" />
                            <div style="min-width: 0"><div class="n">{{ p.name }}</div><div class="p">{{ p.position ?? p.roleLabel }}</div></div>
                        </div>
                        <div v-if="!d.people.length" class="t-hint" style="padding: 0">Поки нікого.</div>
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="tab === 'org'">
            <div class="t-org">
                <div class="top">
                    <div v-for="p in direction?.people ?? []" :key="p.id" class="t-person">
                        <Avatar :person="p" :size="40" />
                        <div><div class="n">{{ p.name }}</div><div class="p">{{ p.position }}</div></div>
                    </div>
                    <div v-if="!direction" class="t-hint">Дирекція</div>
                </div>
                <div class="line" />
                <div class="branches">
                    <div v-for="d in branches" :key="d.id" class="node">
                        <h4>{{ d.name }}</h4>
                        <div class="t-people">
                            <div v-for="p in d.people" :key="p.id" class="t-person">
                                <Avatar :person="p" :size="26" />
                                <div style="min-width: 0"><div class="n" style="font-size: .85rem">{{ p.name }}</div><div class="p">{{ p.position }}</div></div>
                            </div>
                            <div v-if="!d.people.length" class="t-hint" style="padding: 0">вакансія</div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <template v-else>
            <div class="t-hint" style="padding: 0">Співробітники не видаляються назавжди: їхні повідомлення й документи лишаються в задачах.</div>
            <table class="t-table responsive">
                <thead><tr><th /><th>Співробітник</th><th>Роль</th><th>Хто видалив</th><th>Дата</th><th /></tr></thead>
                <tbody>
                    <tr v-for="r in trash" :key="r.id">
                        <td class="av"><Avatar :person="r" :size="34" /></td>
                        <td><b>{{ r.name }}</b><div class="t-hint" style="padding: 0">{{ r.position }}</div></td>
                        <td>{{ r.roleLabel }}</td>
                        <td>{{ r.archivedBy ?? '—' }}</td>
                        <td>{{ r.archivedAt ? new Date(r.archivedAt).toLocaleString('uk-UA', { dateStyle: 'short', timeStyle: 'short' }) : '' }}</td>
                        <td style="text-align: right"><button type="button" class="t-btn small" @click="restore(r)">↩ Відновити</button></td>
                    </tr>
                    <tr v-if="!trash.length"><td colspan="6" class="t-empty">Кошик порожній.</td></tr>
                </tbody>
            </table>
        </template>

        <div v-if="editing" class="t-modal-wrap" @click.self="editing = null">
            <form class="t-modal" @submit.prevent="save">
                <header>
                    <h2>{{ editing === 'new' ? 'Новий співробітник' : 'Картка співробітника' }}</h2>
                    <button type="button" class="t-btn ghost icon" @click="editing = null">✕</button>
                </header>
                <div class="body">
                    <div v-if="error" class="t-error">{{ error }}</div>
                    <div class="t-two">
                        <label class="t-field"><span>Імʼя *</span><input v-model="form.firstName" class="t-input" /></label>
                        <label class="t-field"><span>Прізвище</span><input v-model="form.lastName" class="t-input" /></label>
                    </div>
                    <label class="t-field"><span>Посада</span><input v-model="form.position" class="t-input" placeholder="Технолог, майстер дільниці…" /></label>
                    <div class="t-two">
                        <label class="t-field"><span>Роль</span>
                            <select v-model="form.role" class="t-select"><option v-for="r in store.meta?.roles" :key="r.value" :value="r.value">{{ r.label }}</option></select>
                        </label>
                        <label class="t-field"><span>Відділ</span>
                            <select v-model.number="form.departmentId" class="t-select"><option :value="0">— без відділу —</option><option v-for="d in deps" :key="d.id" :value="d.id">{{ d.name }}</option></select>
                        </label>
                    </div>
                    <div class="t-two">
                        <label class="t-field"><span>Email (логін)</span><input v-model="form.email" type="email" class="t-input" autocomplete="off" /></label>
                        <label class="t-field"><span>Телефон</span><input v-model="form.phone" class="t-input" inputmode="tel" /></label>
                    </div>
                    <div class="t-hint" style="padding: 0">Коли людина зайде в Telegram-бот і поділиться цим номером, картка сама звʼяжеться з ботом — і сповіщення по задачах підуть у Telegram.</div>
                    <label class="t-field"><span>{{ editing === 'new' ? 'Пароль' : 'Новий пароль (залиште порожнім, щоб не міняти)' }}</span><input v-model="form.password" type="password" class="t-input" autocomplete="new-password" minlength="8" /></label>
                </div>
                <footer>
                    <button type="button" class="t-btn ghost" @click="editing = null">Скасувати</button>
                    <button type="submit" class="t-btn primary">Зберегти</button>
                </footer>
            </form>
        </div>
    </div>
</template>
