import { call, SessionExpired } from '../../app/api'

export interface Person {
    id: number
    name: string
    initials: string
    color: string
    position: string | null
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

export interface Message {
    id: number
    kind: 'comment' | 'event'
    author: Person | null
    text: string
    mentions: number[]
    createdAt: string
    deleted: boolean
    mine: boolean
    attachments: Attachment[]
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
}

export interface TasksMeta {
    me: Person & { can: { staff: boolean; seeAll: boolean; decide: boolean } }
    statuses: StatusInfo[]
    labels: Label[]
    people: Person[]
    departments: { id: number; name: string; people: number[] }[]
    roles: { value: string; label: string }[]
}

export interface Dashboard {
    stats: { mine: number; overdue: number; open: number; unread: number; decisions: number; byStatus: Record<string, number> }
    mine: TaskItem[]
    overdue: TaskItem[]
    department: TaskItem[]
    departmentName: string | null
    decisions: TaskItem[]
    recent: TaskItem[]
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
    status: (id: number, to: string, comment?: string) =>
        call<TaskDetail>(`/api/tasks/${id}/status`, { method: 'POST', body: json({ to, comment }) }),
    poll: (id: number, after: number, pending: number[]) => {
        const params = new URLSearchParams({ after: String(after) })
        pending.forEach((p) => params.append('pending[]', String(p)))
        return call<{ messages: Message[]; attachments: Attachment[]; status: string }>(`/api/tasks/${id}/messages?` + params)
    },
    post: (id: number, text: string, files: File[], mentions: number[]) => {
        const form = new FormData()
        form.append('text', text)
        files.forEach((f) => form.append('files[]', f, f.name))
        mentions.forEach((m) => form.append('mentions[]', String(m)))
        return upload<Message>(`/api/tasks/${id}/messages`, form)
    },
    deleteMessage: (id: number, messageId: number) => call<Message>(`/api/tasks/${id}/messages/${messageId}`, { method: 'DELETE' }),

    staff: (trash = false) => call<{ items: StaffRow[] }>('/api/staff' + (trash ? '?trash=1' : '')),
    createStaff: (payload: Record<string, unknown>) => call<StaffRow>('/api/staff', { method: 'POST', body: json(payload) }),
    updateStaff: (id: number, payload: Record<string, unknown>) => call<StaffRow>(`/api/staff/${id}`, { method: 'PATCH', body: json(payload) }),
    trashStaff: (id: number) => call<StaffRow>(`/api/staff/${id}/trash`, { method: 'POST' }),
    restoreStaff: (id: number) => call<StaffRow>(`/api/staff/${id}/restore`, { method: 'POST' }),
    departments: () => call<{ items: StaffDepartment[]; unassigned: Person[]; canEdit: boolean }>('/api/staff/departments'),
    createDepartment: (name: string) => call<StaffDepartment>('/api/staff/departments', { method: 'POST', body: json({ name }) }),
}
