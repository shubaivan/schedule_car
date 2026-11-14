import type { LocationQuery, RouteLocationNormalizedLoaded, RouteLocationRaw, Router } from 'vue-router'

/**
 * «Без модалок» (правило Івана 14.11): кожне вікно — адреса, тож «Назад»
 * у браузері й на телефоні закриває його, а посилання можна переслати.
 *
 * Шар у межах сторінки — параметр адреси (?f=12 — відкритий файл). Відкрити = push,
 * закрити = back, якщо попередній крок історії — та сама сторінка без шару;
 * інакше (прийшли за посиланням) — replace без параметра, щоб не вийти з CRM.
 */

function stripped(query: LocationQuery, key: string): Record<string, string> {
    const out: Record<string, string> = {}
    for (const [k, v] of Object.entries(query)) if (k !== key && typeof v === 'string' && v !== '') out[k] = v
    return out
}

export function openLayer(router: Router, route: RouteLocationNormalizedLoaded, key: string, value: string | number): void {
    const query = { ...stripped(route.query, key), [key]: String(value) }
    // Уже відкритий шар (гортаємо файли ←/→) — без нового кроку історії.
    if (typeof route.query[key] === 'string') router.replace({ query })
    else router.push({ query })
}

export function closeLayer(router: Router, route: RouteLocationNormalizedLoaded, key: string): void {
    const without = router.resolve({ path: route.path, query: stripped(route.query, key) }).fullPath
    const back = (window.history.state as { back?: string | null } | null)?.back
    if (back === without) router.back()
    else router.replace({ query: stripped(route.query, key) })
}

/**
 * «Назад» зі сторінки-вікна (форма, картка): на крок назад, якщо він є в межах CRM,
 * інакше — на запасну адресу (відкрили форму прямим посиланням).
 */
export function goBack(router: Router, fallback: RouteLocationRaw): void {
    const back = (window.history.state as { back?: string | null } | null)?.back
    if (back) router.back()
    else router.replace(fallback)
}

/** Фокус у полі вводу — гарячі клавіші сторінки не перехоплюємо. */
export function typing(target: EventTarget | null): boolean {
    const el = target as HTMLElement | null
    return !!el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName))
}
