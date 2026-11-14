import { call } from '../../app/api'

/** Журнал аудиту «Плану» (/api/plan/audit*) — лише суперадмін, інакше 403. */

export interface AuditPerson {
    id: number
    name: string
}

export interface AuditSubject {
    type: string
    id: number
    /** `task-12` — той самий ключ, що у фільтрі subject. */
    key: string
    kindLabel: string
    title: string | null
    /** Шлях на сайті (`/crm/tasks/board/12`, `/sklad/items/5`); null — суб'єкта не відкрити. */
    url: string | null
}

export interface AuditItem {
    id: number
    type: string
    typeLabel: string
    group: string | null
    /** Київський час, `2026-11-14 18:03:11`. */
    at: string
    actor: AuditPerson | null
    subject: AuditSubject
    summary: string
    /** Коментар до зміни статусу (живий журнал пише його в ту саму подію). */
    comment: string | null
    channel: string
    ip: string | null
    userAgent: string | null
    /** Подію перенесено з історії (plan:events:backfill), а не записано наживо. */
    backfill: boolean
}

export interface AuditPage {
    items: AuditItem[]
    older: number | null
    newer: number | null
    total: number
    totalCapped: boolean
}

export interface AuditDetail extends AuditItem {
    payload: Record<string, unknown>
    people: AuditPerson[]
    around: AuditItem[]
}

export interface AuditMeta {
    groups: { value: string; label: string; types: { value: string; label: string }[] }[]
    subjects: { value: string; label: string }[]
    actors: AuditPerson[]
    csvMax: number
    retention: { domainMonths: number; accessMonths: number }
}

/** Фільтри — ті самі ключі, що в адресі сторінки й у API. */
export const FILTER_KEYS = ['q', 'actor', 'group', 'kind', 'subject', 'from', 'to', 'ip'] as const
export type AuditFilters = Partial<Record<(typeof FILTER_KEYS)[number], string>>

export function filterQuery(filters: AuditFilters, extra: Record<string, string | number | null | undefined> = {}): string {
    const query = new URLSearchParams()
    for (const key of FILTER_KEYS) {
        const value = filters[key]
        if (value) query.set(key, value)
    }
    for (const [key, value] of Object.entries(extra)) {
        if (value !== null && value !== undefined && value !== '') query.set(key, String(value))
    }
    return query.toString()
}

export const auditApi = {
    meta: () => call<AuditMeta>('/api/plan/audit/meta'),
    page: (filters: AuditFilters, cursor: { before?: string; after?: string }) =>
        call<AuditPage>('/api/plan/audit?' + filterQuery(filters, cursor)),
    event: (id: number) => call<AuditDetail>(`/api/plan/audit/${id}`),
    csvUrl: (filters: AuditFilters) => '/api/plan/audit.csv?' + filterQuery(filters),
}
