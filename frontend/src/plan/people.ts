import { computed } from 'vue'
import { useTasks } from '../modules/tasks/store'

/**
 * Телефон поруч з іменем людини — скрізь у Плані (рішення Івана 15.11):
 * «Олександр Чигрин (093 350 44 42)». Номер беремо з довідника людей (/api/tasks/meta → people:
 * phone, tel — формат бекенду PhoneFormat). Шукаємо за id, а там, де API віддає лише ім'я
 * рядком, — за ім'ям (лише якщо воно однозначне; «Ім'я (@нік)» теж упізнаємо).
 */
export interface PhoneEntry {
    phone: string
    tel: string
}

export function usePhoneBook() {
    const store = useTasks()
    // Довідник людей вантажить і решта сторінок Плану; тут — на випадок, коли ще ні (кешується).
    if (!store.meta) store.load().catch(() => undefined)

    const byId = computed(() => {
        const map = new Map<number, PhoneEntry>()
        for (const p of store.meta?.people ?? []) if (p.phone && p.tel) map.set(p.id, { phone: p.phone, tel: p.tel })
        return map
    })

    const byName = computed(() => {
        const map = new Map<string, PhoneEntry | null>()
        for (const p of store.meta?.people ?? []) {
            const key = normalizeName(p.name)
            // Двоє з однаковим іменем — не вгадуємо.
            map.set(key, map.has(key) ? null : p.phone && p.tel ? { phone: p.phone, tel: p.tel } : null)
        }
        return map
    })

    function lookup(id?: number | null, name?: string | null): PhoneEntry | null {
        if (id) {
            const hit = byId.value.get(id)
            if (hit) return hit
            if (store.meta?.people.some((p) => p.id === id)) return null
        }
        return name ? (byName.value.get(normalizeName(name)) ?? null) : null
    }

    return { lookup }
}

/** «Іван Шуба (@ivan)» → «іван шуба». */
export function normalizeName(name: string): string {
    return name.replace(/\s*\(@[^)]*\)\s*$/, '').trim().toLowerCase()
}

/** Ім'я з телефоном одним рядком — для title, select, тексту без розмітки. */
export function withPhone(name: string, phone?: string | null): string {
    return phone ? `${name} (${phone})` : name
}

/** Як PhoneFormat на бекенді: 0933504442 / +380 93 … → «093 350 44 42» і tel «+380933504442». */
export function formatPhone(raw?: string | null): PhoneEntry | null {
    const digits = (raw ?? '').replace(/\D+/g, '')
    const full = digits.length === 9 ? '380' + digits : digits.length === 10 && digits.startsWith('0') ? '38' + digits : digits.length >= 11 ? digits : null
    if (!full) return null
    if (full.length === 12 && full.startsWith('380')) {
        const l = '0' + full.slice(3)
        return { phone: `${l.slice(0, 3)} ${l.slice(3, 6)} ${l.slice(6, 8)} ${l.slice(8, 10)}`, tel: '+' + full }
    }
    return { phone: '+' + full, tel: '+' + full }
}
