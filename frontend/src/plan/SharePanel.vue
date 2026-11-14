<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useSession } from '../app/store'
import {
    canShareFiles,
    filesApi,
    messengerLinks,
    prepareFile,
    shareFile,
    type FileType,
    type PlanFileMeta,
    type Recipient,
    type ShareLink,
} from './files'

/**
 * «Поділитися» файлом — вбудована панель читалки (не модалка): на телефоні —
 * окремий крок історії (useHistoryLayer у FileViewer), «Назад» згортає її.
 *
 * Три шляхи, від найкращого:
 * 1) телефон — сам файл у Telegram / WhatsApp / Viber (Web Share Level 2);
 * 2) колегам у «Плані» — лише тим, хто має доступ (сервер перевіряє ще раз), → сповіщення;
 * 3) людині поза «Планом» — тимчасове посилання на ttlDays днів (відкликається тут же)
 *    + кнопки месенджерів. Google Drive файл нікому не відкриває — він лише архів.
 */
const props = defineProps<{ type: FileType; id: number; name: string; mime: string; fetchUrl: string }>()
const emit = defineEmits<{ close: [] }>()
const session = useSession()

const meta = ref<PlanFileMeta | null>(null)
const people = ref<Recipient[]>([])
const q = ref('')
const picked = ref<number[]>([])
const note = ref('')
const links = ref<ShareLink[]>([])
const fresh = ref<(ShareLink & { url: string }) | null>(null)
const busy = ref('')
const notice = ref('')
const error = ref('')
const copied = ref(false)

const nativeOk = canShareFiles()
const nativeFile = ref<File | null>(null)
const nativeState = ref<'idle' | 'loading' | 'ready' | 'none'>(nativeOk ? 'loading' : 'none')

const allowed = computed(() => people.value.filter((p) => p.canView))
const blocked = computed(() => people.value.filter((p) => !p.canView))
const ttl = computed(() => meta.value?.share.ttlDays ?? 7)
const messengers = computed(() => (fresh.value ? messengerLinks(fresh.value.url, props.name) : []))

async function guard(label: string, work: () => Promise<void>) {
    busy.value = label
    error.value = ''
    try {
        await work()
    } catch (e) {
        error.value = session.handle(e) || (e as Error).message || 'Не вдалось.'
    } finally {
        busy.value = ''
    }
}

async function loadPeople() {
    people.value = (await filesApi.recipients(props.type, props.id, q.value)).people
}

let searchTimer = 0
watch(q, () => {
    window.clearTimeout(searchTimer)
    searchTimer = window.setTimeout(() => guard('', loadPeople), 250)
})

onMounted(async () => {
    await guard('', async () => {
        const [m, l] = await Promise.all([filesApi.meta(props.type, props.id), filesApi.links(props.type, props.id), loadPeople()])
        meta.value = m
        links.value = l.links
    })
    // Файл для Web Share — заздалегідь, щоб share() пішов одразу з натискання.
    if (nativeOk) {
        try {
            nativeFile.value = await prepareFile(props.fetchUrl, props.name, props.mime)
            nativeState.value = nativeFile.value ? 'ready' : 'none'
        } catch {
            nativeState.value = 'none'
        }
    }
})

function toggle(id: number) {
    picked.value = picked.value.includes(id) ? picked.value.filter((x) => x !== id) : [...picked.value, id]
}

function sendToPeople() {
    return guard('people', async () => {
        const r = await filesApi.share(props.type, props.id, picked.value, note.value)
        notice.value = `Надіслано: ${r.shared.join(', ')}.`
        picked.value = []
        note.value = ''
    })
}

function createLink() {
    return guard('link', async () => {
        fresh.value = await filesApi.createLink(props.type, props.id)
        links.value = [fresh.value, ...links.value]
        copied.value = false
    })
}

function revoke(link: ShareLink) {
    return guard('revoke-' + link.id, async () => {
        await filesApi.revoke(link.id)
        links.value = links.value.filter((l) => l.id !== link.id)
        if (fresh.value?.id === link.id) fresh.value = null
        notice.value = 'Посилання відкликано — воно більше не відкривається.'
    })
}

async function copy() {
    if (!fresh.value) return
    try {
        await navigator.clipboard.writeText(fresh.value.url)
        copied.value = true
    } catch {
        error.value = 'Не вдалось скопіювати — виділіть посилання вручну.'
    }
}

async function nativeShare() {
    if (!nativeFile.value) return
    try {
        const r = await shareFile(nativeFile.value)
        if (r === 'shared') notice.value = 'Файл передано.'
    } catch (e) {
        error.value = (e as Error).message || 'Телефон не зміг поділитися файлом.'
    }
}

async function shareLinkNatively() {
    if (!fresh.value) return
    try {
        await navigator.share({ url: fresh.value.url, title: props.name })
    } catch {
        /* скасували — нічого */
    }
}

const hasShareApi = typeof navigator.share === 'function'

function date(iso: string): string {
    return new Date(iso).toLocaleString('uk-UA', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
    <aside class="p-share" aria-label="Поділитися файлом">
        <header>
            <button type="button" class="back" aria-label="Назад до файлу" @click="emit('close')">←</button>
            <div class="ttl">
                <b>Поділитися</b>
                <span>{{ name }}</span>
            </div>
        </header>

        <div v-if="error" class="msg err" role="alert">{{ error }}</div>
        <div v-if="notice" class="msg ok" role="status">{{ notice }}</div>

        <section v-if="nativeState !== 'none'">
            <h3>Файлом з телефона</h3>
            <button type="button" class="btn primary wide" :disabled="nativeState !== 'ready'" @click="nativeShare">
                {{ nativeState === 'ready' ? '📤 Надіслати файл у Telegram, WhatsApp, Viber…' : 'Готуємо файл…' }}
            </button>
        </section>

        <section>
            <h3>Колегам у «Плані»</h3>
            <input v-model="q" type="search" class="inp" placeholder="Пошук людини" aria-label="Пошук людини" />
            <ul class="people">
                <li v-for="p in allowed" :key="p.id">
                    <label>
                        <input type="checkbox" :checked="picked.includes(p.id)" @change="toggle(p.id)" />
                        <span class="av" :style="{ background: p.color }">{{ p.initials }}</span>
                        <span class="nm">{{ p.name }}<small v-if="p.position || p.roleLabel">{{ p.position || p.roleLabel }}</small></span>
                    </label>
                </li>
                <li v-for="p in blocked" :key="'b' + p.id" class="off" :title="'Немає доступу до ' + (meta?.subject.label ?? 'файлу')">
                    <span class="av" :style="{ background: p.color }">{{ p.initials }}</span>
                    <span class="nm">{{ p.name }}<small>немає доступу</small></span>
                </li>
                <li v-if="!people.length && !busy" class="empty">Нікого не знайдено.</li>
            </ul>
            <textarea v-model="note" class="inp" rows="2" maxlength="500" placeholder="Коментар (необов'язково)" aria-label="Коментар"></textarea>
            <button type="button" class="btn primary wide" :disabled="!picked.length || busy === 'people'" @click="sendToPeople">
                Надіслати {{ picked.length ? `(${picked.length})` : '' }}
            </button>
        </section>

        <section>
            <h3>Посилання для людей поза «Планом»</h3>
            <p class="hint">Діє {{ ttl }} днів, його можна відкликати. Відкривається без входу — надсилайте лише тим, кому файл призначений.</p>
            <template v-if="fresh">
                <div class="url">
                    <input :value="fresh.url" readonly class="inp" aria-label="Посилання" @focus="($event.target as HTMLInputElement).select()" />
                    <button type="button" class="btn" @click="copy">{{ copied ? '✓' : 'Копіювати' }}</button>
                </div>
                <div class="msgr">
                    <a v-for="m in messengers" :key="m.cls" :class="['btn', m.cls]" :href="m.href" target="_blank" rel="noopener noreferrer">{{ m.label }}</a>
                    <button v-if="hasShareApi" type="button" class="btn" @click="shareLinkNatively">Ще…</button>
                </div>
            </template>
            <button v-else type="button" class="btn wide" :disabled="busy === 'link'" @click="createLink">🔗 Створити посилання на {{ ttl }} днів</button>

            <ul v-if="links.length" class="links">
                <li v-for="l in links" :key="l.id">
                    <span>{{ l.mine ? 'Моє' : l.createdBy }} · до {{ date(l.expiresAt) }} · скачано {{ l.downloads }}</span>
                    <button v-if="l.canRevoke" type="button" class="btn sm danger" :disabled="busy === 'revoke-' + l.id" @click="revoke(l)">Відкликати</button>
                </li>
            </ul>
        </section>

        <section v-if="meta">
            <h3>Google Диск</h3>
            <p class="hint">
                <template v-if="meta.drive.state === 'done'">Копія лежить в архіві на Диску.</template>
                <template v-else-if="meta.drive.state === 'failed'">Копія на Диск не вдалась — адміністратор бачить це на сторінці «Диск».</template>
                <template v-else-if="meta.drive.state === 'skipped'">На Диск не копіюється.</template>
                <template v-else>Копія на Диск у черзі.</template>
            </p>
            <a v-if="meta.drive.url" class="btn wide" :href="meta.drive.url" target="_blank" rel="noopener noreferrer">Відкрити в Google Drive</a>
        </section>
    </aside>
</template>

<style scoped>
.p-share { width: 380px; max-width: 100%; flex: none; background: var(--surface, #fff); color: var(--text, #1d2330); overflow-y: auto; display: flex; flex-direction: column; gap: 4px; padding-bottom: 24px; border-left: 1px solid rgba(0, 0, 0, .2); }
header { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-bottom: 1px solid var(--border, #e5e7eb); position: sticky; top: 0; background: inherit; z-index: 1; }
header .back { border: 0; background: var(--bg-soft, #f1f3f6); border-radius: 8px; width: 36px; height: 36px; font-size: 1.1rem; cursor: pointer; color: inherit; }
header .ttl { display: flex; flex-direction: column; min-width: 0; }
header .ttl span { font-size: .8rem; opacity: .7; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
section { padding: 10px 14px; display: flex; flex-direction: column; gap: 8px; }
h3 { margin: 4px 0 0; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; opacity: .65; }
.hint { margin: 0; font-size: .82rem; opacity: .75; }
.inp { width: 100%; box-sizing: border-box; border: 1px solid var(--border, #d1d5db); border-radius: 8px; padding: 8px 10px; font: inherit; background: var(--surface, #fff); color: inherit; }
.btn { border: 1px solid var(--border, #d1d5db); background: var(--surface, #fff); color: inherit; border-radius: 8px; padding: 8px 12px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; text-align: center; }
.btn.primary { background: var(--accent, #2563eb); border-color: transparent; color: #fff; }
.btn.danger { color: #b91c1c; }
.btn.sm { padding: 4px 8px; font-size: .8rem; }
.btn.wide { width: 100%; }
.btn:disabled { opacity: .55; cursor: default; }
.btn.tg { background: #229ed9; color: #fff; border-color: transparent; }
.btn.wa { background: #25d366; color: #fff; border-color: transparent; }
.btn.vb { background: #7360f2; color: #fff; border-color: transparent; }
.people { list-style: none; margin: 0; padding: 0; max-height: 240px; overflow-y: auto; border: 1px solid var(--border, #e5e7eb); border-radius: 8px; }
.people li, .people label { display: flex; align-items: center; gap: 8px; }
.people li { padding: 6px 8px; border-bottom: 1px solid var(--border, #f0f1f3); }
.people li:last-child { border-bottom: 0; }
.people label { flex: 1; cursor: pointer; }
.people li.off { opacity: .5; }
.people li.empty { justify-content: center; opacity: .6; font-size: .85rem; }
.av { width: 28px; height: 28px; border-radius: 50%; color: #fff; font-size: .72rem; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; flex: none; }
.nm { display: flex; flex-direction: column; font-size: .9rem; line-height: 1.2; }
.nm small { font-size: .74rem; opacity: .65; }
.url { display: flex; gap: 6px; }
.msgr { display: grid; grid-template-columns: repeat(auto-fit, minmax(90px, 1fr)); gap: 6px; }
.links { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; font-size: .82rem; }
.links li { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.msg { margin: 8px 14px 0; padding: 8px 10px; border-radius: 8px; font-size: .86rem; }
.msg.err { background: #fde8e8; color: #9b1c1c; }
.msg.ok { background: #e6f6ec; color: #116132; }
@media (max-width: 760px) {
    .p-share { width: 100%; border-left: 0; position: absolute; inset: 0; z-index: 5; }
}
</style>
