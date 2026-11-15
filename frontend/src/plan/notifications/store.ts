import { defineStore } from 'pinia'
import { ref } from 'vue'
import { createRealtime, type Realtime, type RealtimeEvent } from '../realtime'
import { notificationsApi, type PlanNotification } from './api'

/** Жовтий тост на всю ширину: важливе (погодження, згадка, лід). */
export interface PlanToast {
    id: number
    title: string
    detail: string
    url: string
    important: boolean
}

/**
 * Сповіщення «Плану» в усій вкладці: лічильник дзвіночка, живий канал (Mercure
 * або опитування), черга тостів, звук. Один на вкладку — запускає оболонка.
 */
export const useNotifications = defineStore('plan-notifications', () => {
    const unread = ref(0)
    const toasts = ref<PlanToast[]>([])
    const transport = ref<'mercure' | 'polling' | 'off'>('off')
    const sound = ref(false)
    /** Остання подія — щоб відкрита сторінка центру знала, що є нове. */
    const lastEvent = ref<RealtimeEvent | null>(null)
    let realtime: (Realtime & { readonly transport: 'mercure' | 'polling' }) | null = null
    let users = 0
    let heartbeat: ReturnType<typeof setInterval> | undefined

    async function refreshCount(): Promise<void> {
        if (typeof document !== 'undefined' && document.hidden) return
        try {
            unread.value = (await notificationsApi.count()).unread
        } catch {
            /* наступна спроба за розкладом */
        }
    }

    async function onEvent(event: RealtimeEvent): Promise<void> {
        if (typeof event.unread === 'number') unread.value = event.unread
        emitActivity(event)
        // bell=false — подія «в темі», але не в дзвіночок: лише оновити відкриті екрани.
        if (event.bell === false) return
        lastEvent.value = event
        if (!event.important) return

        try {
            const item = await notificationsApi.one(event.event)
            pushToast(item)
        } catch {
            /* сповіщення вже недоступне (права змінились) — без тосту */
        }
    }

    function pushToast(item: PlanNotification): void {
        if (toasts.value.some((t) => t.id === item.event)) return
        toasts.value = [...toasts.value.slice(-2), { id: item.event, title: `${item.headline} · ${item.title}`, detail: item.detail, url: item.url, important: item.important }]
        if (sound.value) beep()
    }

    function dismiss(id: number): void {
        toasts.value = toasts.value.filter((t) => t.id !== id)
    }

    /** Запустити один раз на вкладку (лічильник споживачів — як у chronicle.ts). */
    let generation = 0
    let cookieRefresh: ReturnType<typeof setInterval> | undefined

    async function start(): Promise<void> {
        if (users++ > 0) return
        const mine = ++generation
        try {
            const [config, settings] = await Promise.all([notificationsApi.realtime(), notificationsApi.settings().catch(() => null)])
            // Поки чекали — оболонку зняли (або зняли й поставили знову): цей запуск уже не актуальний.
            if (mine !== generation || users === 0) return
            unread.value = config.unread
            sound.value = settings?.prefs.sound ?? false
            realtime = createRealtime(
                config,
                (after, signal) => notificationsApi.events(after, signal),
                20_000,
                () => notificationsApi.realtime(),
            )
            realtime.subscribe((e) => void onEvent(e))
            realtime.start()
            transport.value = realtime.transport
            // Звірка лічильника (прочитали в іншій вкладці) і запасний шлях, якщо подія загубилась.
            heartbeat = setInterval(() => {
                transport.value = realtime?.transport ?? 'off'
                void refreshCount()
            }, 60_000)
            // Кука Mercure живе 12 год — оновлюємо раніше, щоб реконект не впирався в 401.
            cookieRefresh = setInterval(() => void notificationsApi.realtime().catch(() => undefined), 6 * 3600_000)
        } catch {
            transport.value = 'off'
        }
    }

    function stop(): void {
        if (--users > 0) return
        users = 0
        generation++
        realtime?.stop()
        realtime = null
        clearInterval(heartbeat)
        clearInterval(cookieRefresh)
        transport.value = 'off'
    }

    async function markRead(item: PlanNotification): Promise<void> {
        if (item.readAt) return
        item.readAt = new Date().toISOString()
        unread.value = (await notificationsApi.read(item.id)).unread
    }

    async function markAll(): Promise<void> {
        unread.value = (await notificationsApi.readAll()).unread
    }

    return { unread, toasts, transport, sound, lastEvent, start, stop, refreshCount, markRead, markAll, dismiss, pushToast }
})

/**
 * Будь-яка жива подія (і дзвіночок, і «щось змінилось у вашій задачі») — для
 * дошки, стрічки, Хроніки, «Огляду»: оновлюються за подією, а не за таймером.
 */
const activity = new Set<(event: RealtimeEvent) => void>()

export function onActivity(listener: (event: RealtimeEvent) => void): () => void {
    activity.add(listener)
    return () => activity.delete(listener)
}

function emitActivity(event: RealtimeEvent): void {
    activity.forEach((listener) => {
        try {
            listener(event)
        } catch (e) {
            console.error('activity listener failed', e)
        }
    })
}

/** Короткий сигнал без файлу (WebAudio); браузер без звуку — мовчки нічого. */
function beep(): void {
    try {
        const Ctx = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
        if (!Ctx) return
        const ctx = new Ctx()
        const osc = ctx.createOscillator()
        const gain = ctx.createGain()
        osc.frequency.value = 880
        gain.gain.setValueAtTime(0.0001, ctx.currentTime)
        gain.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime + 0.02)
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35)
        osc.connect(gain).connect(ctx.destination)
        osc.start()
        osc.stop(ctx.currentTime + 0.4)
        osc.onended = () => void ctx.close()
    } catch {
        /* звук — не головне */
    }
}
