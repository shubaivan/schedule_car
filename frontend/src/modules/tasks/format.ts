import { plural } from '../../plan/uk'

const TIME = new Intl.DateTimeFormat('uk-UA', { hour: '2-digit', minute: '2-digit' })
const DAY = new Intl.DateTimeFormat('uk-UA', { day: 'numeric', month: 'long' })
const DAY_YEAR = new Intl.DateTimeFormat('uk-UA', { day: 'numeric', month: 'long', year: 'numeric' })
const SHORT = new Intl.DateTimeFormat('uk-UA', { day: 'numeric', month: 'short' })
const WEEKDAY = new Intl.DateTimeFormat('uk-UA', { weekday: 'long', day: 'numeric', month: 'long' })

function startOfDay(d: Date): number {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
}

/** «Сьогодні», «Вчора», «11 листопада». */
export function dayLabel(value: string): string {
    const date = new Date(value)
    const diff = Math.round((startOfDay(new Date()) - startOfDay(date)) / 86400000)
    if (diff === 0) return 'Сьогодні'
    if (diff === 1) return 'Вчора'
    return date.getFullYear() === new Date().getFullYear() ? DAY.format(date) : DAY_YEAR.format(date)
}

export function time(value: string): string {
    return TIME.format(new Date(value))
}

/** Коротко для списків: «14:20», «вчора», «11 лист.». */
export function ago(value: string): string {
    const date = new Date(value)
    const diff = Math.round((startOfDay(new Date()) - startOfDay(date)) / 86400000)
    if (diff === 0) return TIME.format(date)
    if (diff === 1) return 'вчора'
    return SHORT.format(date)
}

/** Дедлайн: «сьогодні», «завтра», «через 3 дні», «прострочено 2 дні». */
export function dueLabel(value: string): string {
    const diff = Math.round((startOfDay(new Date(value)) - startOfDay(new Date())) / 86400000)
    if (diff === 0) return 'сьогодні'
    if (diff === 1) return 'завтра'
    if (diff === -1) return 'вчора'
    if (diff < 0) return `${-diff} ${plural(-diff, 'день', 'дні', 'днів')} тому`
    if (diff < 7) return `через ${diff} ${plural(diff, 'день', 'дні', 'днів')}`
    return SHORT.format(new Date(value))
}

export function today(): string {
    const s = WEEKDAY.format(new Date())
    return s.charAt(0).toUpperCase() + s.slice(1)
}

/** Відмінок за числом — через Intl.PluralRules('uk-UA') (див. plan/uk.ts). */
export { plural }

export function fileSize(bytes: number): string {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1).replace('.', ',') + ' МБ'
    if (bytes >= 1024) return Math.round(bytes / 1024) + ' КБ'
    return bytes + ' Б'
}

/** Колір статусу — один на всю CRM. */
export const STATUS_TONE: Record<string, string> = {
    new: 'sky',
    in_work: 'indigo',
    approval: 'amber',
    approved: 'emerald',
    waiting: 'slate',
    rejected: 'rose',
    done: 'green',
}
