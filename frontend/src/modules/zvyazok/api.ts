import { call } from '../../app/api'
import type { Person } from '../tasks/api'

/** «Звʼязок» — особисті й групові чати поза задачами (/api/plan/zvyazok). */
export interface ZvChatRow {
    id: number
    kind: 'direct' | 'group'
    title: string
    person: Person | null
    members: number
    pinned: boolean
    archived: boolean
    owner: boolean
    unread: number
    mentions: number
    activityAt: string | null
    last: { id: number; text: string; mine: boolean; author: string | null; at: string | null } | null
}

export interface ZvMember extends Person {
    owner: boolean
    lastReadId: number
}

export interface ZvChat {
    id: number
    kind: 'direct' | 'group'
    title: string
    person: ZvMember | null
    owner: boolean
    pinned: boolean
    archived: boolean
    lastReadId: number
    members: ZvMember[]
}

export interface ZvMessage {
    id: number
    author: Person | null
    mine: boolean
    text: string
    deleted: boolean
    edited: boolean
    at: string
    mentions: number[]
    reply: { id: number; author: string | null; text: string } | null
    readBy: number
    readOf: number
}

const BASE = '/api/plan/zvyazok'
const WRITE = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
const write = <T>(url: string, method: string, body: unknown = {}) => call<T>(url, { method, headers: WRITE, body: JSON.stringify(body) })

export const zvApi = {
    chats: (q = '', archived = false) => call<{ items: ZvChatRow[]; unread: number }>(`${BASE}/chats?q=${encodeURIComponent(q)}${archived ? '&archived=1' : ''}`),
    unread: () => call<{ unread: number }>(`${BASE}/unread`),
    people: () => call<{ items: Person[] }>(`${BASE}/people`),
    direct: (userId: number) => write<ZvChat>(`${BASE}/chats`, 'POST', { kind: 'direct', userId }),
    group: (title: string, memberIds: number[]) => write<ZvChat>(`${BASE}/chats`, 'POST', { kind: 'group', title, memberIds }),
    chat: (id: number) => call<ZvChat>(`${BASE}/chats/${id}`),
    update: (id: number, body: { title?: string; add?: number[]; remove?: number[] }) => write<ZvChat>(`${BASE}/chats/${id}`, 'PATCH', body),
    flag: (id: number, body: { pinned?: boolean; archived?: boolean }) => write<ZvChat>(`${BASE}/chats/${id}/flag`, 'POST', body),
    leave: (id: number) => write<{ left: boolean }>(`${BASE}/chats/${id}/leave`, 'POST'),
    messages: (id: number, params: { before?: number; after?: number } = {}) =>
        call<{ items: ZvMessage[]; more: boolean }>(
            `${BASE}/chats/${id}/messages` + (params.before ? `?before=${params.before}` : params.after !== undefined ? `?after=${params.after}` : ''),
        ),
    post: (id: number, text: string, replyTo: number | null, mentions: number[]) => write<ZvMessage>(`${BASE}/chats/${id}/messages`, 'POST', { text, replyTo, mentions }),
    read: (id: number, messageId: number) => write<{ lastReadId: number }>(`${BASE}/chats/${id}/read`, 'POST', { messageId }),
    edit: (messageId: number, text: string) => write<ZvMessage>(`${BASE}/messages/${messageId}`, 'PATCH', { text }),
    remove: (messageId: number) => write<{ deleted: boolean }>(`${BASE}/messages/${messageId}`, 'DELETE'),
    history: (messageId: number) => call<{ items: { text: string; at: string | null }[] }>(`${BASE}/messages/${messageId}/history`),
}

/** Чернетка — особиста, у цьому браузері; відправлене повідомлення її стирає. */
export const drafts = {
    get(chatId: number): string {
        try {
            return localStorage.getItem('plan.zv.draft.' + chatId) ?? ''
        } catch {
            return ''
        }
    },
    set(chatId: number, text: string) {
        try {
            if (text.trim()) localStorage.setItem('plan.zv.draft.' + chatId, text)
            else localStorage.removeItem('plan.zv.draft.' + chatId)
        } catch {
            /* приватне вікно — без чернеток */
        }
    },
}
