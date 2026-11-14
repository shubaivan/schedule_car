/**
 * Живі оновлення «Плану» — один інтерфейс для всього фронту.
 *
 * Зараз — лише реалізація опитуванням (`createPollingRealtime`). Потік S1 додає
 * EventSource (Mercure) з тим самим інтерфейсом і падає назад на опитування,
 * коли хаб недоступний, — компонентам не треба знати, що саме працює.
 *
 * Подія мінімальна (як і payload Mercure): тип, суб'єкт, лічильник. Дані
 * компонент дочитує сам через API — у подію нічого поза правами не потрапляє.
 */

export type SubjectType = 'task' | 'supply' | 'wh_item' | 'wh_movement' | 'wh_client' | 'wh_site' | 'wh_category' | 'lead' | 'user'

export interface SubjectRef {
    type: SubjectType
    id: number
}

export interface RealtimeEvent {
    /** id рядка plan_event */
    event: number
    /** plan_event.type: comment_added, status_changed, … */
    type: string
    subject: SubjectRef
    /** скільки непрочитаного в людини після цієї події, якщо сервер знає */
    unread?: number
}

export type RealtimeListener = (event: RealtimeEvent) => void

export interface Realtime {
    /** 'push' — живе з'єднання; 'polling' — опитування; 'idle' — не запущено */
    readonly mode: 'push' | 'polling' | 'idle'
    /** Підписатись; повертає функцію відписки. */
    subscribe(listener: RealtimeListener): () => void
    start(): void
    stop(): void
}

/** `task-12` — ключ суб'єкта в маршрутах (`/chronicle/:key`). */
export function subjectKey(subject: SubjectRef): string {
    return `${subject.type}-${subject.id}`
}

export interface PollingOptions {
    /**
     * Нові події після `afterEvent` (0 — від початку сесії). Повертає їх від старих
     * до нових. Помилка мережі — не страшно: наступна спроба за розкладом.
     */
    fetch: (afterEvent: number, signal: AbortSignal) => Promise<RealtimeEvent[]>
    /** Інтервал, мс. За замовчуванням 30 с — не 4 с, як колись у TaskChat. */
    intervalMs?: number
}

/**
 * Опитування: лише поки вкладку видно (прихована вкладка не навантажує сервер),
 * одразу при поверненні на неї; один запит одночасно.
 */
export function createPollingRealtime({ fetch, intervalMs = 30_000 }: PollingOptions): Realtime {
    const listeners = new Set<RealtimeListener>()
    let timer: ReturnType<typeof setTimeout> | null = null
    let controller: AbortController | null = null
    let running = false
    let lastEvent = 0

    const visible = () => typeof document === 'undefined' || document.visibilityState !== 'hidden'

    const schedule = () => {
        if (timer !== null) clearTimeout(timer)
        timer = running ? setTimeout(tick, intervalMs) : null
    }

    async function tick(): Promise<void> {
        if (!running || controller !== null || !visible()) {
            schedule()
            return
        }

        controller = new AbortController()

        try {
            const events = await fetch(lastEvent, controller.signal)

            for (const event of events) {
                if (event.event <= lastEvent) continue
                lastEvent = event.event
                listeners.forEach((listener) => {
                    try {
                        listener(event)
                    } catch (e) {
                        console.error('realtime listener failed', e)
                    }
                })
            }
        } catch {
            // Мережа/сервер — повторимо за розкладом.
        } finally {
            controller = null
            schedule()
        }
    }

    const onVisibility = () => {
        if (running && visible()) void tick()
    }

    return {
        get mode() {
            return running ? 'polling' : 'idle'
        },
        subscribe(listener) {
            listeners.add(listener)
            return () => listeners.delete(listener)
        },
        start() {
            if (running) return
            running = true
            if (typeof document !== 'undefined') document.addEventListener('visibilitychange', onVisibility)
            void tick()
        },
        stop() {
            running = false
            controller?.abort()
            if (timer !== null) clearTimeout(timer)
            timer = null
            if (typeof document !== 'undefined') document.removeEventListener('visibilitychange', onVisibility)
        },
    }
}
