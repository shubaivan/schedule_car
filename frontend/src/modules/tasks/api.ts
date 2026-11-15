import type { SupplyRequest } from '../../app/api'
import { call, SessionExpired } from '../../app/api'

export interface Person {
    id: number
    name: string
    initials: string
    color: string
    position: string | null
    /** «093 350 44 42» — показуємо поруч з іменем (PersonName); null — номера немає. */
    phone?: string | null
    /** «+380933504442» для посилання tel:. */
    tel?: string | null
    role: string
    roleLabel: string
    department: string | null
    departmentId: number | null
    archived: boolean
    via?: string | null
}

export interface Label {
    id: number
    name: string
    color: string
}

export interface StatusInfo {
    value: string
    label: string
    action: string
    column: string
    closed: boolean
    decision: boolean
}

export interface Attachment {
    id: number
    messageId: number
    name: string
    ext: string
    mime: string
    size: number
    sizeLabel: string
    kind: 'image' | 'pdf' | 'office' | 'other'
    preview: 'none' | 'pending' | 'ready' | 'failed'
    pages: number | null
    width: number | null
    height: number | null
    uploadedBy: string | null
    createdAt: string
    urls: { thumb: string | null; view: string | null; page: string | null; inline: string; download: string }
}

export type EventType = 'comment' | 'file' | 'created' | 'status' | 'closed' | 'members' | 'updated'

export interface Message {
    id: number
    kind: 'comment' | 'event'
    eventType: EventType
    author: Person | null
    text: string
    mentions: number[]
    createdAt: string
    deleted: boolean
    mine: boolean
    attachments: Attachment[]
    replyTo: { id: number; author: string | null; authorColor: string | null; text: string; files: number; deleted: boolean } | null
    editedAt: string | null
    pinned: { at: string; by: string | null } | null
    starred: boolean
    can: { edit: boolean; delete: boolean }
}

/** Хто до якого повідомлення дочитав задачу. */
export interface ReadMark {
    userId: number
    lastMessageId: number
}

export interface TaskItem {
    id: number
    number: string
    title: string
    client: string | null
    status: string
    statusLabel: string
    column: string
    closed: boolean
    labels: Label[]
    dueAt: string | null
    overdue: boolean
    creator: Person
    members: Person[]
    membersCount: number
    /** Відділи-виконавці для картки: «3. Відділ продажу». */
    departmentNames: string[]
    fileStats: { count: number; pdf: number; images: number }
    unread: number
    lastActivityAt: string
    createdAt: string
    last: { author: string | null; text: string; files: number; at: string } | null
}

export interface TaskDetail extends TaskItem {
    description: string | null
    departments: { id: number; name: string }[]
    closedAt: string | null
    can: { edit: boolean; comment: boolean; transitions: StatusInfo[] }
    awaitsDecision: boolean
    messages: Message[]
    files: Attachment[]
    favorite: boolean
    reads: ReadMark[]
    /** До якого повідомлення людина дочитала до цього відкриття (для «Нові повідомлення»). */
    readUpTo: number | null
    /** Мітка сервера для першого опитування змін. */
    now?: string
}

export interface ChronicleItem {
    task: { id: number; number: string; title: string; client: string | null; status: string; statusLabel: string; closed: boolean; creator: Person | null }
    event: { id: number; type: EventType; author: Person | null; text: string; files: { name: string; kind: string }[]; filesCount: number; at: string } | null
    unread: number
    favorite: boolean
    lastActivityAt: string
}

export interface ChronicleCounts {
    tasks: number
    events: number
}

export interface Chronicle {
    items: ChronicleItem[]
    next: string | null
    counts: ChronicleCounts
}

/** Шматок тексту з пошуку: m — збіг (малюється як <mark>). */
export interface Hit {
    t: string
    m: boolean
}

export type SearchTab = 'all' | 'tasks' | 'comments' | 'files' | 'people'

export interface SearchResult {
    q: string
    counts: Record<Exclude<SearchTab, 'all'>, number>
    tasks?: {
        id: number
        number: string
        title: Hit[]
        client: string | null
        snippet: Hit[]
        status: string
        statusLabel: string
        closed: boolean
        favorite: boolean
        at: string
    }[]
    comments?: { id: number; taskId: number; taskNumber: string; taskTitle: string; closed: boolean; author: Person | null; snippet: Hit[]; starred: boolean; at: string }[]
    files?: {
        id: number
        name: Hit[]
        plainName: string
        kind: string
        sizeLabel: string
        thumb: string | null
        taskId: number
        taskNumber: string
        taskTitle: string
        closed: boolean
        at: string
    }[]
    people?: (Person & { nameMarked: Hit[] })[]
}

export interface PollResult {
    messages: Message[]
    changed: Message[]
    attachments: Attachment[]
    status: string
    reads: ReadMark[]
    now: string
}

export interface TasksMeta {
    me: Person & { passwordLogin?: boolean; can: { staff: boolean; seeAll: boolean; decide: boolean } }
    statuses: StatusInfo[]
    labels: Label[]
    people: Person[]
    departments: { id: number; name: string; people: number[] }[]
    roles: { value: string; label: string }[]
}

export interface Dashboard {
    /** unread — записів Хроніки з новим (те саме число, що біля «Хроніки»), unreadEvents — скільки в них подій. */
    stats: { mine: number; overdue: number; open: number; unread: number; unreadEvents: number; decisions: number; byStatus: Record<string, number> }
    mine: TaskItem[]
    overdue: TaskItem[]
    department: TaskItem[]
    departmentName: string | null
    decisions: TaskItem[]
    recent: TaskItem[]
    supply: {
        counts: { open: number; new: number; overdue: number; decide: number; mine: number }
        /** Заявки, що чекають рішення саме цієї людини (директор, адмін). */
        decisions: SupplyRequest[]
        /** Погоджує оплату заявок (директор, адмін). */
        approve: boolean
        /** Веде постачання або погоджує — бачить усі заявки. */
        manage: boolean
    }
    /** null — склад вимкнено або людині не видно. */
    warehouse: { shipmentsToday: number; receiptsToday: number; movesWeek: number; repair: number; items: number } | null
}

export type Board = Record<'chronicle' | 'messages' | 'new' | 'work' | 'done', TaskItem[]>

export interface StaffRow extends Person {
    firstName: string | null
    lastName: string | null
    phone: string | null
    email: string | null
    hasPassword: boolean
    telegram: boolean
    lastSeenAt: string | null
    archivedAt: string | null
    archivedBy: string | null
    me: boolean
}

export interface StaffDepartment {
    id: number
    name: string
    people: Person[]
}

export interface TaskPayload {
    title?: string
    description?: string
    client?: string
    dueAt?: string
    labelIds?: number[]
    memberIds?: number[]
    departmentIds?: number[]
}

/** Multipart: файли не пролізуть через JSON-обгортку call(). */
async function upload<T>(url: string, form: FormData): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body: form,
    })

    if (response.status === 401) throw new SessionExpired()

    const payload = await response.json().catch(() => null)

    if (!response.ok) {
        throw new Error(payload?.error ?? (response.status === 413 ? 'Файли завеликі для одного повідомлення.' : `Помилка ${response.status}`))
    }

    return payload as T
}

const json = (body: unknown) => JSON.stringify(body)

export const tasksApi = {
    meta: () => call<TasksMeta>('/api/tasks/meta'),
    dashboard: () => call<Dashboard>('/api/tasks/dashboard'),
    board: (q: string, label: number | null) => {
        const params = new URLSearchParams()
        if (q) params.set('q', q)
        if (label) params.set('label', String(label))
        return call<Board>('/api/tasks/board?' + params.toString())
    },
    get: (id: number) => call<TaskDetail>(`/api/tasks/${id}`),
    create: (payload: TaskPayload) => call<TaskDetail>('/api/tasks', { method: 'POST', body: json(payload) }),
    update: (id: number, payload: TaskPayload) => call<TaskDetail>(`/api/tasks/${id}`, { method: 'PATCH', body: json(payload) }),
    status: (id: number, to: string, comment?: string) => call<TaskDetail>(`/api/tasks/${id}/status`, { method: 'POST', body: json({ to, comment }) }),
    poll: (id: number, after: number, pending: number[], changedSince?: string | null) => {
        const params = new URLSearchParams({ after: String(after) })
        pending.forEach((p) => params.append('pending[]', String(p)))
        if (changedSince) params.set('changedSince', changedSince)
        return call<PollResult>(`/api/tasks/${id}/messages?` + params)
    },
    post: (id: number, text: string, files: File[], mentions: number[], replyTo?: number | null) => {
        const form = new FormData()
        form.append('text', text)
        files.forEach((f) => form.append('files[]', f, f.name))
        mentions.forEach((m) => form.append('mentions[]', String(m)))
        if (replyTo) form.append('replyTo', String(replyTo))
        return upload<Message>(`/api/tasks/${id}/messages`, form)
    },
    editMessage: (id: number, messageId: number, text: string) => call<Message>(`/api/tasks/${id}/messages/${messageId}`, { method: 'PATCH', body: json({ text }) }),
    pinMessage: (id: number, messageId: number, on: boolean) => call<Message>(`/api/tasks/${id}/messages/${messageId}/pin`, { method: 'POST', body: json({ on }) }),
    starMessage: (id: number, messageId: number, on: boolean) => call<Message>(`/api/tasks/${id}/messages/${messageId}/star`, { method: 'POST', body: json({ on }) }),
    favorite: (id: number, on: boolean) => call<{ favorite: boolean }>(`/api/tasks/${id}/favorite`, { method: 'POST', body: json({ on }) }),
    markRead: (id: number) => call<ChronicleCounts>(`/api/tasks/${id}/read`, { method: 'POST' }),
    // Хроніка й пошук — v2 (/api/plan/chronicle, /api/plan/search) у modules/plan/api.ts.
    deleteMessage: (id: number, messageId: number) => call<Message>(`/api/tasks/${id}/messages/${messageId}`, { method: 'DELETE' }),

    staff: (trash = false) => call<{ items: StaffRow[] }>('/api/staff' + (trash ? '?trash=1' : '')),
    createStaff: (payload: Record<string, unknown>) => call<StaffRow>('/api/staff', { method: 'POST', body: json(payload) }),
    updateStaff: (id: number, payload: Record<string, unknown>) => call<StaffRow>(`/api/staff/${id}`, { method: 'PATCH', body: json(payload) }),
    trashStaff: (id: number) => call<StaffRow>(`/api/staff/${id}/trash`, { method: 'POST' }),
    restoreStaff: (id: number) => call<StaffRow>(`/api/staff/${id}/restore`, { method: 'POST' }),
    departments: () => call<{ items: StaffDepartment[]; unassigned: Person[]; canEdit: boolean }>('/api/staff/departments'),
    createDepartment: (name: string) => call<StaffDepartment>('/api/staff/departments', { method: 'POST', body: json({ name }) }),
}
