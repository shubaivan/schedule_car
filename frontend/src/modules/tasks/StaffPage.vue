<script setup lang="ts">
import PersonName from '../../plan/PersonName.vue'
import { formatPhone } from '../../plan/people'
import { people, plural, sortByName } from '../../plan/uk'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Avatar from './Avatar.vue'
import Icon from './shell/Icon.vue'
import { tasksApi, type StaffDepartment, type StaffRow, type Person } from './api'
import { ago } from './format'
import { useTasks } from './store'
import { useSession } from '../../app/store'
import { goBack } from '../../plan/layers'
import { notify, undoable } from '../../plan/undo'
import { canSeeJoinRequests } from '../admin/access'

/**
 * Співробітники — як розділ налаштувань ПланФікса (скріни клієнта 13.11.2026):
 * усі співробітники, відділи, структура компанії (з друком), кошик.
 * Людей не видаляємо назавжди — кошик, щоб через роки знайти їхні повідомлення й файли.
 *
 * Картка — не модалка, а адреса: /staff/12 і /staff/new. На десктопі вона праворуч
 * від списку, на телефоні — на весь екран; «Назад» повертає до списку.
 */
type Tab = 'all' | 'deps' | 'org' | 'trash'
const TABS: Tab[] = ['all', 'deps', 'org', 'trash']
const session = useSession()
const store = useTasks()
const route = useRoute()
const router = useRouter()
const tab = computed<Tab>({
    get: () => (TABS.includes(route.query.tab as Tab) ? (route.query.tab as Tab) : 'all'),
    set: (value) => router.replace({ query: value === 'all' ? {} : { tab: value } }),
})
const rows = ref<StaffRow[]>([])
const trash = ref<StaffRow[]>([])
const deps = ref<StaffDepartment[]>([])
const unassigned = ref<Person[]>([])
const q = ref('')
const error = ref('')
/** Відкрита картка — з адреси: /staff/new або /staff/:id. */
const editing = computed<StaffRow | 'new' | null>(() => {
    if (route.name === 'staff-new') return 'new'
    if (route.name !== 'staff-card') return null
    const id = Number(route.params.id)
    return rows.value.find((r) => r.id === id) ?? trash.value.find((r) => r.id === id) ?? null
})
const loaded = ref(false)
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
        rows.value = sortByName(all.items)
        deps.value = d.items
        unassigned.value = d.unassigned
        if (isAdmin.value) trash.value = (await tasksApi.staff(true)).items
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        loaded.value = true
    }
}

function open(row: StaffRow | 'new') {
    const to = row === 'new' ? { name: 'staff-new', query: route.query } : { name: 'staff-card', params: { id: String(row.id) }, query: route.query }
    // Уже відкрита картка — гортаємо без нових кроків історії; інакше «Назад» має повертати до списку.
    if (editing.value) router.replace(to)
    else router.push(to)
}

function close() {
    goBack(router, { name: 'staff', query: route.query })
}

function fill(row: StaffRow | 'new' | null) {
    if (!row) return
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
        await store.load(true)
        await load()
        notify('Збережено')
        close()
    } catch (e) {
        error.value = session.handle(e)
    }
}

/** У кошик одразу, без вікна підтвердження: тост «Скасувати» повертає людину назад. */
async function toTrash(r: StaffRow) {
    try {
        await tasksApi.trashStaff(r.id)
        await load()
        undoable(`«${r.name}» у кошику — повідомлення й файли лишились`, {
            undo: async () => {
                try {
                    await tasksApi.restoreStaff(r.id)
                    await load()
                } catch (e) {
                    error.value = session.handle(e)
                }
            },
        })
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

// Нова картка чи інша людина — заповнюємо форму наново (і коли список щойно довантажився).
watch(() => [route.name, route.params.id, loaded.value] as const, () => fill(editing.value), { immediate: true })

onMounted(load)
</script>

<template>
    <div class="p-split p-staff" :class="{ split: route.name === 'staff-new' || route.name === 'staff-card' }">
    <div class="t-page p-split-left">
        <div class="t-head">
            <div>
                <h1>Співробітники</h1>
                <div class="t-sub">{{ people(rows.length) }} · {{ deps.length }} {{ plural(deps.length, 'відділ', 'відділи', 'відділів') }}</div>
            </div>
            <div class="t-actions t-noprint">
                <button v-if="tab === 'org'" type="button" class="t-btn" @click="printOrg">🖨 Друк</button>
                <router-link v-if="canSeeJoinRequests(session.user)" :to="{ name: 'admin-join' }" class="t-btn">🙋 Заявки на приєднання</router-link>
                <router-link v-if="isAdmin" :to="{ name: 'staff-new', query: route.query }" class="t-btn primary">＋ Новий співробітник</router-link>
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
                        <td><a :href="'/crm/staff/' + r.id" class="p-staff-name" :class="{ sel: editing !== 'new' && editing?.id === r.id }" @click.prevent="open(r)"><PersonName :person="r" bold :link="false" /></a> <span v-if="r.telegram" title="Підключений до Telegram-бота: отримує сповіщення">✈️</span><div class="t-hint" style="padding: 0">{{ r.position ?? '—' }}</div></td>
                        <td>{{ r.department ?? '—' }}</td>
                        <td><span class="t-status tone-slate">{{ r.roleLabel }}</span></td>
                        <td class="hide-sm" style="font-size: .85rem">{{ r.email ?? '' }}<div class="t-hint" style="padding: 0"><a v-if="formatPhone(r.phone)" :href="'tel:' + formatPhone(r.phone)!.tel">{{ formatPhone(r.phone)!.phone }}</a></div></td>
                        <td class="hide-sm" style="font-size: .85rem; color: var(--text-muted)">{{ r.lastSeenAt ? ago(r.lastSeenAt) : 'входів ще не було' }}</td>
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
                            <div style="min-width: 0"><div class="n"><PersonName :person="p" /></div><div class="p">{{ p.position ?? p.roleLabel }}</div></div>
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
                        <div><div class="n"><PersonName :person="p" /></div><div class="p">{{ p.position }}</div></div>
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
                                <div style="min-width: 0"><div class="n" style="font-size: .85rem"><PersonName :person="p" /></div><div class="p">{{ p.position }}</div></div>
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
                        <td><PersonName :person="r" bold /><div class="t-hint" style="padding: 0">{{ r.position }}</div></td>
                        <td>{{ r.roleLabel }}</td>
                        <td><PersonName v-if="r.archivedBy" :name="r.archivedBy" /><template v-else>—</template></td>
                        <td>{{ r.archivedAt ? new Date(r.archivedAt).toLocaleString('uk-UA', { dateStyle: 'short', timeStyle: 'short' }) : '' }}</td>
                        <td style="text-align: right"><button type="button" class="t-btn small" @click="restore(r)">↩ Відновити</button></td>
                    </tr>
                    <tr v-if="!trash.length"><td colspan="6" class="t-empty">Кошик порожній.</td></tr>
                </tbody>
            </table>
        </template>

    </div>

    <!-- Картка співробітника — праворуч від списку (телефон: на весь екран). -->
    <section v-if="route.name === 'staff-new' || route.name === 'staff-card'" class="p-split-right p-staff-card">
        <div v-if="!editing && loaded" class="t-panel t-empty">
            <p>Співробітника не знайдено.</p>
            <button type="button" class="t-btn" @click="close">← До списку</button>
        </div>
        <form v-else-if="editing" class="t-formcard p-form p-form-page" aria-labelledby="p-staff-title" @submit.prevent="save" @keydown.esc="close">
            <header>
                <button type="button" class="p-icon-btn p-form-back" aria-label="Назад" @click="close"><Icon name="back" /></button>
                <h2 id="p-staff-title">{{ editing === 'new' ? 'Новий співробітник' : 'Картка співробітника' }}</h2>
                <button type="button" class="p-icon-btn p-form-x" aria-label="Закрити" title="Закрити (Esc)" @click="close"><Icon name="x" /></button>
            </header>
            <div class="body">
                <div v-if="error" class="t-error">{{ error }}</div>
                <fieldset class="p-fieldset" :disabled="!isAdmin">
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
                        <label class="t-field"><span>Телефон (для входу через Telegram)</span><input v-model="form.phone" class="t-input" inputmode="tel" autocomplete="off" placeholder="050 123 45 67" /></label>
                    </div>
                    <div class="t-hint" style="padding: 0">Людина входить у План кнопкою «Увійти через Telegram»: уперше бот попросить поділитися номером і впізнає її за цим телефоном. Пароль не потрібен.</div>
                    <label v-if="isAdmin" class="t-field"><span>{{ editing === 'new' ? 'Пароль (необовʼязково, запасний вхід)' : 'Новий пароль (необовʼязково; порожнє — без змін)' }}</span><input v-model="form.password" type="password" class="t-input" autocomplete="new-password" minlength="8" /></label>
                </fieldset>
            </div>
            <footer>
                <button type="button" class="t-btn ghost" @click="close">{{ isAdmin ? 'Скасувати' : 'Закрити' }}</button>
                <button v-if="isAdmin" type="submit" class="t-btn primary">Зберегти</button>
            </footer>
        </form>
        <div v-else class="t-empty">Завантаження…</div>
    </section>
    </div>
</template>
