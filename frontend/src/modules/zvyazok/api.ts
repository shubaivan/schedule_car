import { call, SessionExpired } from '../../app/api'
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

/** Файл у повідомленні: фото (уже стиснене сервером), PDF чи офісний документ. */
export interface ZvFile {
    id: number
    name: string
    mime: string
    size: number
    originalSize: number
    kind: 'image' | 'pdf' | 'office'
    width: number | null
    height: number | null
    thumbUrl: string | null
    viewUrl: string
    downloadUrl: string
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
    files: ZvFile[]
    reply: { id: number; author: string | null; text: string } | null
    readBy: number
    readOf: number
}

const BASE = '/api/plan/zvyazok'

/** Що приймаємо (сервер перевіряє за вмістом): фото, PDF, офісні документи. */
export const ZV_ACCEPT = 'image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.heic,.heif,application/pdf,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf'
export const ZV_MAX_BYTES = 20 * 1024 * 1024
export const ZV_MAX_FILES = 10
/** Один запит — до ~22 МБ (nginx пропускає 25 МБ); більше — наступними повідомленнями. */
export const ZV_BATCH_BYTES = 22 * 1024 * 1024

/** Multipart: текст + файли. X-Requested-With — захист від підробки запиту з чужого сайту. */
async function upload(url: string, form: FormData): Promise<ZvMessage> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: form,
    })
    if (response.status === 401) throw new SessionExpired()
    const payload = await response.json().catch(() => null)
    if (!response.ok) throw new Error(payload?.error ?? (response.status === 413 ? 'Файли завеликі для одного повідомлення.' : `Помилка ${response.status}`))
    return payload as ZvMessage
}

/**
 * Фото стискаємо ще в браузері (довша сторона до 1600 px, JPEG 70%) — на заводському
 * мобільному інтернеті 5 МБ з телефона перетворюються на ~300 КБ. Сервер стискає ще раз
 * і не довіряє цьому кроку; не вийшло (HEIC у Chrome тощо) — шлемо як є.
 */
export async function shrinkPhoto(file: File): Promise<File> {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || typeof createImageBitmap !== 'function') return file
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
        const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height))
        const canvas = document.createElement('canvas')
        canvas.width = Math.max(1, Math.round(bitmap.width * scale))
        canvas.height = Math.max(1, Math.round(bitmap.height * scale))
        const ctx = canvas.getContext('2d')
        if (!ctx) return file
        ctx.fillStyle = '#fff'
        ctx.fillRect(0, 0, canvas.width, canvas.height)
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
        bitmap.close()
        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.7))
        if (!blob || blob.size >= file.size) return file
        return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: file.lastModified })
    } catch {
        return file
    }
}

/** Групи файлів по ≤ ZV_BATCH_BYTES і ≤ ZV_MAX_FILES — кожна окремим повідомленням. */
export function batches(files: File[]): File[][] {
    const out: File[][] = []
    let cur: File[] = []
    let size = 0
    for (const f of files) {
        if (cur.length && (size + f.size > ZV_BATCH_BYTES || cur.length >= ZV_MAX_FILES)) {
            out.push(cur)
            cur = []
            size = 0
        }
        cur.push(f)
        size += f.size
    }
    if (cur.length) out.push(cur)
    return out
}
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
    postFiles: (id: number, text: string, files: File[], replyTo: number | null, mentions: number[]) => {
        const form = new FormData()
        form.append('text', text)
        if (replyTo) form.append('replyTo', String(replyTo))
        mentions.forEach((m) => form.append('mentions[]', String(m)))
        files.forEach((f) => form.append('files[]', f, f.name))
        return upload(`${BASE}/chats/${id}/messages`, form)
    },
    read: (id: number, messageId: number) => write<{ lastReadId: number }>(`${BASE}/chats/${id}/read`, 'POST', { messageId }),
    edit: (messageId: number, text: string) => write<ZvMessage>(`${BASE}/messages/${messageId}`, 'PATCH', { text }),
    remove: (messageId: number) => write<{ deleted: boolean }>(`${BASE}/messages/${messageId}`, 'DELETE'),
    history: (messageId: number) => call<{ items: { text: string; at: string | null }[] }>(`${BASE}/messages/${messageId}/history`),
}

/**
 * Чернетка — особиста (ключ з id людини: на спільному заводському ПК інший не побачить),
 * у цьому браузері; відправлене повідомлення її стирає; «Вийти» чистить усі.
 */
let owner = 0
export function draftsOwner(userId: number) {
    owner = userId
}
export function clearDrafts() {
    try {
        Object.keys(localStorage)
            .filter((k) => k.startsWith('plan.zv.draft.'))
            .forEach((k) => localStorage.removeItem(k))
    } catch {
        /* немає сховища */
    }
}
const key = (chatId: number) => `plan.zv.draft.${owner}.${chatId}`
export const drafts = {
    get(chatId: number): string {
        try {
            return localStorage.getItem(key(chatId)) ?? ''
        } catch {
            return ''
        }
    },
    set(chatId: number, text: string) {
        try {
            if (text.trim()) localStorage.setItem(key(chatId), text)
            else localStorage.removeItem(key(chatId))
        } catch {
            /* приватне вікно — без чернеток */
        }
    },
}
