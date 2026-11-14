import { call } from '../../app/api'
import type { RealtimeConfig, RealtimeEvent } from '../realtime'

/** Рядок центру сповіщень (`/api/plan/notifications`). */
export interface PlanNotification {
    id: number
    event: number
    type: string
    group: string
    important: boolean
    subject: { type: string; id: number; key: string }
    title: string
    /** шлях у CRM від кореня сайту: `/crm/tasks/board/12` */
    url: string
    headline: string
    detail: string
    actor: { id: number; name: string } | null
    createdAt: string
    readAt: string | null
}

export interface NotificationPage {
    items: PlanNotification[]
    next: number | null
    unread: number
}

export type ChannelId = 'app' | 'telegram' | 'push'

export interface NotificationPrefs {
    scope: 'mine' | 'all'
    telegram: boolean
    push: boolean
    sound: boolean
    quietFrom: string | null
    quietTo: string | null
    groups: Record<string, Record<ChannelId, boolean>>
}

export interface NotificationSettings {
    prefs: NotificationPrefs
    groups: { id: string; label: string; important: boolean }[]
    channels: { id: ChannelId; label: string }[]
    canWatchAll: boolean
    telegram: { bot: string | null; linked: boolean; planBot: boolean }
    push: { publicKey: string | null }
}

const BASE = '/api/plan'
const json = (body: unknown): RequestInit => ({ method: 'POST', body: JSON.stringify(body) })

export const notificationsApi = {
    page: (opts: { unread?: boolean; before?: number | null } = {}, signal?: AbortSignal) => {
        const q = new URLSearchParams()
        if (opts.unread) q.set('unread', '1')
        if (opts.before) q.set('before', String(opts.before))
        return call<NotificationPage>(`${BASE}/notifications${q.size ? '?' + q : ''}`, { signal })
    },
    count: () => call<{ unread: number; lastEvent: number }>(`${BASE}/notifications/count`),
    events: (after: number, signal?: AbortSignal) => call<{ events: RealtimeEvent[] }>(`${BASE}/notifications/events?after=${after}`, { signal }).then((r) => r.events),
    one: (event: number) => call<PlanNotification>(`${BASE}/notifications/event/${event}`),
    read: (id: number) => call<{ unread: number }>(`${BASE}/notifications/${id}/read`, { method: 'POST' }),
    readSubject: (key: string) => call<{ unread: number }>(`${BASE}/notifications/read-subject`, json({ subject: key })),
    readAll: () => call<{ unread: number }>(`${BASE}/notifications/read-all`, { method: 'POST' }),
    settings: () => call<NotificationSettings>(`${BASE}/notifications/settings`),
    saveSettings: (patch: Partial<NotificationPrefs>) => call<NotificationSettings>(`${BASE}/notifications/settings`, { method: 'PUT', body: JSON.stringify(patch) }),
    realtime: () => call<RealtimeConfig>(`${BASE}/realtime`),
    telegramLink: () => call<{ url: string; expiresAt: string }>(`${BASE}/telegram/link`, { method: 'POST' }),
    telegramUnlink: () => call<NotificationSettings>(`${BASE}/telegram/link`, { method: 'DELETE' }),
    pushSubscribe: (subscription: PushSubscriptionJSON) => call<{ ok: boolean }>(`${BASE}/push/subscriptions`, json(subscription)),
    pushUnsubscribe: (endpoint: string) => call<{ ok: boolean }>(`${BASE}/push/subscriptions`, { method: 'DELETE', body: JSON.stringify({ endpoint }) }),
    pushTest: () => call<{ delivered: number }>(`${BASE}/push/test`, { method: 'POST' }),
}
