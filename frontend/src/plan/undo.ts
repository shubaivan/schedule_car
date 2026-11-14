import { reactive } from 'vue'

/**
 * Тост «Видалено · Скасувати» замість вікна підтвердження: дія відбувається одразу на екрані,
 * а людина має 8 секунд, щоб передумати. Два способи:
 *  - commit — справжній запит іде лише після таймера (у сервера немає «відновити»);
 *  - undo — запит уже пішов, «Скасувати» робить зворотну дію (кошик → відновити).
 * Незавершені commit віддаємо одразу, якщо сторінку закривають або компонент зникає.
 */
export interface Toast {
    id: number
    text: string
    tone: 'info' | 'error'
    action?: { label: string; run: () => void }
}

interface Pending {
    timer: number
    commit?: () => Promise<unknown> | void
}

export const toasts = reactive<Toast[]>([])
const pending = new Map<number, Pending>()
let seq = 0

function drop(id: number) {
    const i = toasts.findIndex((t) => t.id === id)
    if (i >= 0) toasts.splice(i, 1)
    const p = pending.get(id)
    if (p) window.clearTimeout(p.timer)
    pending.delete(id)
}

/** Повідомлення без дії (або з дією «Відкрити»): зникає саме. */
export function notify(text: string, opts: { tone?: Toast['tone']; action?: Toast['action']; ms?: number } = {}): number {
    const id = ++seq
    toasts.push({ id, text, tone: opts.tone ?? 'info', action: opts.action })
    pending.set(id, { timer: window.setTimeout(() => drop(id), opts.ms ?? 6000) })
    return id
}

/**
 * Дія з можливістю скасувати.
 * @param commit що зробити, якщо не скасували (викликається один раз)
 * @param undo  що зробити, якщо натиснули «Скасувати»
 */
export function undoable(text: string, opts: { commit?: () => Promise<unknown> | void; undo?: () => void; ms?: number }): number {
    const id = ++seq
    const run = () => {
        const p = pending.get(id)
        drop(id)
        if (p?.commit) void Promise.resolve(p.commit()).catch((e) => notify(e instanceof Error ? e.message : 'Не вдалося', { tone: 'error' }))
    }
    toasts.push({
        id,
        text,
        tone: 'info',
        action: {
            label: 'Скасувати',
            run: () => {
                drop(id)
                opts.undo?.()
            },
        },
    })
    pending.set(id, { timer: window.setTimeout(run, opts.ms ?? 8000), commit: opts.commit })
    return id
}

/** Виконати всі відкладені дії зараз (людина пішла зі сторінки чи закриває вкладку). */
export function flushUndo(): void {
    for (const [id, p] of [...pending]) {
        if (!p.commit) continue
        drop(id)
        void Promise.resolve(p.commit()).catch(() => undefined)
    }
}

/** ✕ на тості: сховати. Якщо дія чекала таймера — виконати її зараз (скасовує лише «Скасувати»). */
export function dismiss(id: number): void {
    const p = pending.get(id)
    drop(id)
    if (p?.commit) void Promise.resolve(p.commit()).catch((e) => notify(e instanceof Error ? e.message : 'Не вдалося', { tone: 'error' }))
}

if (typeof window !== 'undefined') window.addEventListener('pagehide', flushUndo)
