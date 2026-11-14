import { computed, onBeforeUnmount, onMounted } from 'vue'
import { useRoute, useRouter, type LocationQuery } from 'vue-router'
import { useMobile } from './nav'

/** Параметри, що належать відкритій задачі, а не списку: ?m= (повідомлення), ?f= (файл). */
const TASK_QUERY = ['m', 'f']

function listQuery(query: LocationQuery): Record<string, string> {
    const out: Record<string, string> = {}
    for (const [k, v] of Object.entries(query)) {
        if (typeof v === 'string' && v !== '' && !TASK_QUERY.includes(k)) out[k] = v
    }
    return out
}

/**
 * Розділений екран для Хроніки й Пошуку — та сама поведінка, що й у дошки:
 * адреса /tasks/<розділ>/:id, на десктопі задача праворуч, список лишається на місці;
 * на телефоні — задача на весь екран, «назад» повертає до списку. Esc — закрити.
 */
export function useSplitTask(name: string) {
    const route = useRoute()
    const router = useRouter()
    const mobile = useMobile()

    const openId = computed(() => (route.params.id ? Number(route.params.id) : null))

    function open(id: number, extra: Record<string, string> = {}) {
        const to = { name, params: { id: String(id) }, query: { ...listQuery(route.query), ...extra } }
        // Телефон: «назад» має повертати до списку. Десктоп: гортаємо задачі, не засмічуючи історію.
        if (mobile.value || openId.value === null) router.push(to)
        else router.replace(to)
    }

    function close() {
        const base = router.resolve({ name }).path
        const back = (window.history.state as { back?: string } | null)?.back
        if (back && back.split('?')[0] === base) router.back()
        else router.replace({ name, query: listQuery(route.query) })
    }

    function onKey(e: KeyboardEvent) {
        if (e.key !== 'Escape' || e.defaultPrevented || openId.value === null) return
        const el = e.target as HTMLElement | null
        if (el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName))) return
        if (document.querySelector('.t-modal-wrap, .t-viewer, .p-menu')) return
        e.preventDefault()
        close()
    }
    onMounted(() => window.addEventListener('keydown', onKey))
    onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

    return { openId, open, close }
}
