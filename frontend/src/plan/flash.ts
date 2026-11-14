/**
 * Банер «на сторінці, куди людина щойно перейшла» замість спливаючого вікна (alert):
 * форма кладе повідомлення під ключем (напр. «task-12»), сторінка забирає його один раз.
 */
const store = new Map<string, string>()

export function putFlash(key: string, text: string): void {
    store.set(key, text)
}

export function takeFlash(key: string): string | null {
    const text = store.get(key) ?? null
    store.delete(key)
    return text
}
