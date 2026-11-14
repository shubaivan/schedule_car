import { call, SessionExpired } from '../../app/api'
import type { Attachment, Message, ReadMark } from '../tasks/api'
import type { FeedApi, FeedPoll } from '../tasks/feedApi'

/** Документ заявки у форматі вкладення задачі + тип і копія в Drive. */
export interface SupplyFile extends Attachment {
    type: string
    typeLabel: string
    driveUrl: string | null
}

/** Відповідь GET /api/supply/requests/{id}/feed (бекенд — Supply\Service\SupplyFeed). */
export interface SupplyFeedResponse {
    messages: Message[]
    files: SupplyFile[]
    status: string
    statusLabel: string
    can: { comment: boolean; attach: boolean; removeFiles: boolean }
    reads: ReadMark[]
    now: string
}

async function upload(url: string, form: FormData): Promise<SupplyFeedResponse> {
    const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: form })
    if (response.status === 401) throw new SessionExpired()
    const payload = await response.json().catch(() => null)
    if (!response.ok) throw new Error(payload?.error ?? (response.status === 413 ? 'Файли завеликі для одного надсилання.' : `Помилка ${response.status}`))
    return payload as SupplyFeedResponse
}

export const supplyFeedApi = {
    get: (id: number) => call<SupplyFeedResponse>(`/api/supply/requests/${id}/feed`),
    post: (id: number, text: string, files: File[], type?: string) => {
        const form = new FormData()
        form.append('text', text)
        files.forEach((f) => form.append('files[]', f, f.name))
        if (type) form.append('type', type)
        return upload(`/api/supply/requests/${id}/feed`, form)
    },
}

/**
 * Адаптер стрічки заявки для TaskChat: той самий чат, ті самі бульбашки.
 * Заявка не має відповідей із цитатою, закріплення й ★ — цих кнопок не буде;
 * видалити можна лише документ (менеджер), з тостом «Скасувати».
 */
export function supplyFeed(id: number, opts: { onFeed?: (f: SupplyFeedResponse) => void } = {}): FeedApi {
    const toPoll = (f: SupplyFeedResponse): FeedPoll => {
        opts.onFeed?.(f)
        return { messages: f.messages, changed: [], attachments: [], status: f.status, reads: f.reads, now: f.now, full: true, files: f.files }
    }
    return {
        poll: async () => toPoll(await supplyFeedApi.get(id)),
        post: async (text, files) => toPoll(await supplyFeedApi.post(id, text, files)).messages,
        remove: async (m) => {
            for (const a of m.attachments) {
                // keepalive: запит доживе, навіть якщо вкладку закривають під час «Скасувати».
                await call(`/api/supply/requests/${id}/attachments/${a.id}`, { method: 'DELETE', keepalive: true })
            }
            return null
        },
        features: { reply: false, pin: false, star: false, mentions: false },
        removedText: 'Документ прибрано',
        placeholder: 'Коментар — заявник отримає його в Telegram',
        intervalMs: 8000,
        fullFeed: true,
    }
}
