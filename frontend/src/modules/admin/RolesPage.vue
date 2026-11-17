<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSession } from '../../app/store'
import { notify } from '../../plan/undo'
import Icon from '../tasks/shell/Icon.vue'
import { canManageRoles } from './access'
import { rolesApi, type RoleCell, type RolePermissionRow, type RolesPageData } from './api'
import './roles.css'

/**
 * «Ролі й доступ» (Олександр 17.11): хто яку роль має, що кожна роль може (на основі тієї ж
 * моделі прав, що й раніше в коді), суперадміністратори й історія змін.
 * Керують адміністратор, системний адміністратор і суперадмін; сервер перевіряє кожну зміну
 * (зафіксовані клітинки, «Приховані розділи» — лише суперадмін, останній суперадмін лишається).
 * Без модалок: вкладки-маршрути /admin/roles/people|matrix|super|history.
 */
const session = useSession()
const route = useRoute()
const router = useRouter()
const allowed = computed(() => canManageRoles(session.user))

type Tab = 'people' | 'matrix' | 'super' | 'history'
const tab = computed<Tab>(() => (route.params.tab as Tab) || 'people')
const TABS: { key: Tab; label: string }[] = [
    { key: 'people', label: 'Люди й ролі' },
    { key: 'matrix', label: 'Права ролей' },
    { key: 'super', label: 'Суперадміни' },
    { key: 'history', label: 'Історія' },
]
function go(t: Tab) {
    router.replace({ name: 'admin-roles', params: { tab: t === 'people' ? '' : t } })
}

const data = ref<RolesPageData | null>(null)
const error = ref('')
const busy = ref(false)
const q = ref('')

async function load() {
    try {
        data.value = await rolesApi.load()
        error.value = ''
    } catch (e) {
        error.value = session.handle(e)
    }
}
onMounted(() => allowed.value && void load())

async function run(work: () => Promise<RolesPageData>, done: string) {
    if (busy.value) return
    busy.value = true
    try {
        data.value = await work()
        error.value = ''
        notify(done)
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        busy.value = false
    }
}

const roleLabel = (value: string) => data.value?.roles.find((r) => r.value === value)?.label ?? value
const people = computed(() => {
    const needle = q.value.trim().toLowerCase()
    return (data.value?.people ?? []).filter((p) => !needle || `${p.name} ${p.position ?? ''} ${p.department ?? ''} ${roleLabel(p.role)}`.toLowerCase().includes(needle))
})
function assign(id: number, role: string, name: string) {
    void run(() => rolesApi.assign(id, role), `${name}: роль «${roleLabel(role)}».`)
}

const groups = computed(() => {
    const out = new Map<string, RolePermissionRow[]>()
    for (const p of data.value?.permissions ?? []) out.set(p.group, [...(out.get(p.group) ?? []), p])
    return [...out.entries()]
})
function toggle(p: RolePermissionRow, c: RoleCell) {
    if (!c.editable) return
    void run(() => rolesApi.permission(c.role, p.value, !c.allowed), `${roleLabel(c.role)} · ${p.label}: ${c.allowed ? 'заборонено' : 'дозволено'}.`)
}
const cellTitle = (p: RolePermissionRow, c: RoleCell) =>
    c.locked
        ? (c.lockReason ?? 'Зафіксовано')
        : !c.editable
          ? p.superadminOnly
              ? 'Змінює лише суперадміністратор'
              : 'Права на задачі роздає той, хто їх бачить'
          : (c.allowed ? 'Дозволено' : 'Заборонено') + (c.changed ? ' (змінено, за замовчуванням — ' + (c.default ? 'так' : 'ні') + ')' : '')

const supers = computed(() => (data.value?.people ?? []).filter((p) => p.superadmin))
const candidates = computed(() => {
    const needle = superQ.value.trim().toLowerCase()
    return needle ? (data.value?.people ?? []).filter((p) => !p.superadmin && p.name.toLowerCase().includes(needle)).slice(0, 8) : []
})
const superQ = ref('')
const confirmRevoke = ref<number | null>(null)
function revoke(id: number, name: string) {
    if (confirmRevoke.value !== id) {
        confirmRevoke.value = id
        window.setTimeout(() => confirmRevoke.value === id && (confirmRevoke.value = null), 4000)
        return
    }
    confirmRevoke.value = null
    void run(() => rolesApi.superadmin(id, false), `${name} — більше не суперадміністратор.`)
}
function grant(id: number, name: string) {
    superQ.value = ''
    void run(() => rolesApi.superadmin(id, true), `${name} — суперадміністратор.`)
}
const when = (at: string) => new Date(at.replace(' ', 'T')).toLocaleString('uk-UA', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
</script>

<template>
    <div class="r-page">
        <p v-if="!allowed" class="r-empty">Сторінка доступна адміністраторам.</p>
        <template v-else>
            <header class="r-head">
                <h1>Ролі й доступ</h1>
                <nav class="r-tabs" role="tablist">
                    <button v-for="t in TABS" :key="t.key" type="button" role="tab" :class="{ on: tab === t.key }" :data-testid="'roles-tab-' + t.key" @click="go(t.key)">
                        {{ t.label }}
                    </button>
                </nav>
            </header>
            <p v-if="error" class="t-error r-error" role="alert">{{ error }}</p>
            <p v-if="!data && !error" class="r-empty">Завантаження…</p>

            <section v-if="data && tab === 'people'" class="r-card" aria-label="Люди й ролі">
                <input v-model="q" class="t-input r-search" type="search" placeholder="Пошук: імʼя, посада, відділ, роль" aria-label="Пошук людей" />
                <table class="r-table">
                    <thead>
                        <tr>
                            <th>Людина</th>
                            <th class="r-hide-sm">Відділ</th>
                            <th>Роль</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in people" :key="p.id" :data-person="p.id">
                            <td>
                                <b>{{ p.name }}</b>
                                <small v-if="p.superadmin" class="r-badge">суперадмін</small>
                                <small v-if="p.position" class="r-muted">{{ p.position }}</small>
                            </td>
                            <td class="r-hide-sm r-muted">{{ p.department ?? '—' }}</td>
                            <td>
                                <select
                                    v-if="p.assignable.length"
                                    class="t-input r-select"
                                    :value="p.role"
                                    :disabled="busy"
                                    :aria-label="'Роль: ' + p.name"
                                    @change="assign(p.id, ($event.target as HTMLSelectElement).value, p.name)"
                                >
                                    <option v-if="!p.assignable.includes(p.role)" :value="p.role" disabled>{{ roleLabel(p.role) }}</option>
                                    <option v-for="r in p.assignable" :key="r" :value="r">{{ roleLabel(r) }}</option>
                                </select>
                                <span
                                    v-else
                                    class="r-fixed"
                                    :title="p.id === data.me.id ? 'Свою роль змінює лише суперадміністратор' : 'Роль цієї людини змінює суперадміністратор'"
                                >
                                    {{ roleLabel(p.role) }}
                                    <Icon name="shield" :size="13" />
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="data && tab === 'matrix'" class="r-card" aria-label="Права ролей">
                <p class="r-note">
                    Натисніть клітинку, щоб дозволити чи заборонити. Діє одразу для всіх людей з роллю. Крапка — змінено проти стандартного;
                    <Icon name="shield" :size="12" />
                    — зафіксовано.
                </p>
                <div class="r-scroll">
                    <table class="r-matrix">
                        <thead>
                            <tr>
                                <th class="r-perm">Право</th>
                                <th v-for="r in data.roles" :key="r.value" scope="col">{{ r.label }}</th>
                            </tr>
                        </thead>
                        <tbody v-for="[group, rows] in groups" :key="group">
                            <tr class="r-group">
                                <th :colspan="data.roles.length + 1">{{ group }}</th>
                            </tr>
                            <tr v-for="p in rows" :key="p.value">
                                <th class="r-perm" scope="row">
                                    {{ p.label }}
                                    <small v-if="p.superadminOnly" class="r-muted">змінює суперадмін</small>
                                </th>
                                <td v-for="c in p.cells" :key="c.role">
                                    <button
                                        type="button"
                                        class="r-cell"
                                        :class="{ yes: c.allowed, locked: c.locked, ro: !c.editable, changed: c.changed }"
                                        :disabled="!c.editable || busy"
                                        :title="cellTitle(p, c)"
                                        :aria-label="roleLabel(c.role) + ' · ' + p.label + ': ' + (c.allowed ? 'так' : 'ні')"
                                        :aria-pressed="c.allowed"
                                        :data-cell="c.role + ':' + p.value"
                                        @click="toggle(p, c)"
                                    >
                                        <Icon v-if="c.locked" name="shield" :size="13" />
                                        <Icon v-else-if="c.allowed" name="check" :size="15" />
                                        <span v-else>—</span>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <h2 class="r-sub">Не налаштовується</h2>
                <ul class="r-fixed-list">
                    <li v-for="f in data.fixed" :key="f.label">
                        <b>{{ f.label }}</b>
                        — {{ f.roles.map(roleLabel).join(', ') }}{{ f.roles.length && f.note ? ', ' : '' }}{{ f.note }}
                    </li>
                </ul>
            </section>

            <section v-if="data && tab === 'super'" class="r-card" aria-label="Суперадміністратори">
                <p class="r-note">
                    Суперадміністратор бачить усе й журнал аудиту. Призначає й знімає лише суперадміністратор; останнього зняти не можна, себе — теж (це робить інший
                    суперадміністратор).
                </p>
                <ul class="r-supers">
                    <li v-for="p in supers" :key="p.id">
                        <b>{{ p.name }}</b>
                        <small class="r-muted">{{ roleLabel(p.role) }}</small>
                        <button
                            v-if="data.me.superadmin && p.id !== data.me.id && supers.length > 1"
                            type="button"
                            class="t-btn"
                            :class="{ danger: confirmRevoke === p.id }"
                            :disabled="busy"
                            @click="revoke(p.id, p.name)"
                        >
                            {{ confirmRevoke === p.id ? 'Точно зняти?' : 'Зняти' }}
                        </button>
                    </li>
                </ul>
                <template v-if="data.me.superadmin">
                    <input v-model="superQ" class="t-input r-search" placeholder="Додати суперадміністратора: імʼя…" aria-label="Пошук людини" />
                    <div class="r-candidates">
                        <button v-for="p in candidates" :key="p.id" type="button" class="t-btn" :disabled="busy" @click="grant(p.id, p.name)">+ {{ p.name }}</button>
                    </div>
                </template>
                <p v-else class="r-muted">Змінити список може лише суперадміністратор.</p>
            </section>

            <section v-if="data && tab === 'history'" class="r-card" aria-label="Історія змін">
                <ol class="r-history">
                    <li v-for="h in data.history" :key="h.id">
                        <time>{{ when(h.at) }}</time>
                        <span>{{ h.text }}</span>
                        <small class="r-muted">— {{ h.by }}</small>
                    </li>
                    <li v-if="!data.history.length" class="r-muted">Змін ще не було.</li>
                </ol>
            </section>
        </template>
    </div>
</template>
