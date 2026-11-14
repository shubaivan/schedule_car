/**
 * Українська в інтерфейсі «Плану»: відмінки за числом, порядок імен, числа й гроші.
 * Лише для «Плану» — стенди заявок мають свої сторінки й не змінюються.
 */
const RULES = new Intl.PluralRules('uk-UA')
const COLLATOR = new Intl.Collator('uk', { sensitivity: 'base', numeric: true })
const NUMBER = new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 3 })
const MONEY = new Intl.NumberFormat('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** «1 особа», «3 особи», «5 осіб», «2,5 тонни» (дробове — як «few»). */
export function plural(n: number, one: string, few: string, many: string): string {
    switch (RULES.select(n)) {
        case 'one':
            return one
        case 'few':
        case 'other': // дробові: «2,5 тонни»
            return few
        default:
            return many
    }
}

export function people(n: number): string {
    return `${num(n)} ${plural(n, 'особа', 'особи', 'осіб')}`
}

/** Порівняння за українською абеткою (А, Б, … І, Ї, Й …), а не за кодами Unicode. */
export function compareUk(a: string | null | undefined, b: string | null | undefined): number {
    return COLLATOR.compare(a ?? '', b ?? '')
}

export function sortByName<T extends { name?: string | null }>(list: readonly T[]): T[] {
    return [...list].sort((a, b) => compareUk(a.name, b.name))
}

/** «2,4», «1 450» — кома в дробах, пробіл між тисячами. */
export function num(value: number | string | null | undefined): string {
    const n = typeof value === 'string' ? Number(value.replace(',', '.')) : value
    return n === null || n === undefined || Number.isNaN(n) ? '' : NUMBER.format(n)
}

/** «9 800,00 ₴». */
export function money(value: number | string | null | undefined, currency = '₴'): string {
    const n = typeof value === 'string' ? Number(value.replace(',', '.')) : value
    return n === null || n === undefined || Number.isNaN(n) ? '' : `${MONEY.format(n)} ${currency}`
}

/** «2,4 т» — кількість заявки з одиницею. */
export function quantity(r: { quantity: number | string; unitLabel?: string; quantityLabel?: string }): string {
    return r.unitLabel !== undefined ? `${num(r.quantity)} ${r.unitLabel}` : (r.quantityLabel ?? '')
}
