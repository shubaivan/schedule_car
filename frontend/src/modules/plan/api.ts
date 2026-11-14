import { call } from '../../app/api'
import type { Hit, Person } from '../tasks/api'

/** Типи «Плану», спільні для пошуку й Хроніки. */
export type SubjectType = 'task' | 'supply' | 'wh_item' | 'wh_movement' | 'wh_client' | 'wh_site' | 'wh_category' | 'lead' | 'user'
export interface SubjectRef {
    type: SubjectType
    id: number
}

export type SearchGroup = 'task' | 'supply' | 'warehouse' | 'comment' | 'file' | 'person'

export interface SearchHit {
    id: number
    docType: string
    group: SearchGroup
    docLabel: string
    subject: SubjectRef
    key: string
    refId: number
    title: Hit[]
    snippet: Hit[]
    status: string | null
    statusLabel: string | null
    closed: boolean
    client: string | null
    author: Person | null
    departments: string[]
    thumb: string | null
    url: string
    at: string
    createdAt: string
}

export interface FacetCount {
    key: string
    label: string
    count: number
}
export interface StatusFacet extends FacetCount {
    kind: string
    kindLabel: string
}
export interface PersonFacet {
    id: number
    name: string
    count: number
    person: Person
}

export interface SearchResponse {
    q: string
    terms: string[]
    total: number
    /** лічильники фасетів пораховано по перших 2000 збігах */
    approximate: boolean
    /** загальна кількість точна (інакше «2000+») */
    totalExact: boolean
    offset: number
    more: boolean
    hits: SearchHit[]
    facets: {
        type: FacetCount[]
        status: StatusFacet[]
        author: PersonFacet[]
        executor: PersonFacet[]
        department: { id: number; name: string; count: number }[]
        client: { name: string; count: number }[]
    }
    people: Person[]
    tookMs: number
}

export interface SavedSearch {
    id: number
    name: string
    /** рядок запиту адреси: q=…&type=supply */
    query: string
}

export interface SearchMeta {
    types: { key: SearchGroup; label: string }[]
    statuses: { key: string; label: string; kind: string; kindLabel: string }[]
    people: Person[]
    departments: { id: number; name: string }[]
    saved: SavedSearch[]
}

export type ChronicleModule = 'task' | 'supply' | 'warehouse'
export type ChronicleEventFilter = 'comment' | 'status' | 'file' | 'created' | 'stock'

export interface ChronicleRow {
    key: string
    subject: SubjectRef
    module: ChronicleModule
    moduleLabel: string
    number: string | null
    title: string
    status: string | null
    statusLabel: string | null
    closed: boolean
    client: string | null
    owner: Person | null
    url: string
    event: { id: number; type: string; group: string; author: Person | null; text: string; at: string }
    unread: number
    favorite: boolean
    lastActivityAt: string
}

export interface PlanCounts {
    /** скільки суб'єктів (задач, заявок, складу) з новим — бейдж меню */
    tasks: number
    events: number
    byModule?: Partial<Record<ChronicleModule, number>>
}

export interface ChroniclePage {
    items: ChronicleRow[]
    next: string | null
    counts: PlanCounts
    modules: { key: ChronicleModule; label: string }[]
}

export interface ChronicleQuery {
    modules?: ChronicleModule[]
    events?: ChronicleEventFilter[]
    unread?: boolean
    favorites?: boolean
    cursor?: string | null
}

export const planApi = {
    search: (query: string, signal?: AbortSignal) => call<SearchResponse>('/api/plan/search?' + query, { signal }),
    searchMeta: () => call<SearchMeta>('/api/plan/search/meta'),
    saveSearch: (name: string, query: string) =>
        call<SavedSearch>('/api/plan/search/saved', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name, query }) }),
    deleteSaved: (id: number) => call<SavedSearch[]>(`/api/plan/search/saved/${id}`, { method: 'DELETE' }),

    chronicle: (q: ChronicleQuery) => {
        const params = new URLSearchParams()
        if (q.modules?.length) params.set('module', q.modules.join(','))
        if (q.events?.length) params.set('event', q.events.join(','))
        if (q.unread) params.set('unread', '1')
        if (q.favorites) params.set('favorites', '1')
        if (q.cursor) params.set('cursor', q.cursor)
        return call<ChroniclePage>('/api/plan/chronicle?' + params)
    },
    counts: () => call<PlanCounts>('/api/plan/chronicle/counts'),
    markRead: (key: string) => call<PlanCounts>(`/api/plan/chronicle/${key}/read`, { method: 'POST' }),
    readAll: () => call<PlanCounts>('/api/plan/chronicle/read-all', { method: 'POST' }),
}
