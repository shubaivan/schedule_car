import type { Attachment } from './api'

/** Клас кольору бейджа за розширенням: PDF червоний, Word синій, Excel зелений… */
export function extClass(a: Pick<Attachment, 'ext' | 'kind'>): string {
    const ext = a.ext.toLowerCase()
    if (a.kind === 'image') return 'ext-img'
    if (ext === 'pdf') return 'ext-pdf'
    if (['doc', 'docx', 'odt', 'rtf'].includes(ext)) return 'ext-doc'
    if (['xls', 'xlsx', 'ods', 'csv'].includes(ext)) return 'ext-xls'
    if (['ppt', 'pptx', 'odp'].includes(ext)) return 'ext-ppt'
    return 'ext-other'
}

export function pagesLabel(n: number | null): string {
    if (!n) return ''
    const n10 = n % 10
    const n100 = n % 100
    const word = n10 === 1 && n100 !== 11 ? 'сторінка' : n10 >= 2 && n10 <= 4 && (n100 < 12 || n100 > 14) ? 'сторінки' : 'сторінок'
    return `${n} ${word}`
}
