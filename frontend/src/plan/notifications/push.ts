import { notificationsApi } from './api'

/**
 * Web Push у браузері: service worker `/sw.js` (scope `/crm/`) + підписка
 * PushManager з VAPID-ключем сервера. iOS/iPadOS 16.4+ дає push лише PWA,
 * доданій на домашній екран, — тоді й показуємо підказку.
 */
export type PushState = 'unsupported' | 'ios-install' | 'denied' | 'off' | 'on'

export const isIos = () => /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
export const isStandalone = () => window.matchMedia?.('(display-mode: standalone)').matches || (navigator as unknown as { standalone?: boolean }).standalone === true

function supported(): boolean {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

async function registration(): Promise<ServiceWorkerRegistration> {
    return (await navigator.serviceWorker.getRegistration('/crm/')) ?? navigator.serviceWorker.register('/sw.js', { scope: '/crm/' })
}

export async function pushState(): Promise<PushState> {
    if (!supported()) return isIos() && !isStandalone() ? 'ios-install' : 'unsupported'
    if (Notification.permission === 'denied') return 'denied'
    const reg = await navigator.serviceWorker.getRegistration('/crm/')
    const sub = await reg?.pushManager.getSubscription()
    return sub ? 'on' : 'off'
}

function keyBytes(base64url: string): Uint8Array<ArrayBuffer> {
    const pad = '='.repeat((4 - (base64url.length % 4)) % 4)
    const raw = atob((base64url + pad).replace(/-/g, '+').replace(/_/g, '/'))
    const out = new Uint8Array(new ArrayBuffer(raw.length))
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i)
    return out
}

export async function enablePush(publicKey: string): Promise<PushState> {
    if (!supported()) return pushState()
    const permission = await Notification.requestPermission()
    if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'off'
    const reg = await registration()
    await navigator.serviceWorker.ready
    const sub = (await reg.pushManager.getSubscription()) ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) }))
    await notificationsApi.pushSubscribe(sub.toJSON())
    return 'on'
}

export async function disablePush(): Promise<PushState> {
    const reg = supported() ? await navigator.serviceWorker.getRegistration('/crm/') : undefined
    const sub = await reg?.pushManager.getSubscription()
    if (sub) {
        await notificationsApi.pushUnsubscribe(sub.endpoint).catch(() => undefined)
        await sub.unsubscribe()
    }
    return pushState()
}

/**
 * Підписка цього браузера (якщо є) — прив'язати до того, хто зараз увійшов:
 * спільний комп'ютер, інша людина відкрила налаштування.
 */
export async function rebindPush(): Promise<void> {
    if (!supported() || Notification.permission !== 'granted') return
    const reg = await navigator.serviceWorker.getRegistration('/crm/')
    const sub = await reg?.pushManager.getSubscription()
    if (sub) await notificationsApi.pushSubscribe(sub.toJSON()).catch(() => undefined)
}

/** Вихід із CRM: push цього браузера більше не належить нікому. */
export async function forgetPushOnLogout(): Promise<void> {
    if (!supported()) return
    const reg = await navigator.serviceWorker.getRegistration('/crm/')
    const sub = await reg?.pushManager.getSubscription()
    if (!sub) return
    await notificationsApi.pushUnsubscribe(sub.endpoint).catch(() => undefined)
    await sub.unsubscribe().catch(() => undefined)
}
