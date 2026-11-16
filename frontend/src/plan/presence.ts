import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useSession } from '../app/store'

/**
 * «Хто зараз у Плані» (ТЗ §24; як portal_presence у PMAP, але без запису на кожен GET):
 * поки вкладку видно — heartbeat раз на хвилину (POST /api/plan/presence з розділом),
 * відповідь одразу несе список. Прихована вкладка нічого не шле, тож людина зникає
 * зі списку за 3 хвилини; повернулась — heartbeat одразу. Перехід в інший розділ —
 * теж одразу (з невеликою затримкою на пачку переходів). Відкрили список — GET, без запису.
 */
export interface PresencePerson {
    id: number
    name: string
    initials: string
    color: string
    position: string | null
    /** Номер лише тоді, коли людині видно довідник телефонів (вирішує сервер). */
    phone: string | null
    tel: string | null
    section: PresenceSectionKey
    sectionLabel: string
    me: boolean
    lastSeenAt: string
}

export interface PresenceResponse {
    count: number
    people: PresencePerson[]
    heartbeatSeconds: number
    activeSeconds: number
}

export type PresenceSectionKey =
    'overview' | 'chronicle' | 'tasks' | 'supply' | 'warehouse' | 'search' | 'notifications' | 'staff' | 'files' | 'fleet' | 'admin' | 'profile' | 'other'

/** Розділ за маршрутом — та сама логіка, що підсвічує рейку в PlanShell. Лише розділ, без id. */
export function presenceSection(name: string, query: Record<string, unknown> = {}): PresenceSectionKey {
    if (name === 'tasks') return 'overview'
    if (name === 'tasks-chronicle' || (name === 'tasks-board' && query.view === 'chronicle')) return 'chronicle'
    if (name === 'tasks-search' || (name === 'tasks-board' && query.find === '1')) return 'search'
    if (name === 'tasks-profile') return 'profile'
    if (name.startsWith('task')) return 'tasks'
    if (name.startsWith('supply-')) return 'supply'
    if (name.startsWith('warehouse')) return 'warehouse'
    if (name.startsWith('notifications')) return 'notifications'
    if (name.startsWith('staff')) return 'staff'
    if (name.startsWith('admin')) return 'admin'
    if (name === 'file' || name === 'file-typed' || name === 'drive-status') return 'files'
    if (name === 'fleet' || name === 'schedule') return 'fleet'
    return 'other'
}

const URL = '/api/plan/presence'
/** Пачка переходів (Огляд → Задачі → картка) — один heartbeat. */
const ROUTE_DEBOUNCE_MS = 1500
/** Повернулись на вкладку — heartbeat, якщо попередній старший за це. */
const RESUME_AFTER_MS = 15_000

export const usePresence = defineStore('plan-presence', () => {
    const people = ref<PresencePerson[]>([])
    const count = ref(0)
    const ready = ref(false)

    let section: PresenceSectionKey = 'other'
    let sentSection: PresenceSectionKey | null = null
    let lastBeat = 0
    let heartbeatMs = 60_000
    let timer: ReturnType<typeof setTimeout> | undefined
    let routeTimer: ReturnType<typeof setTimeout> | undefined
    let running = false
    let inflight = false

    const hidden = () => typeof document !== 'undefined' && document.visibilityState === 'hidden'

    /**
     * Запит зі статусом: 401 — сесія скінчилась (екран «візьміть нове посилання», як скрізь);
     * 403/404 (заявка ще не затверджена, розділ вимкнено) — більше не стукаємо;
     * збій мережі чи 5xx — null, наступна спроба за розкладом.
     */
    async function request(init?: RequestInit): Promise<PresenceResponse | null> {
        let response: Response
        try {
            response = await fetch(URL, { credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, ...init })
        } catch {
            return null
        }
        if (response.status === 401) {
            useSession().expired = true
            stop()
            return null
        }
        if (response.status === 403 || response.status === 404) {
            stop()
            return null
        }
        return response.ok ? ((await response.json().catch(() => null)) as PresenceResponse | null) : null
    }

    function apply(data: PresenceResponse): void {
        people.value = data.people
        count.value = data.count
        ready.value = true
        if (data.heartbeatSeconds > 0) heartbeatMs = data.heartbeatSeconds * 1000
    }

    function schedule(): void {
        clearTimeout(timer)
        if (running) timer = setTimeout(() => void beat(), heartbeatMs)
    }

    async function beat(): Promise<void> {
        if (!running) return
        // Уже летить запит — розділ, якщо змінився, дошлемо одразу після нього (finally нижче).
        if (inflight) return
        if (hidden()) return schedule()
        inflight = true
        const sending = section
        let delivered = false
        try {
            const data = await request({ method: 'POST', body: JSON.stringify({ section: sending }) })
            if (data) {
                apply(data)
                sentSection = sending
                lastBeat = Date.now()
                delivered = true
            }
        } finally {
            inflight = false
        }
        if (!running) return
        // Поки летів запит, людина перейшла в інший розділ — шлемо новий одразу, а не за хвилину
        // (лише після вдалого запиту: збій мережі не перетворюється на цикл).
        if (delivered && section !== sentSection) return void beat()
        schedule()
    }

    /** Відкрили список — свіже читання (нічого не пише). */
    async function refresh(): Promise<void> {
        if (!running) return
        const data = await request()
        if (data) apply(data)
    }

    function onVisibility(): void {
        if (!hidden() && Date.now() - lastBeat > RESUME_AFTER_MS) void beat()
    }

    /** Розділ змінився — heartbeat невдовзі; той самий — нічого (його й так шле таймер). */
    function setSection(next: PresenceSectionKey): void {
        section = next
        if (!running || next === sentSection) return
        clearTimeout(routeTimer)
        routeTimer = setTimeout(() => void beat(), ROUTE_DEBOUNCE_MS)
    }

    function start(initial: PresenceSectionKey): void {
        section = initial
        if (running) return
        running = true
        document.addEventListener('visibilitychange', onVisibility)
        void beat()
    }

    function stop(): void {
        running = false
        clearTimeout(timer)
        clearTimeout(routeTimer)
        document.removeEventListener('visibilitychange', onVisibility)
    }

    return { people, count, ready, start, stop, setSection, refresh }
})
