export interface ApiUser {
    id: number
    name: string
    phone: string | null
    role: string
    roleLabel: string
    accessStatus: string
    accessStatusLabel: string
    department: string | null
    departmentId: number | null
}

export interface TimelineEvent {
    type: 'status' | 'comment'
    at: string
    author: ApiUser | null
    text: string | null
    status?: string
    statusLabel?: string
    statusFrom?: string | null
}

export interface SupplyRequest {
    id: number
    number: string
    item: string
    quantity: number
    unit: string
    unitLabel: string
    quantityLabel: string
    status: string
    statusLabel: string
    urgent: boolean
    overdue: boolean
    needBy: string | null
    site: string | null
    department: string | null
    departmentId: number | null
    author: ApiUser
    createdAt: string
    note?: string | null
    closedAt?: string | null
    allowedTransitions?: { value: string; label: string }[]
    timeline?: TimelineEvent[]
}

export interface RequestListResponse {
    items: SupplyRequest[]
    total: number
    page: number
    pageSize: number
    counts: Record<string, number>
}

export interface Meta {
    statuses: { value: string; label: string; emoji: string; final: boolean }[]
    units: { value: string; label: string }[]
    departments: { id: number; name: string }[]
}

/** Сесія протухла — далі показуємо екран «візьміть нове посилання в боті». */
export class SessionExpired extends Error {}

async function call<T>(url: string, init: RequestInit = {}): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        ...init,
    })

    if (response.status === 401) {
        throw new SessionExpired()
    }

    const payload = await response.json().catch(() => null)

    if (!response.ok) {
        throw new Error(payload?.error ?? `Помилка ${response.status}`)
    }

    return payload as T
}

export const api = {
    me: () => call<ApiUser>('/api/me'),
    meta: () => call<Meta>('/api/supply/meta'),

    requests: (params: Record<string, string | number | boolean | string[] | undefined>) => {
        const query = new URLSearchParams()

        Object.entries(params).forEach(([key, value]) => {
            if (value === undefined || value === '' || value === false) return
            if (Array.isArray(value)) {
                value.forEach((item) => query.append(`${key}[]`, item))
                return
            }
            query.set(key, String(value))
        })

        return call<RequestListResponse>(`/api/supply/requests?${query.toString()}`)
    },

    request: (id: number) => call<SupplyRequest>(`/api/supply/requests/${id}`),

    changeStatus: (id: number, to: string, comment?: string) =>
        call<SupplyRequest>(`/api/supply/requests/${id}/status`, {
            method: 'POST',
            body: JSON.stringify({ to, comment }),
        }),

    comment: (id: number, text: string) =>
        call<SupplyRequest>(`/api/supply/requests/${id}/comments`, {
            method: 'POST',
            body: JSON.stringify({ text }),
        }),

    users: () => call<{ items: ApiUser[] }>('/api/supply/users'),

    updateUser: (id: number, payload: { role?: string; departmentId?: number | null }) =>
        call<ApiUser>(`/api/supply/users/${id}`, {
            method: 'PATCH',
            body: JSON.stringify(payload),
        }),
}
