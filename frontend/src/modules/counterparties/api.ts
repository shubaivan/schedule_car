import { call, SessionExpired } from '../../app/api'

export interface CpOption {
    value: string
    label: string
}

export interface CpPerson {
    id: number
    name: string
}

export interface CpMeta {
    kinds: CpOption[]
    legalForms: (CpOption & { codeLabel: string })[]
    vatStatuses: CpOption[]
    paymentTerms: CpOption[]
    managers: CpPerson[]
    can: { edit: boolean; finance: boolean }
    me: number
}

export interface CpContact {
    id: number | null
    name: string
    role: string | null
    phone: string | null
    phoneRaw: string | null
    tel: string | null
    email: string | null
    primary: boolean
}

export interface CpRow {
    id: number
    name: string
    shortName: string | null
    displayName: string
    kind: string
    kindLabel: string
    legalForm: string
    legalFormLabel: string
    code: string | null
    codeLabel: string
    vatStatus: string | null
    vatLabel: string | null
    paymentTerms: string | null
    termsLabel: string | null
    creditLimit: string | null
    manager: CpPerson | null
    phone: string | null
    contact: CpContact | null
    contactsCount: number
    archived: boolean
}

export interface CpCard extends CpRow {
    vatNumber: string | null
    legalAddress: string | null
    actualAddress: string | null
    phoneRaw: string | null
    tel: string | null
    email: string | null
    iban: string | null
    bankName: string | null
    deferralDays: number | null
    note: string | null
    externalCode: string | null
    contacts: CpContact[]
    createdAt: string
    createdBy: CpPerson | null
    archivedAt: string | null
    archivedBy: CpPerson | null
    tasks: { id: number; number: string; title: string; statusLabel: string; closed: boolean; linked: boolean }[]
    links: { suppliers: { id: number; name: string }[]; warehouseClients: { id: number; name: string }[] }
    history: { at: string; who: string | null; text: string }[]
}

/** Те, що надсилає форма. Порожній рядок — «очистити поле». */
export interface CpPayload {
    kind: string
    legalForm: string
    name: string
    shortName: string
    code: string
    vatStatus: string
    vatNumber: string
    legalAddress: string
    actualAddress: string
    phone: string
    email: string
    iban: string
    bankName: string
    paymentTerms?: string
    deferralDays?: string
    creditLimit?: string
    managerId: number | null
    note: string
    externalCode: string
    contacts: { id: number | null; name: string; role: string; phone: string; email: string; primary: boolean }[]
}

export interface CpCounts {
    all: number
    client: number
    supplier: number
    archived: number
}

/** Помилка форми: яке поле і, для дубля, яка картка вже є. */
export class CpError extends Error {
    constructor(
        message: string,
        public field: string | null,
        public existingId: number | null,
        public status: number,
    ) {
        super(message)
    }
}

/**
 * Кожен запис несе X-Requested-With: HTML-форма з чужого сайту поставити його
 * не може, і сервер без нього запис не приймає (як склад).
 */
async function write(url: string, method: string, body?: unknown): Promise<CpCard> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: body === undefined ? undefined : JSON.stringify(body),
    })
    const payload = await response.json().catch(() => null)
    if (!response.ok) {
        if (response.status === 401) throw new SessionExpired()
        throw new CpError(payload?.error ?? `Помилка ${response.status}`, payload?.field ?? null, payload?.existingId ?? null, response.status)
    }
    return payload as CpCard
}

const qs = (params: Record<string, string | undefined>) => {
    const search = new URLSearchParams()
    for (const [k, v] of Object.entries(params)) if (v) search.set(k, v)
    const s = search.toString()
    return s ? '?' + s : ''
}

const BASE = '/api/plan/counterparties'
let metaCache: Promise<CpMeta> | null = null

export const cpApi = {
    meta: (fresh = false) => {
        if (fresh || !metaCache)
            metaCache = call<CpMeta>(BASE + '/meta').catch((e) => {
                metaCache = null
                throw e
            })
        return metaCache
    },
    list: (params: Record<string, string | undefined>) => call<{ items: CpRow[]; counts: CpCounts; limit: number }>(BASE + qs(params)),
    get: (id: number) => call<CpCard>(`${BASE}/${id}`),
    create: (payload: CpPayload) => write(BASE, 'POST', payload),
    update: (id: number, payload: Partial<CpPayload>) => write(`${BASE}/${id}`, 'PATCH', payload),
    archive: (id: number) => write(`${BASE}/${id}/archive`, 'POST'),
    restore: (id: number) => write(`${BASE}/${id}/restore`, 'POST'),
}

/** «150000.00» → «150 000 ₴». */
export function hryvnia(value: string | null): string {
    if (value === null || value === '') return '—'
    const n = Number(value)
    return Number.isFinite(n) ? n.toLocaleString('uk-UA', { maximumFractionDigits: 2 }) + ' ₴' : value
}
