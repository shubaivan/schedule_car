import { call, SessionExpired } from '../../app/api'
import type { Person } from '../tasks/api'
import type { FeedApi, FeedSubject } from '../tasks/feedApi'

/** Склад у «Плані» — JSON API /api/warehouse (бекенд src/Warehouse/Controller/Api). */

export interface WhSite {
    id: number
    kind: 'warehouse' | 'site'
    name: string
    label: string
    address: string | null
    client: { id: number; name: string } | null
    active: boolean
}

export interface WhCategory {
    id: number
    name: string
    emoji: string | null
    prefix: string | null
    attributes: string[]
    active: boolean
    next?: string
}

export interface WhMovementType {
    value: string
    label: string
    emoji: string
    needsFrom: boolean
    needsTo: boolean
    fromKind: 'warehouse' | 'site' | null
    toKind: 'warehouse' | 'site' | null
}

export interface WhMeta {
    categories: WhCategory[]
    sites: WhSite[]
    clients: { id: number; name: string; edrpou: string | null; phone: string | null; active: boolean }[]
    movementTypes: WhMovementType[]
    states: { value: string; label: string }[]
    trackings: { value: string; label: string }[]
    documentTypes: { value: string; label: string }[]
    units: string[]
    actions: { value: string; label: string; emoji: string }[]
    subjectTypes: Record<string, string>
    can: { edit: boolean }
    today: string
}

export interface WhItemRow {
    id: number
    inventoryNumber: string
    name: string
    label: string
    category: { id: number; name: string; emoji: string | null }
    tracking: 'unit' | 'bulk'
    trackingLabel: string
    unit: string
    state: string
    stateLabel: string
    total: number
    places: { site: string; quantity: number }[]
    currentSite: WhSite | null
    currentSince: string | null
    cover: string | null
    updatedAt: string
}

export interface WhDocument {
    id: number
    name: string
    type: string
    typeLabel: string
    mime: string
    size: number
    sizeLabel: string
    photo: boolean
    url: string
    download: string
    driveUrl: string | null
    uploadedBy: string | null
}

export interface WhMovementRow {
    id: number
    type: string
    typeLabel: string
    emoji: string
    title: string
    occurredAt: string
    documentNumber: string | null
    from: WhSite | null
    to: WhSite | null
    route: string
    client: string | null
    supplier: string | null
    lines: number
    units: number
    quantity: number | null
    unit: string | null
    createdBy: Person | null
}

export interface WhActivity {
    id: number
    action: string
    actionLabel: string
    emoji: string
    subjectType: string
    subjectId: number
    subjectLabel: string
    user: Person | null
    channel: string
    channelLabel: string
    details: string | null
    at: string
}

export interface WhMovement extends WhMovementRow {
    note: string | null
    items: { id: number; item: { id: number; inventoryNumber: string; name: string; unit: string }; quantity: number; rentalRate: string | null }[]
    documents: WhDocument[]
    journal: WhActivity[]
    twig: string
}

export interface WhItem {
    id: number
    inventoryNumber: string
    name: string
    label: string
    category: WhCategory
    tracking: 'unit' | 'bulk'
    trackingLabel: string
    unit: string
    state: string
    stateLabel: string
    description: string | null
    attributes: Record<string, string>
    serialNumber: string | null
    supplier: { id: number; name: string } | null
    purchasePrice: string | null
    purchasedAt: string | null
    rentalRate: string | null
    total: number
    places: { site: WhSite; quantity: number; since: string | null }[]
    documents: WhDocument[]
    history: WhMovementRow[]
    createdBy: Person | null
    createdAt: string
    qr: string
    labels: string
    twig: string
    shareUrl: string | null
    mentions?: {
        tasks: { id: number; title: string; status: string; closed: boolean }[]
        supply: { id: number; number: string; title: string; status: string }[]
    }
}

export interface ItemPayload {
    name: string
    categoryId: number | null
    tracking: string
    unit: string
    inventoryNumber: string
    description: string
    serialNumber: string
    supplierId: string
    purchasePrice: string
    purchasedAt: string
    rentalRate: string
    state: string
    attributes: { name: string; value: string }[]
}

export interface MovementPayload {
    type: string
    occurredAt: string
    fromId: string
    toId: string
    toClientId: string
    supplierId: string
    documentNumber: string
    documentType: string
    note: string
    lines: { itemId: number; quantity: string; rate: string }[]
}

/**
 * Кожен запис у склад несе X-Requested-With: HTML-форма з чужого сайту поставити його
 * не може, і сервер (WarehouseGate) без нього запис не приймає — замість CSRF-токена.
 */
const WRITE = { 'X-Requested-With': 'XMLHttpRequest' }
const JSON_WRITE = { 'Content-Type': 'application/json', Accept: 'application/json', ...WRITE }

async function upload<T>(url: string, form: FormData): Promise<T> {
    const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', ...WRITE }, body: form })
    if (response.status === 401) throw new SessionExpired()
    const payload = await response.json().catch(() => null)
    if (!response.ok) throw new Error(payload?.error ?? (response.status === 413 ? 'Файли завеликі для одного надсилання.' : `Помилка ${response.status}`))
    return payload as T
}

const qs = (params: Record<string, string | number | boolean | undefined | null>) => {
    const search = new URLSearchParams()
    for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '' && v !== false) search.set(k, String(v))
    const s = search.toString()
    return s ? '?' + s : ''
}

let metaCache: Promise<WhMeta> | null = null

export const whApi = {
    /** Довідники кешуються на сесію сторінки; після нової позиції чи нового об'єкта — meta(true). */
    meta: (fresh = false) => {
        if (fresh || !metaCache) metaCache = call<WhMeta>('/api/warehouse/meta').catch((e) => {
            metaCache = null
            throw e
        })
        return metaCache
    },
    items: (params: { q?: string; category?: string; place?: string; state?: string; tracking?: string; off?: boolean }) =>
        call<{ items: WhItemRow[]; total: number; counts: { all: number; unit: number; bulk: number; repair: number } }>('/api/warehouse/items' + qs(params)),
    item: (id: number) => call<WhItem>(`/api/warehouse/items/${id}`),
    viewed: (id: number) => call<{ logged: boolean }>(`/api/warehouse/items/${id}/viewed`, { method: 'POST', headers: JSON_WRITE }),
    feed: (id: number) => call<FeedSubject>(`/api/warehouse/items/${id}/feed`),
    createItem: (p: ItemPayload) => call<WhItem>('/api/warehouse/items', { method: 'POST', headers: JSON_WRITE, body: JSON.stringify(p) }),
    updateItem: (id: number, p: ItemPayload) => call<WhItem>(`/api/warehouse/items/${id}`, { method: 'PUT', headers: JSON_WRITE, body: JSON.stringify(p) }),
    uploadDocuments: (id: number, files: File[], type: string) => {
        const form = new FormData()
        files.forEach((f) => form.append('files[]', f, f.name))
        form.append('type', type)
        return upload<WhItem>(`/api/warehouse/items/${id}/documents`, form)
    },
    removeDocument: (id: number) => call<{ removed: boolean }>(`/api/warehouse/documents/${id}`, { method: 'DELETE', headers: JSON_WRITE, keepalive: true }),
    movements: (params: { type?: string; site?: string; item?: string; q?: string; from?: string; to?: string }) =>
        call<{ items: WhMovementRow[]; total: number; limit: number }>('/api/warehouse/movements' + qs(params)),
    movement: (id: number) => call<WhMovement>(`/api/warehouse/movements/${id}`),
    movementViewed: (id: number) => call<{ logged: boolean }>(`/api/warehouse/movements/${id}/viewed`, { method: 'POST', headers: JSON_WRITE }),
    createMovement: (p: MovementPayload, files: File[]) => {
        const form = new FormData()
        form.append('data', JSON.stringify(p))
        files.forEach((f) => form.append('files[]', f, f.name))
        return upload<WhMovement>('/api/warehouse/movements', form)
    },
    journal: (params: { user?: string; action?: string; type?: string; from?: string; to?: string }) =>
        call<{ items: WhActivity[]; people: Person[] }>('/api/warehouse/journal' + qs(params)),
}

/**
 * Стрічка позиції для TaskChat: лише події (рухи, зміни, документи, скани),
 * без поля вводу. Опитування рідке — склад не чат.
 */
export function itemFeed(id: number): FeedApi {
    return {
        poll: async () => {
            const f = await whApi.feed(id)
            return { messages: f.messages, changed: [], attachments: [], status: f.status, reads: [], now: f.now ?? new Date().toISOString(), full: true, files: [] }
        },
        post: async () => [],
        features: { reply: false, pin: false, star: false, mentions: false },
        intervalMs: 20000,
        // Рух чи правка картки — події wh_*; позиції в них не видно напряму, тож будь-яка складська.
        live: (e) => e.subject.type.startsWith('wh_'),
        fullFeed: true,
    }
}

/** «12 шт» / «1 450 кг» — кількість з одиницею. */
export function qty(n: number, unit: string): string {
    return `${n.toLocaleString('uk-UA')} ${unit}`
}

export const STATE_TONE: Record<string, string> = { active: 'ok', repair: 'warn', written_off: 'off' }
