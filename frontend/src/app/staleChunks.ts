import type { RouteLocationRaw, Router } from 'vue-router'
import { notify } from '../plan/undo'

/**
 * Стара вкладка після деплою фронту. Розділи вантажаться ліниво (import('./ProfilePage.vue')),
 * а імена файлів мають хеш збірки. Після деплою старих файлів може вже не бути — тоді клік
 * «Профіль» мовчки нічого не робив. Тут ловимо такий збій і один раз перезавантажуємо сторінку
 * (одразу на адресу, куди людина йшла), щоб підтягнулась свіжа збірка.
 *
 * Захист від циклу: позначка в sessionStorage — не частіше одного разу на хвилину.
 * Якщо перезавантаження не допомогло (або sessionStorage недоступний) — тост «Оновити».
 */
const KEY = 'plan:stale-chunk-reload'
const WINDOW_MS = 60_000

const CHUNK_ERROR =
    /Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed|Unable to preload CSS|not a valid JavaScript MIME type|Expected a JavaScript/i

export function isChunkLoadError(err: unknown): boolean {
    const text = err instanceof Error ? `${err.name}: ${err.message}` : String(err ?? '')
    return CHUNK_ERROR.test(text)
}

/** true — перезавантаження пішло; false — вже пробували щойно (або нема де тримати позначку). */
export function reloadOnce(href?: string): boolean {
    try {
        const last = Number(sessionStorage.getItem(KEY) ?? 0)
        if (Date.now() - last < WINDOW_MS) return false
        sessionStorage.setItem(KEY, String(Date.now()))
    } catch {
        // Без sessionStorage не можемо гарантувати «лише раз» — не ризикуємо циклом.
        return false
    }
    if (href && href !== window.location.pathname + window.location.search + window.location.hash) {
        window.location.assign(href)
    } else {
        window.location.reload()
    }
    return true
}

let toastShown = false
function askToReload() {
    if (toastShown) return
    toastShown = true
    notify('Вийшла нова версія «Плану». Оновіть сторінку, щоб відкрити розділ.', {
        tone: 'error',
        ms: 30_000,
        action: { label: 'Оновити', run: () => window.location.reload() },
    })
}

export function installStaleChunkRecovery(router: Router): void {
    // Куди людина зараз переходить: лінивий розділ вантажиться після beforeEach.
    let pending: RouteLocationRaw | null = null
    router.beforeEach((to) => {
        pending = to.fullPath
    })
    router.afterEach(() => {
        pending = null
    })

    const targetHref = () => (pending ? router.resolve(pending).href : undefined)

    // Vite шле цю подію, коли не вдалося завантажити лінивий модуль або його залежності.
    window.addEventListener('vite:preloadError', (event) => {
        if (reloadOnce(targetHref())) event.preventDefault()
    })

    router.onError((err, to) => {
        if (!isChunkLoadError(err)) return
        if (!reloadOnce(router.resolve(to.fullPath).href)) askToReload()
    })
}
