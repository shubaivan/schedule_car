<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { notificationsApi, type ChannelId, type NotificationPrefs, type NotificationSettings } from './api'
import { disablePush, enablePush, isIos, pushState, rebindPush, type PushState } from './push'
import { useNotifications } from './store'
import { useSession } from '../../app/store'

/**
 * Налаштування сповіщень — окрема сторінка: що надсилати й куди (у Плані /
 * Telegram / push), тихі години, звук, «Усе» для директора, прив'язка бота
 * Плану й Web Push на цьому пристрої. Кожна зміна зберігається одразу.
 */
const session = useSession()
const store = useNotifications()
const settings = ref<NotificationSettings | null>(null)
const saved = ref('')
const error = ref('')
const push = ref<PushState>('unsupported')
const busy = ref(false)
const link = ref<string | null>(null)
let savedTimer: ReturnType<typeof setTimeout> | undefined

const prefs = computed(() => settings.value?.prefs ?? null)

async function load() {
    try {
        settings.value = await notificationsApi.settings()
        push.value = await pushState().catch(() => 'unsupported' as PushState)
        if (push.value === 'on') void rebindPush()
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function save(patch: Partial<NotificationPrefs>) {
    error.value = ''
    try {
        settings.value = await notificationsApi.saveSettings(patch)
        store.sound = settings.value.prefs.sound
        saved.value = 'Збережено'
        clearTimeout(savedTimer)
        savedTimer = setTimeout(() => (saved.value = ''), 1800)
    } catch (e) {
        error.value = session.handle(e)
    }
}

function toggle(group: string, channel: ChannelId, on: boolean) {
    void save({ groups: { [group]: { [channel]: on } } as NotificationPrefs['groups'] })
}

function quiet(from: string | null, to: string | null) {
    void save({ quietFrom: from, quietTo: to })
}

async function connectTelegram() {
    busy.value = true
    error.value = ''
    // Вікно відкриваємо одразу, у самому кліку: після await Safari/iOS вважає його спливаючим і блокує.
    const win = window.open('about:blank', '_blank')
    try {
        const { url } = await notificationsApi.telegramLink()
        link.value = url
        if (win) {
            win.opener = null
            win.location.href = url
        }
        else window.location.href = url
    } catch (e) {
        win?.close()
        error.value = session.handle(e)
    } finally {
        busy.value = false
    }
}

/** Повернулись із Telegram — перечитати, чи вже прив'язано. */
function onFocus() {
    if (link.value && !settings.value?.telegram.linked) void load()
}

async function disconnectTelegram() {
    settings.value = await notificationsApi.telegramUnlink()
    link.value = null
}

async function pushOn() {
    const key = settings.value?.push.publicKey
    if (!key) return
    busy.value = true
    error.value = ''
    try {
        push.value = await enablePush(key)
    } catch (e) {
        error.value = 'Браузер не дав підписатися на push: ' + ((e as Error).message || 'невідома помилка')
    } finally {
        busy.value = false
    }
}

async function pushOff() {
    push.value = await disablePush()
}

async function pushTest() {
    const r = await notificationsApi.pushTest()
    saved.value = r.delivered ? 'Тест надіслано' : 'Не доставлено — перевірте дозвіл браузера'
}

onMounted(() => {
    void load()
    window.addEventListener('focus', onFocus)
})
onBeforeUnmount(() => window.removeEventListener('focus', onFocus))
</script>

<template>
    <div class="p-nset">
        <p v-if="error" class="p-nset-error" role="alert">{{ error }}</p>
        <p class="p-nset-saved" aria-live="polite">{{ saved }}</p>

        <template v-if="settings && prefs">
            <section class="p-card">
                <h3>Telegram</h3>
                <template v-if="settings.telegram.bot">
                    <p v-if="settings.telegram.linked" class="p-nset-ok" data-testid="tg-linked">Підключено до @{{ settings.telegram.bot }}. Сповіщення приходять у бот з кнопкою «Відкрити».</p>
                    <p v-else class="p-muted">Бот @{{ settings.telegram.bot }} надсилатиме сповіщення Плану. Натисніть «Підключити» й відкрийте бот на пристрої, де у вас Telegram.</p>
                    <div class="p-nset-row">
                        <button v-if="!settings.telegram.linked" type="button" class="p-btn p-btn-primary" :disabled="busy" data-testid="tg-connect" @click="connectTelegram">Підключити Telegram</button>
                        <button v-else type="button" class="p-btn" @click="disconnectTelegram">Відключити</button>
                        <label class="p-switch"><input type="checkbox" :checked="prefs.telegram" @change="save({ telegram: ($event.target as HTMLInputElement).checked })" /> Надсилати в Telegram</label>
                    </div>
                    <p v-if="link" class="p-muted p-small">Посилання діє 15 хвилин: <a :href="link" target="_blank" rel="noopener">{{ link }}</a></p>
                    <p class="p-muted p-small">У самому боті: /mute — вимкнути або ввімкнути, /help — довідка.</p>
                </template>
                <p v-else class="p-muted">Telegram-бот на цьому сервері ще не підключено.</p>
            </section>

            <section class="p-card">
                <h3>Push на цьому пристрої</h3>
                <template v-if="settings.push.publicKey">
                    <p v-if="push === 'ios-install'" class="p-nset-hint">На iPhone push працює лише з Плану на домашньому екрані: «Поділитися» → «На екран Додому», відкрийте План звідти й увімкніть тут.</p>
                    <p v-else-if="push === 'unsupported'" class="p-muted">Цей браузер не підтримує push-сповіщення.</p>
                    <p v-else-if="push === 'denied'" class="p-nset-hint">Сповіщення для сайту заблоковано в налаштуваннях браузера — дозвольте їх там і поверніться сюди.</p>
                    <div v-else class="p-nset-row">
                        <button v-if="push !== 'on'" type="button" class="p-btn p-btn-primary" :disabled="busy" data-testid="push-on" @click="pushOn">Увімкнути push</button>
                        <template v-else>
                            <span class="p-nset-ok">Увімкнено</span>
                            <button type="button" class="p-btn" @click="pushTest">Надіслати тест</button>
                            <button type="button" class="p-btn" @click="pushOff">Вимкнути</button>
                        </template>
                        <label class="p-switch"><input type="checkbox" :checked="prefs.push" @change="save({ push: ($event.target as HTMLInputElement).checked })" /> Push узагалі</label>
                    </div>
                    <p v-if="isIos() && push !== 'ios-install'" class="p-muted p-small">iPhone: push приходить, лише поки План стоїть на домашньому екрані.</p>
                </template>
                <p v-else class="p-muted">Push-сповіщення на цьому сервері ще не налаштовано.</p>
            </section>

            <section class="p-card">
                <h3>Тихі години</h3>
                <p class="p-muted p-small">У цей час Telegram приходить без звуку, push не надсилається. У самому Плані все як завжди.</p>
                <div class="p-nset-row">
                    <label>з <input type="time" :value="prefs.quietFrom ?? ''" data-testid="quiet-from" @change="quiet(($event.target as HTMLInputElement).value || null, prefs.quietTo ?? '08:00')" /></label>
                    <label>до <input type="time" :value="prefs.quietTo ?? ''" data-testid="quiet-to" @change="quiet(prefs.quietFrom ?? '22:00', ($event.target as HTMLInputElement).value || null)" /></label>
                    <button v-if="prefs.quietFrom" type="button" class="p-btn" @click="quiet(null, null)">Вимкнути</button>
                </div>
                <label class="p-switch"><input type="checkbox" :checked="prefs.sound" data-testid="sound" @change="save({ sound: ($event.target as HTMLInputElement).checked })" /> Звук разом із жовтим тостом</label>
            </section>

            <section v-if="settings.canWatchAll" class="p-card">
                <h3>Що показувати в центрі</h3>
                <label class="p-switch"><input type="radio" name="scope" :checked="prefs.scope === 'mine'" @change="save({ scope: 'mine' })" /> Лише моє — де я автор, учасник чи спостерігач</label>
                <label class="p-switch"><input type="radio" name="scope" :checked="prefs.scope === 'all'" data-testid="scope-all" @change="save({ scope: 'all' })" /> Усе — події всіх задач і заявок (Telegram і push — лише моє)</label>
            </section>

            <section class="p-card">
                <h3>Події</h3>
                <table class="p-nset-table">
                    <thead>
                        <tr><th scope="col">Подія</th><th v-for="c in settings.channels" :key="c.id" scope="col">{{ c.label }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="g in settings.groups" :key="g.id">
                            <th scope="row">{{ g.label }}<span v-if="g.important" class="p-nset-imp" title="Жовтий тост">важливе</span></th>
                            <td v-for="c in settings.channels" :key="c.id">
                                <input type="checkbox" :aria-label="g.label + ' — ' + c.label" :checked="prefs.groups[g.id]?.[c.id] ?? true" :data-testid="`pref-${g.id}-${c.id}`" @change="toggle(g.id, c.id, ($event.target as HTMLInputElement).checked)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>
        <p v-else-if="!error" class="p-muted">Завантаження…</p>
    </div>
</template>

<style>
.p-nset { max-width: 760px; margin: 0 auto; display: flex; flex-direction: column; gap: 12px; }
.p-nset .p-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--p-r, 10px); padding: 14px 16px; display: flex; flex-direction: column; gap: 10px; }
.p-nset h3 { margin: 0; font-size: 15px; }
.p-nset-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.p-nset-row input[type="time"] { height: 34px; padding: 0 8px; border: 1px solid var(--border-strong); border-radius: 8px; background: var(--surface); color: var(--text); font: inherit; }
.p-switch { display: flex; align-items: center; gap: 8px; cursor: pointer; }
.p-switch input { width: 18px; height: 18px; accent-color: var(--accent); }
.p-nset-ok { color: var(--green, #15803d); font-weight: 600; margin: 0; }
.p-nset-hint { background: #fef3c7; color: #78350f; border: 1px solid #f59e0b; border-radius: 8px; padding: 8px 10px; margin: 0; }
.p-nset-error { color: var(--danger); margin: 0; }
.p-nset-saved { position: sticky; top: 0; margin: 0; min-height: 0; color: var(--green, #15803d); font-weight: 600; text-align: right; font-size: 13px; }
.p-nset-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.p-nset-table th, .p-nset-table td { padding: 8px 6px; border-top: 1px solid var(--border); }
.p-nset-table thead th { border-top: 0; color: var(--text-muted); font-weight: 600; font-size: 12.5px; }
.p-nset-table th[scope="row"] { text-align: left; font-weight: 500; }
.p-nset-table td { text-align: center; width: 84px; }
.p-nset-table input { width: 20px; height: 20px; accent-color: var(--accent); }
.p-nset-imp { margin-left: 6px; padding: 1px 6px; border-radius: 6px; background: #fde68a; color: #78350f; font-size: 11px; font-weight: 700; }
.p-btn-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
.p-small { font-size: 12.5px; margin: 0; }
@media (max-width: 760px) {
    .p-nset-table td { width: 56px; }
    .p-nset-table thead th { font-size: 11.5px; }
}
</style>
