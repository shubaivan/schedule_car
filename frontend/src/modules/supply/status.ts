/** Колір статусу заявки — ті самі тони, що в задачах «Плану». */
export const SUPPLY_TONE: Record<string, string> = {
    new: 'sky',
    in_progress: 'indigo',
    approval: 'amber',
    approved: 'emerald',
    waiting: 'slate',
    paid: 'emerald',
    delivery: 'sky',
    in_stock: 'green',
    ready: 'green',
    rejected: 'rose',
}

/** Колір смужки мітки менеджера (🔴 🟡 🟢) на картці списку. */
export const ACCENT_COLOR: Record<string, string> = {
    red: '#ef4444',
    yellow: '#f59e0b',
    green: '#10b981',
}

/** Рішення директора: ці переходи — у жовтій смузі, решта — у рядку статусів менеджера. */
export const DECISION_FROM = ['approval', 'waiting']

export const tone = (status: string) => 'tone-' + (SUPPLY_TONE[status] ?? 'slate')
