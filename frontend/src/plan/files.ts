import { call } from '../app/api'
import type { Attachment, Person } from '../modules/tasks/api'

/** Звідки файл: чат задачі, документ заявки чи документ складу (як FileType у бекенді). */
export type FileType = 'task' | 'supply' | 'wh'

export interface PlanFileMeta {
    file: Attachment & { fileType: FileType; driveUrl: string | null; driveState: string }
    subject: { type: string; id: number; label: string; route: { name: string; params: Record<string, string> } | null }
    drive: { state: 'pending' | 'done' | 'failed' | 'skipped'; url: string | null }
    share: { ttlDays: number }
}

export interface ShareLink {
    id: number
    createdBy: string
    mine: boolean
    createdAt: string
    expiresAt: string
    revokedAt: string | null
    downloads: number
    canRevoke: boolean
    /** Лише у відповіді на створення: сам токен у базі не зберігається. */
    url?: string
}

export type Recipient = Person & { canView: boolean }

/** Тип файлу з того, що вже є в Attachment (стрічки задач і заявок самі його не пишуть). */
export function fileTypeOf(file: Attachment & { fileType?: FileType }): FileType {
    if (file.fileType) return file.fileType
    if (file.urls.download.startsWith('/api/supply/')) return 'supply'
    if (file.urls.download.startsWith('/api/plan/files/wh/')) return 'wh'
    return 'task'
}

const base = (type: FileType, id: number) => `/api/plan/files/${type}/${id}`

export const filesApi = {
    meta: (type: FileType, id: number) => call<PlanFileMeta>(base(type, id)),
    recipients: (type: FileType, id: number, q = '') => call<{ people: Recipient[] }>(`${base(type, id)}/recipients?q=${encodeURIComponent(q)}`),
    share: (type: FileType, id: number, userIds: number[], note: string) =>
        call<{ shared: string[] }>(`${base(type, id)}/share`, { method: 'POST', body: JSON.stringify({ userIds, note }) }),
    links: (type: FileType, id: number) => call<{ links: ShareLink[] }>(`${base(type, id)}/links`),
    createLink: (type: FileType, id: number) => call<ShareLink & { url: string }>(`${base(type, id)}/links`, { method: 'POST', body: '{}' }),
    revoke: (linkId: number) => call<ShareLink>(`/api/plan/share-links/${linkId}/revoke`, { method: 'POST', body: '{}' }),
}

/** Кнопки «відкрити в месенджері» для посилання /s/{token}. */
export function messengerLinks(url: string, text: string): { label: string; href: string; cls: string }[] {
    const u = encodeURIComponent(url)
    const t = encodeURIComponent(text)
    const both = encodeURIComponent(`${text} ${url}`)
    return [
        { label: 'Telegram', href: `https://t.me/share/url?url=${u}&text=${t}`, cls: 'tg' },
        { label: 'WhatsApp', href: `https://wa.me/?text=${both}`, cls: 'wa' },
        { label: 'Viber', href: `viber://forward?text=${both}`, cls: 'vb' },
    ]
}

/**
 * Web Share Level 2: сам файл у Telegram / WhatsApp / Viber з телефона.
 * Файл треба мати ДО натискання: share() дозволений лише одразу після жесту
 * (Safari не чекає мережі), тож панель готує File заздалегідь (prepareFile).
 */
export function canShareFiles(): boolean {
    const nav = navigator as Navigator & { canShare?: (data: ShareData) => boolean }
    try {
        return typeof nav.share === 'function' && typeof nav.canShare === 'function' && nav.canShare({ files: [new File([''], 'x.txt', { type: 'text/plain' })] })
    } catch {
        return false
    }
}

export async function prepareFile(url: string, name: string, mime: string): Promise<File | null> {
    const response = await fetch(url, { credentials: 'same-origin' })
    if (!response.ok) return null
    const blob = await response.blob()
    const file = new File([blob], name, { type: mime || blob.type || 'application/octet-stream' })
    const nav = navigator as Navigator & { canShare?: (data: ShareData) => boolean }
    return nav.canShare?.({ files: [file] }) ? file : null
}

/** 'cancelled' — людина закрила меню «Поділитися», це не помилка. */
export async function shareFile(file: File): Promise<'shared' | 'cancelled'> {
    try {
        await navigator.share({ files: [file], title: file.name })
        return 'shared'
    } catch (e) {
        if ((e as DOMException)?.name === 'AbortError') return 'cancelled'
        throw e
    }
}
