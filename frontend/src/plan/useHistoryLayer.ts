import { onBeforeUnmount, watch, type Ref } from 'vue'
import { MOBILE_QUERY } from '../modules/tasks/shell/nav'

let seq = 0

/**
 * Розкритий на місці рядок (вибір статусу, дії з повідомленням) — окремий крок історії
 * на телефоні: «Назад» спершу згортає його, а не виходить із задачі (архітектура §7).
 *
 * Відкрили — кладемо в історію копію поточного запису з позначкою (адреса та сама,
 * vue-router її не помічає); «Назад» знімає позначку — згортаємо. Згорнули кнопкою —
 * самі робимо крок назад, щоб у історії не лишилось зайвого запису.
 * На десктопі «Назад» лишається навігацією — там рядки згортаються Esc або кліком.
 */
export function useHistoryLayer(open: Ref<boolean>): void {
    const id = ++seq
    let pushed = false

    const ours = () => (window.history.state as { planLayer?: number } | null)?.planLayer === id

    function onPop() {
        if (pushed && !ours()) {
            pushed = false
            open.value = false
        }
    }

    watch(open, (now) => {
        if (now && !pushed && window.matchMedia(MOBILE_QUERY).matches) {
            window.history.pushState({ ...(window.history.state ?? {}), planLayer: id }, '')
            pushed = true
        } else if (!now && pushed) {
            pushed = false
            if (ours()) window.history.back()
        }
    }, { flush: 'sync' })

    window.addEventListener('popstate', onPop)
    onBeforeUnmount(() => {
        window.removeEventListener('popstate', onPop)
        // Рядок зник разом із компонентом (повідомлення видалили, задачу перемкнули) —
        // знімаємо і його крок історії. Пішли на іншу адресу — крок уже не наш, не чіпаємо.
        if (pushed && ours()) window.history.back()
        pushed = false
    })
}
