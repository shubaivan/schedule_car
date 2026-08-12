const DATE = new Intl.DateTimeFormat('uk-UA', { day: '2-digit', month: '2-digit', year: 'numeric' })
const DATE_TIME = new Intl.DateTimeFormat('uk-UA', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
})

export function formatDate(value: string): string {
    return DATE.format(new Date(value))
}

export function formatDateTime(value: string): string {
    return DATE_TIME.format(new Date(value))
}
