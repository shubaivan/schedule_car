/*
 * Service worker «Плану» (потік S1) — лише Web Push, без кешування сторінок.
 * Scope /crm/. Push прийшов, а План відкритий і видимий — системне сповіщення
 * не показуємо (там уже жовтий тост / лічильник), лише кажемо вкладці.
 */
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

self.addEventListener('push', (event) => {
    let data = {}
    try {
        data = event.data ? event.data.json() : {}
    } catch (e) {
        data = { title: 'План', body: event.data ? event.data.text() : '' }
    }

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
        const visible = windows.find((w) => w.visibilityState === 'visible' && w.focused)

        if (visible) {
            visible.postMessage({ type: 'plan-push', data })
            return
        }

        await self.registration.showNotification(data.title || 'План', {
            body: data.body || '',
            tag: data.tag || undefined,
            renotify: Boolean(data.tag),
            requireInteraction: Boolean(data.important),
            icon: '/plan-icons/icon-192.png',
            badge: '/plan-icons/badge-96.png',
            data: { url: data.url || '/crm/notifications' },
        })
    })())
})

self.addEventListener('notificationclick', (event) => {
    event.notification.close()
    const url = new URL((event.notification.data && event.notification.data.url) || '/crm/notifications', self.location.origin)

    // Лише свій сайт: чужу адресу з payload не відкриваємо.
    if (url.origin !== self.location.origin) return

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
        for (const w of windows) {
            if (new URL(w.url).pathname.startsWith('/crm/') && 'focus' in w) {
                await w.focus()
                if ('navigate' in w) await w.navigate(url.href)
                return
            }
        }
        await self.clients.openWindow(url.href)
    })())
})
