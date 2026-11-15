import { computed, nextTick, onBeforeUnmount, onMounted, watch } from 'vue'
import { useRoute, useRouter, type LocationQuery } from 'vue-router'
import { useMobile } from '../modules/tasks/shell/nav'
import { typing } from './layers'

/** Параметри, що належать відкритому праворуч, а не списку: ?m= (повідомлення), ?f= (файл). */
const ITEM_QUERY = ['m', 'f']

export function listQuery(query: LocationQuery): Record<string, string> {
    const out: Record<string, string> = {}
    for (const [k, v] of Object.entries(query)) {
        if (typeof v === 'string' && v !== '' && !ITEM_QUERY.includes(k)) out[k] = v
    }
    return out
}

/**
 * Розділений екран «список ліворуч — відкрите праворуч» для будь-якого розділу
 * (задачі в Хроніці й Пошуку, заявки, люди): адреса /<розділ>/:id, на десктопі
 * гортаємо без нових кроків історії, на телефоні відкрите — на весь екран,
 * «назад» повертає до списку з тією ж прокруткою. Esc — закрити.
 */
export function useSplit(name: string) {
    const route = useRoute()
    const router = useRouter()
    const mobile = useMobile()

    const openId = computed(() => (route.params.id ? Number(route.params.id) : null))

    function open(id: number, extra: Record<string, string> = {}) {
        const to = { name, params: { id: String(id) }, query: { ...listQuery(route.query), ...extra } }
        // Телефон: «назад» має повертати до списку. Десктоп: гортаємо, не засмічуючи історію.
        if (mobile.value || openId.value === null) router.push(to)
        else router.replace(to)
    }

    function close() {
        const base = router.resolve({ name }).path
        const back = (window.history.state as { back?: string } | null)?.back
        if (back && back.split('?')[0] === base) router.back()
        else router.replace({ name, query: listQuery(route.query) })
    }

    // Телефон: список під відкритим лише схований — «назад» повертає туди, де людина була.
    let savedY = 0
    watch(
        openId,
        async (now, before) => {
            if (!mobile.value) return
            if (now && !before) savedY = window.scrollY
            if (now) window.scrollTo(0, 0)
            if (!now && before) {
                await nextTick()
                window.scrollTo(0, savedY)
            }
        },
        { flush: 'pre' },
    )

    function onKey(e: KeyboardEvent) {
        if (e.key !== 'Escape' || e.defaultPrevented || openId.value === null || typing(e.target)) return
        // Відкритий файл закривається першим (його Esc обробляє переглядач).
        if (route.query.f) return
        e.preventDefault()
        close()
    }
    onMounted(() => window.addEventListener('keydown', onKey))
    onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

    return { openId, open, close }
}
