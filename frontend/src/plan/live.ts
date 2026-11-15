import { onBeforeUnmount, onMounted, watch } from 'vue'
import type { RealtimeEvent } from './realtime'
import { onActivity, useNotifications } from './notifications/store'

/**
 * Оновлення екрана «за подією, а таймер — запасний»: поки живий канал Mercure
 * підключений, екран перечитується лише коли прийшла подія, що його стосується
 * (з невеликою затримкою, щоб пачка подій дала один запит), і раз на хвилину
 * про всяк випадок. Без Mercure (стенди, хаб упав) — опитування як раніше.
 */
export interface LiveOptions {
    /** Інтервал опитування без Mercure, мс. */
    pollMs: number
    /** Запасний інтервал при живому Mercure, мс (не менше 60 с). */
    fallbackMs?: number
    /** Яка подія стосується екрана; за замовчуванням — будь-яка. */
    match?: (event: RealtimeEvent) => boolean
    /** Затримка після події, мс. */
    debounceMs?: number
    /** Поки true — опитувати часто навіть із Mercure (напр. чекаємо прев'ю файлу). */
    fastWhile?: () => boolean
}

export const LIVE_FALLBACK_MS = 60_000

export function useLiveRefresh(refresh: () => unknown, options: LiveOptions): void {
    const store = useNotifications()
    let timer: number | undefined
    let pending: number | undefined
    let off: (() => void) | undefined

    let alive = false
    const interval = () => (store.transport === 'mercure' && !options.fastWhile?.() ? Math.max(LIVE_FALLBACK_MS, options.fallbackMs ?? LIVE_FALLBACK_MS) : options.pollMs)
    const tick = () => {
        if (!document.hidden) void refresh()
    }
    const schedule = () => {
        window.clearTimeout(timer)
        if (!alive) return
        timer = window.setTimeout(() => {
            tick()
            schedule()
        }, interval())
    }

    onMounted(() => {
        alive = true
        schedule()
        off = onActivity((event) => {
            if (options.match && !options.match(event)) return
            window.clearTimeout(pending)
            pending = window.setTimeout(tick, options.debounceMs ?? 300)
        })
    })
    // Канал змінився (Mercure підключився або впав) — новий розклад; після падіння — одразу дочитати.
    watch(
        () => store.transport,
        (now, before) => {
            schedule()
            if (before === 'mercure' && now !== 'mercure') tick()
        },
    )
    onBeforeUnmount(() => {
        alive = false
        window.clearTimeout(timer)
        window.clearTimeout(pending)
        off?.()
    })
}

/** Чи зараз працює живий канал (для модулів без компонента). */
export function liveConnected(): boolean {
    return useNotifications().transport === 'mercure'
}
