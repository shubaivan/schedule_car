/** Час у журналі — київський настінний `2026-11-14 18:03:11`, як у БД; без перерахунку поясів. */
export function auditStamp(at: string, withSeconds = false): string {
    const m = /^(\d{4})-(\d\d)-(\d\d)[ T](\d\d):(\d\d):(\d\d)/.exec(at)
    if (!m) return at
    const [, y, mo, d, h, mi, s] = m
    const thisYear = String(new Date().getFullYear()) === y
    return `${d}.${mo}${thisYear ? '' : '.' + y} ${h}:${mi}${withSeconds ? ':' + s : ''}`
}

export function auditDay(at: string): string {
    return at.slice(0, 10)
}

export const CHANNELS: Record<string, string> = {
    web: 'Веб',
    bot: 'Бот',
    cli: 'Консоль',
    api: 'API',
}

/** Події, текст яких — це сам коментар (малюються блоком «💬», як і коментар до статусу). */
export const COMMENT_TYPES = ['comment_added', 'comment_edited']

/** Колір мітки групи подій — ті самі токени, що й статуси «Плану». */
export const GROUP_TONE: Record<string, string> = {
    create: 'emerald',
    update: 'slate',
    status: 'indigo',
    comment: 'sky',
    members: 'violet',
    file: 'amber',
    money: 'green',
    access: 'rose',
    view: 'slate',
    login: 'rose',
}

/** Шлях на сайті → маршрут SPA (база роутера `/crm/`) або null — звичайне посилання (склад, магазин). */
export function spaPath(url: string | null): string | null {
    if (!url) return null
    return url.startsWith('/crm/') ? url.slice(4) : null
}
