import { call } from '../../app/api'
import { tasksApi, type Attachment, type Message, type PollResult, type ReadMark } from './api'
import { TASK_SAVED } from './shell/nav'

/**
 * Стрічка, яку малює TaskChat: задача або заявка (SupplyFeed на бекенді віддає
 * заявку в тому самому форматі повідомлень). Від суб'єкта чату потрібні лише ці поля.
 */
export interface FeedSubject {
    id: number
    status: string
    messages: Message[]
    files: Attachment[]
    reads: ReadMark[]
    readUpTo: number | null
    now?: string
    /** attach: false — коментувати можна, а файли ні (директор у заявці). */
    can: { comment: boolean; attach?: boolean }
}

export interface FeedPoll extends PollResult {
    /** true — у відповіді вся стрічка (заявки): що зникло на сервері, зникає й тут. */
    full?: boolean
    files?: Attachment[]
}

/** Як читати й писати стрічку. Методу немає — немає й кнопки. */
export interface FeedApi {
    poll(after: number, pending: number[], changedSince: string | null): Promise<FeedPoll>
    /** Нове повідомлення (й файли). Повертає те, що з'явилось у стрічці. */
    post(text: string, files: File[], mentions: number[], replyTo?: number | null): Promise<Message[]>
    edit?(messageId: number, text: string): Promise<Message>
    pin?(messageId: number, on: boolean): Promise<Message>
    star?(messageId: number, on: boolean): Promise<Message>
    /** Видалити. null — повідомлення зникає зі стрічки зовсім (документ заявки). */
    remove?(m: Message): Promise<Message | null>
    features: { reply: boolean; pin: boolean; star: boolean; mentions: boolean }
    /** Що сказати в тості «… · Скасувати». */
    removedText?: string
    placeholder?: string
    /** Як часто опитувати (мс). Задача — 4 с; заявка віддає всю стрічку, тож рідше. */
    intervalMs?: number
    /** post повертає всю стрічку в порядку сервера (заявка), а не лише нові повідомлення. */
    fullFeed?: boolean
    /** Після власного повідомлення: задача — перечитати дошку. */
    onSaved?(): void
}

export function taskFeed(id: number): FeedApi {
    return {
        poll: (after, pending, changedSince) => tasksApi.poll(id, after, pending, changedSince),
        post: async (text, files, mentions, replyTo) => [await tasksApi.post(id, text, files, mentions, replyTo)],
        edit: (messageId, text) => tasksApi.editMessage(id, messageId, text),
        pin: (messageId, on) => tasksApi.pinMessage(id, messageId, on),
        star: (messageId, on) => tasksApi.starMessage(id, messageId, on),
        // keepalive: запит доживе, навіть якщо людина закриває вкладку під час «Скасувати».
        remove: (m) => call<Message>(`/api/tasks/${id}/messages/${m.id}`, { method: 'DELETE', keepalive: true }),
        features: { reply: true, pin: true, star: true, mentions: true },
        removedText: 'Повідомлення видалено',
        onSaved: () => window.dispatchEvent(new Event(TASK_SAVED)),
    }
}
