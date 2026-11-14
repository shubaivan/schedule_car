/**
 * Живі оновлення «Плану» — один інтерфейс для всього фронту.
 *
 * Дві реалізації з одним інтерфейсом: EventSource на Mercure-хаб
 * (`createMercureRealtime`) і опитування (`createPollingRealtime`). Яку саме —
 * вирішує `createRealtime` за відповіддю сервера, і падає на опитування, коли хаба
 * немає або він недоступний, — компонентам не треба знати, що саме працює.
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
    /** важливе: погодження, згадка, лід — жовтий тост */
    important?: boolean
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
    /** Остання вже відома подія: старіші не роздаються (інакше на старті — шквал тостів). */
    initialEvent?: number
}

/**
 * Опитування: лише поки вкладку видно (прихована вкладка не навантажує сервер),
 * одразу при поверненні на неї; один запит одночасно.
 */
export function createPollingRealtime({ fetch, intervalMs = 30_000, initialEvent = 0 }: PollingOptions): Realtime {
    const listeners = new Set<RealtimeListener>()
    let timer: ReturnType<typeof setTimeout> | null = null
    let controller: AbortController | null = null
    let running = false
    let lastEvent = initialEvent

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

/** Роздати подію слухачам; помилка одного не зупиняє інших. */
function emit(listeners: Set<RealtimeListener>, event: RealtimeEvent): void {
    listeners.forEach((listener) => {
        try {
            listener(event)
        } catch (e) {
            console.error('realtime listener failed', e)
        }
    })
}

export interface MercureOptions {
    /** Адреса хаба (`/.well-known/mercure` на тому ж домені). */
    hub: string
    /** Персональний топік (`https://…/u/<id>`); право на нього — у HttpOnly-куці. */
    topic: string
    initialEvent?: number
    /** Дочитати пропущене після обриву (ті самі події, що й опитування). */
    catchUp: (afterEvent: number, signal: AbortSignal) => Promise<RealtimeEvent[]>
    /** Хаб не відповідає — перейти на опитування. */
    onDown?: () => void
    /** Скільки чекати першого з'єднання, мс. */
    connectTimeoutMs?: number
    /** Скільки помилок поспіль терпіти до переходу на опитування. */
    maxErrors?: number
    /** Для тестів: власний конструктор EventSource. */
    eventSource?: typeof EventSource
}

/**
 * Живий канал Mercure. Подія — те саме мінімальне `{event, type, subject, unread,
 * important}`; дублікати (повтор після реконекту) відкидаються за id події.
 * Щойно з'єднання відкрилось (і після кожного обриву) — один запит «що я пропустив».
 */
export function createMercureRealtime(options: MercureOptions): Realtime {
    const listeners = new Set<RealtimeListener>()
    const ES = options.eventSource ?? (typeof EventSource !== 'undefined' ? EventSource : undefined)
    let source: EventSource | null = null
    let running = false
    let opened = false
    let errors = 0
    let lastEvent = options.initialEvent ?? 0
    let timer: ReturnType<typeof setTimeout> | null = null
    let controller: AbortController | null = null

    const deliver = (event: RealtimeEvent) => {
        if (!event || typeof event.event !== 'number' || event.event <= lastEvent) return
        lastEvent = event.event
        emit(listeners, event)
    }

    const down = () => {
        stop()
        options.onDown?.()
    }

    async function catchUp(): Promise<void> {
        controller?.abort()
        controller = new AbortController()
        try {
            for (const event of await options.catchUp(lastEvent, controller.signal)) deliver(event)
        } catch {
            /* наступне відновлення спробує ще раз */
        }
    }

    function stop(): void {
        running = false
        source?.close()
        source = null
        controller?.abort()
        if (timer !== null) clearTimeout(timer)
        timer = null
    }

    return {
        get mode() {
            return running ? 'push' : 'idle'
        },
        subscribe(listener) {
            listeners.add(listener)
            return () => listeners.delete(listener)
        },
        start() {
            if (running) return
            if (!ES) {
                options.onDown?.()
                return
            }
            running = true
            const url = new URL(options.hub, window.location.href)
            url.searchParams.append('topic', options.topic)
            source = new ES(url.toString(), { withCredentials: true })
            timer = setTimeout(() => {
                if (!opened) down()
            }, options.connectTimeoutMs ?? 8000)
            source.onopen = () => {
                opened = true
                errors = 0
                // І перше з'єднання, і реконект: подія могла прийти між /realtime і відкриттям потоку.
                void catchUp()
            }
            source.onmessage = (message: MessageEvent) => {
                try {
                    deliver(JSON.parse(String(message.data)) as RealtimeEvent)
                } catch {
                    /* не наш формат — пропускаємо */
                }
            }
            source.onerror = () => {
                // EventSource сам перепідключається; закритий назавжди чи багато збоїв поспіль — опитування.
                errors++
                if (!source || source.readyState === 2 || errors >= (options.maxErrors ?? 4)) down()
            }
        },
        stop,
    }
}

/** Що каже сервер про живий канал (`GET /api/plan/realtime`). */
export interface RealtimeConfig {
    mode: 'mercure' | 'polling'
    hub: string | null
    topic: string
    lastEvent: number
    unread: number
}

/**
 * Один живий канал на вкладку: Mercure, якщо сервер його дав і хаб живий;
 * інакше (або коли хаб упав) — опитування з тим самим інтерфейсом. Слухачі
 * переживають перемикання.
 */
export function createRealtime(
    config: RealtimeConfig,
    fetchEvents: PollingOptions['fetch'],
    pollIntervalMs = 20_000,
    /** Перепитати сервер (нова кука) — щоб після падіння хаба повернутись на Mercure. */
    reconfigure?: () => Promise<RealtimeConfig>,
    retryMs = 5 * 60_000,
): Realtime & { readonly transport: 'mercure' | 'polling' } {
    const listeners = new Set<RealtimeListener>()
    let lastEvent = config.lastEvent
    let current: Realtime
    let transport: 'mercure' | 'polling' = 'polling'
    let started = false
    const relay: RealtimeListener = (event) => {
        lastEvent = Math.max(lastEvent, event.event)
        emit(listeners, event)
    }

    let retry: ReturnType<typeof setInterval> | undefined

    const mercure = (cfg: RealtimeConfig) => {
        transport = 'mercure'
        current = createMercureRealtime({ hub: cfg.hub as string, topic: cfg.topic, initialEvent: lastEvent, catchUp: fetchEvents, onDown: () => {
            // Хаб упав (або кука прострочена): дочитуємо пропущене й живемо на опитуванні, час від часу пробуючи хаб знову.
            polling(true)
        } })
        current.subscribe(relay)
        if (started) current.start()
    }

    const polling = (retryHub: boolean) => {
        transport = 'polling'
        current = createPollingRealtime({ fetch: fetchEvents, intervalMs: pollIntervalMs, initialEvent: lastEvent })
        current.subscribe(relay)
        if (started) current.start()
        if (retryHub && reconfigure && retry === undefined) {
            retry = setInterval(async () => {
                try {
                    const fresh = await reconfigure()
                    if (!started || fresh.mode !== 'mercure' || !fresh.hub) return
                    clearInterval(retry)
                    retry = undefined
                    current.stop()
                    mercure(fresh)
                } catch {
                    /* наступна спроба за розкладом */
                }
            }, retryMs)
        }
    }

    if (config.mode === 'mercure' && config.hub) mercure(config)
    else polling(false)

    return {
        get mode() {
            return current.mode
        },
        get transport() {
            return transport
        },
        subscribe(listener) {
            listeners.add(listener)
            return () => listeners.delete(listener)
        },
        start() {
            started = true
            current.start()
        },
        stop() {
            started = false
            clearInterval(retry)
            retry = undefined
            current.stop()
        },
    }
}
