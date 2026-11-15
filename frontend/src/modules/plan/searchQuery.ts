import type { LocationQuery } from 'vue-router'
import type { SearchGroup } from './api'

/**
 * Стан пошуку живе в адресі (`/crm/tasks/search?q=цемент&type=supply&author=5`):
 * посиланням можна поділитися, «Назад» повертає попередні фільтри, збережений
 * пошук — це просто рядок запиту. Тут — розбір і збирання цього рядка.
 */
export type PersonRole = 'person' | 'author' | 'executor' | 'manager'

export interface SearchState {
    q: string
    types: SearchGroup[]
    person: { role: PersonRole; id: number } | null
    departments: number[]
    statuses: string[]
    client: string
    from: string
    to: string
    starred: boolean
    /** Типово «Новіші»; «За збігом» — лише коли людина обрала (UX: прецеденти шукають за датою). */
    sort: 'relevance' | 'recent'
    /** Вигляд результатів: auto — таблиця для «прецедентів» (фільтр без слова), інакше список. */
    view: 'auto' | 'list' | 'table'
}

export const ROLES: { key: PersonRole; label: string }[] = [
    { key: 'person', label: 'будь-яка роль' },
    { key: 'author', label: 'автор' },
    { key: 'executor', label: 'виконавець' },
    { key: 'manager', label: 'менеджер' },
]

/** Ключі адреси, що належать пошуку (решта — відкрита праворуч задача, панель тощо). */
export const SEARCH_KEYS = ['q', 'type', 'person', 'author', 'executor', 'manager', 'department', 'status', 'client', 'from', 'to', 'starred', 'sort', 'view']

const GROUPS: SearchGroup[] = ['task', 'supply', 'warehouse', 'comment', 'file', 'person']

function one(v: LocationQuery[string]): string {
    return typeof v === 'string' ? v : Array.isArray(v) && typeof v[0] === 'string' ? v[0] : ''
}

function list(v: LocationQuery[string]): string[] {
    return one(v)
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean)
}

const DATE = /^\d{4}-\d\d-\d\d$/

export function parse(query: LocationQuery): SearchState {
    let person: SearchState['person'] = null
    for (const role of ['person', 'author', 'executor', 'manager'] as PersonRole[]) {
        const id = Number(one(query[role]))
        if (Number.isInteger(id) && id > 0) {
            person = { role, id }
            break
        }
    }

    return {
        q: one(query.q),
        types: list(query.type).filter((t): t is SearchGroup => (GROUPS as string[]).includes(t)),
        person,
        departments: list(query.department)
            .map(Number)
            .filter((n) => Number.isInteger(n) && n > 0),
        statuses: list(query.status).filter((s) => /^[a-z_]+:[a-z_]+$/.test(s)),
        client: one(query.client),
        from: DATE.test(one(query.from)) ? one(query.from) : '',
        to: DATE.test(one(query.to)) ? one(query.to) : '',
        starred: one(query.starred) === '1',
        sort: one(query.sort) === 'relevance' ? 'relevance' : 'recent',
        view: one(query.view) === 'list' || one(query.view) === 'table' ? (one(query.view) as 'list' | 'table') : 'auto',
    }
}

/** Стан → параметри адреси (лише непорожні, у сталому порядку). */
export function toQuery(s: SearchState): Record<string, string> {
    const out: Record<string, string> = {}
    if (s.q.trim()) out.q = s.q.trim()
    if (s.types.length) out.type = s.types.join(',')
    if (s.person) out[s.person.role] = String(s.person.id)
    if (s.departments.length) out.department = s.departments.join(',')
    if (s.statuses.length) out.status = s.statuses.join(',')
    if (s.client.trim()) out.client = s.client.trim()
    if (s.from) out.from = s.from
    if (s.to) out.to = s.to
    if (s.starred) out.starred = '1'
    if (s.sort === 'relevance') out.sort = 'relevance'
    if (s.view !== 'auto') out.view = s.view
    return out
}

export function toString(s: SearchState): string {
    return new URLSearchParams(toQuery(s)).toString()
}

export function hasFilters(s: SearchState): boolean {
    return !!(s.types.length || s.person || s.departments.length || s.statuses.length || s.client.trim() || s.from || s.to || s.starred)
}

/** Таблиця — для «усіх прецедентів»: є фільтр (людина, відділ, контрагент…), але немає слова. */
export function tableView(s: SearchState): boolean {
    return s.view === 'table' || (s.view === 'auto' && hasFilters(s) && s.q.trim().length < 2)
}

export function isEmpty(s: SearchState): boolean {
    return s.q.trim().length < 2 && !hasFilters(s)
}

/** Дата в YYYY-MM-DD за місцевим часом — для полів «з / по» і швидких періодів. */
export function isoDay(d: Date): string {
    const p = (n: number) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

const RECENT_KEY = 'plan.search.recent'

/** Недавні пошуки — зручність одного браузера (localStorage), не спільні дані. */
export function recent(): string[] {
    try {
        const raw = JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]')
        return Array.isArray(raw) ? raw.filter((x): x is string => typeof x === 'string').slice(0, 8) : []
    } catch {
        return []
    }
}

export function remember(query: string): void {
    if (!query) return
    try {
        localStorage.setItem(RECENT_KEY, JSON.stringify([query, ...recent().filter((x) => x !== query)].slice(0, 8)))
    } catch {
        /* приватне вікно — без недавніх */
    }
}

export function forget(query: string): void {
    try {
        localStorage.setItem(RECENT_KEY, JSON.stringify(recent().filter((x) => x !== query)))
    } catch {
        /* нічого */
    }
}
