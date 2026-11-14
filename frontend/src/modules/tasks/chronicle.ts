import { onBeforeUnmount, onMounted, ref } from 'vue'
import type { ChronicleCounts } from './api'
import { planApi } from '../plan/api'
import { LIVE_FALLBACK_MS, liveConnected } from '../../plan/live'
import { onActivity } from '../../plan/notifications/store'

/**
 * Лічильник Хроніки (скільки записів — задач, заявок, складу — з новим) — один на всю CRM.
 * Живий канал Mercure підключений — перечитуємо за подією (+ раз на хвилину про всяк
 * випадок); без нього — опитування раз на 15 с, поки хоч один компонент його використовує
 * (бейдж у меню, вкладка «Хроніка»).
 */
const counts = ref<ChronicleCounts>({ tasks: 0, events: 0 })
let users = 0
let timer: number | undefined
let pending: number | undefined
let off: (() => void) | undefined

/** Оновити лічильник зараз (відкрили задачу, прочитали) — не чекаючи таймера. */
export async function refreshChronicleCounts(): Promise<void> {
    if (document.hidden) return
    try {
        counts.value = await planApi.counts()
    } catch {
        /* наступна спроба за розкладом */
    }
}

export function setChronicleCounts(value: ChronicleCounts): void {
    counts.value = value
}

function schedule(intervalMs: number): void {
    window.clearTimeout(timer)
    if (users === 0) return
    timer = window.setTimeout(async () => {
        await refreshChronicleCounts()
        schedule(intervalMs)
    }, liveConnected() ? Math.max(LIVE_FALLBACK_MS, intervalMs) : intervalMs)
}

export function useChronicleCounts(intervalMs = 15000) {
    onMounted(() => {
        if (users++ === 0) {
            refreshChronicleCounts()
            schedule(intervalMs)
            off = onActivity(() => {
                window.clearTimeout(pending)
                pending = window.setTimeout(refreshChronicleCounts, 400)
            })
        }
    })
    onBeforeUnmount(() => {
        if (--users === 0) {
            window.clearTimeout(timer)
            window.clearTimeout(pending)
            off?.()
        }
    })

    return { counts, refresh: refreshChronicleCounts }
}
