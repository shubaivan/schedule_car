import { onBeforeUnmount, onMounted, ref } from 'vue'
import type { ChronicleCounts } from './api'
import { planApi } from '../plan/api'

/**
 * Лічильник Хроніки (скільки записів — задач, заявок, складу — з новим) — один на всю CRM.
 * Опитує раз на 15 с, поки хоч один компонент його використовує
 * (бейдж у шапці, вкладка «Хроніка»). Mercure не потрібен.
 */
const counts = ref<ChronicleCounts>({ tasks: 0, events: 0 })
let users = 0
let timer: number | undefined

/** Оновити лічильник зараз (відкрили задачу, прочитали) — не чекаючи 15 с. */
export async function refreshChronicleCounts(): Promise<void> {
    if (document.hidden) return
    try {
        counts.value = await planApi.counts()
    } catch {
        /* наступна спроба за 15 с */
    }
}

export function setChronicleCounts(value: ChronicleCounts): void {
    counts.value = value
}

export function useChronicleCounts(intervalMs = 15000) {
    onMounted(() => {
        if (users++ === 0) {
            refreshChronicleCounts()
            timer = window.setInterval(refreshChronicleCounts, intervalMs)
        }
    })
    onBeforeUnmount(() => {
        if (--users === 0) window.clearInterval(timer)
    })

    return { counts, refresh: refreshChronicleCounts }
}
