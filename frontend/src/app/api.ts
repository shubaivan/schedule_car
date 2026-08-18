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

/** Запис довідника телефонів: роль чекає на людину, поки та не зайде в бота. */
export interface StaffPhone {
    id: number
    phone: string
    name: string | null
    role: string
    roleLabel: string
    note: string | null
    appliedTo: ApiUser | null
    appliedAt: string | null
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

export interface Purchase {
    id: number
    supplier: ApiSupplier
    quantity: number | null
    pricePerUnit: number | null
    totalAmount: number
    totalLabel: string
    currency: string
    vatIncluded: boolean
    invoiceNumber: string | null
    purchasedAt: string | null
    payment: string
    paymentLabel: string
}

/** Те, що надсилає форма закупівлі. Досить суми або ціни з кількістю. */
export interface PurchasePayload {
    supplierId: number
    totalAmount?: string
    quantity?: string
    pricePerUnit?: string
    payment?: string
    vatIncluded?: boolean
    invoiceNumber?: string
    purchasedAt?: string
}

export interface Attachment {
    id: number
    type: string
    typeLabel: string
    name: string
    size: number
    sizeLabel: string
    mime: string
    uploadedBy: string | null
    uploadedAt: string
    /** null — копія ще не поїхала в Google Drive; це не помилка. */
    driveUrl: string | null
}

export const ATTACHMENT_TYPES = [
    { value: 'invoice', label: 'Накладна' },
    { value: 'bill', label: 'Рахунок' },
    { value: 'contract', label: 'Договір' },
    { value: 'payment', label: 'Платіжка' },
    { value: 'photo', label: 'Фото' },
    { value: 'other', label: 'Інше' },
]

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
    purchases?: Purchase[]
    purchaseTotal?: number
    attachments?: Attachment[]
}

export interface RequestListResponse {
    items: SupplyRequest[]
    total: number
    page: number
    pageSize: number
    counts: Record<string, number>
}

export interface ApiSupplier {
    id: number
    name: string
    edrpou: string | null
    phone: string | null
    contactPerson: string | null
    note: string | null
    active: boolean
}

/** Поля постачальника, які можна надіслати на створення чи правку. */
export type SupplierPayload = Partial<Omit<ApiSupplier, 'id'>>

export interface Meta {
    statuses: { value: string; label: string; emoji: string; final: boolean }[]
    units: { value: string; label: string }[]
    departments: { id: number; name: string }[]
}

export interface Report {
    period: { from: string; to: string }
    totals: {
        requests: number
        closed: number
        rejected: number
        open: number
        overdue: number
        purchases: number
        spent: number
        /** null — за період не закрили жодної заявки. */
        leadTimeDays: number | null
    }
    suppliers: { id: number; name: string; purchases: number; total: number; share: number }[]
    departments: { id: number | null; name: string; requests: number; total: number }[]
    items: { item: string; requests: number; total: number }[]
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

/** Машина автопарку. */
export interface FleetCar {
    id: number
    carNumber: string
    model: string | null
    active: boolean
    label: string
}

/** Водій у довіднику: чекає, поки людина зайде в бота й поділиться номером. */
export interface FleetDriver {
    id: number
    phone: string
    name: string | null
    note: string | null
    carId: number | null
    carLabel: string | null
    appliedTo: string | null
    appliedAt: string | null
}

export interface CarPayload {
    carNumber?: string
    model?: string | null
    active?: boolean
}

export interface DriverPayload {
    phone?: string
    name?: string | null
    note?: string | null
    carId?: number | null
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

    /** Файл іде як multipart — Content-Type проставляє браузер разом із boundary. */
    uploadAttachment: async (requestId: number, file: File, type: string) => {
        const body = new FormData()
        body.append('file', file)
        body.append('type', type)

        const response = await fetch(`/api/supply/requests/${requestId}/attachments`, {
            method: 'POST',
            credentials: 'same-origin',
            body,
        })

        if (response.status === 401) throw new SessionExpired()

        const payload = await response.json().catch(() => null)

        if (!response.ok) throw new Error(payload?.error ?? `Помилка ${response.status}`)

        return payload as SupplyRequest
    },

    deleteAttachment: (requestId: number, attachmentId: number) =>
        call<SupplyRequest>(`/api/supply/requests/${requestId}/attachments/${attachmentId}`, {
            method: 'DELETE',
        }),

    attachmentUrl: (requestId: number, attachmentId: number) =>
        `/api/supply/requests/${requestId}/attachments/${attachmentId}`,

    addPurchase: (requestId: number, payload: PurchasePayload) =>
        call<SupplyRequest>(`/api/supply/requests/${requestId}/purchases`, {
            method: 'POST',
            body: JSON.stringify(payload),
        }),

    updatePurchase: (requestId: number, purchaseId: number, payload: Partial<PurchasePayload>) =>
        call<SupplyRequest>(`/api/supply/requests/${requestId}/purchases/${purchaseId}`, {
            method: 'PATCH',
            body: JSON.stringify(payload),
        }),

    deletePurchase: (requestId: number, purchaseId: number) =>
        call<SupplyRequest>(`/api/supply/requests/${requestId}/purchases/${purchaseId}`, {
            method: 'DELETE',
        }),

    suppliers: (params: { q?: string; active?: boolean } = {}) => {
        const query = new URLSearchParams()
        if (params.q) query.set('q', params.q)
        if (params.active) query.set('active', '1')

        return call<{ items: ApiSupplier[] }>(`/api/supply/suppliers?${query.toString()}`)
    },

    createSupplier: (payload: SupplierPayload) =>
        call<ApiSupplier>('/api/supply/suppliers', {
            method: 'POST',
            body: JSON.stringify(payload),
        }),

    updateSupplier: (id: number, payload: SupplierPayload) =>
        call<ApiSupplier>(`/api/supply/suppliers/${id}`, {
            method: 'PATCH',
            body: JSON.stringify(payload),
        }),

    reports: (from: string, to: string) =>
        call<Report>(`/api/supply/reports?from=${from}&to=${to}`),

    users: () => call<{ items: ApiUser[] }>('/api/supply/users'),

    updateUser: (id: number, payload: { role?: string; departmentId?: number | null }) =>
        call<ApiUser>(`/api/supply/users/${id}`, {
            method: 'PATCH',
            body: JSON.stringify(payload),
        }),

    staff: () => call<{ items: StaffPhone[] }>('/api/supply/staff'),

    saveStaff: (payload: { phone: string; role: string; name?: string; note?: string }) =>
        call<StaffPhone>('/api/supply/staff', {
            method: 'POST',
            body: JSON.stringify(payload),
        }),

    deleteStaff: (id: number) =>
        call<{ ok: boolean }>(`/api/supply/staff/${id}`, { method: 'DELETE' }),

    cars: () => call<{ items: FleetCar[] }>('/api/fleet/cars'),

    createCar: (payload: CarPayload) =>
        call<FleetCar>('/api/fleet/cars', { method: 'POST', body: JSON.stringify(payload) }),

    updateCar: (id: number, payload: CarPayload) =>
        call<FleetCar>(`/api/fleet/cars/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),

    drivers: () => call<{ items: FleetDriver[] }>('/api/fleet/drivers'),

    createDriver: (payload: DriverPayload) =>
        call<FleetDriver>('/api/fleet/drivers', { method: 'POST', body: JSON.stringify(payload) }),

    updateDriver: (id: number, payload: DriverPayload) =>
        call<FleetDriver>(`/api/fleet/drivers/${id}`, { method: 'PATCH', body: JSON.stringify(payload) }),

    deleteDriver: (id: number) =>
        call<{ ok: boolean }>(`/api/fleet/drivers/${id}`, { method: 'DELETE' }),
}
