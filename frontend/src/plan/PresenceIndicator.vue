<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId } from 'vue'
import { usePresence } from './presence'
import './presence.css'

/**
 * Кружечок «хто зараз у Плані» біля пошуку (прохання Олександра 15.11 21:35).
 * Число — скільки людей зараз у Плані (зі мною). Наведення (миша) чи натискання —
 * список: хто, у якому розділі, телефон (якщо його видно цій людині). Heartbeat
 * веде оболонка (PlanShell → usePresence().start), тут лише показ.
 */
const props = withDefaults(defineProps<{ compact?: boolean }>(), { compact: false })
const store = usePresence()
const open = ref(false)
/** Відкрито натисканням — не ховати, коли миша пішла. */
const pinned = ref(false)
const root = ref<HTMLElement | null>(null)
const panelId = `presence-${useId()}`
let hoverTimer: ReturnType<typeof setTimeout> | undefined

const label = computed(() => (store.count > 99 ? '99+' : String(store.count)))
const aria = computed(() => `Зараз у Плані: ${store.count} ${plural(store.count)}. Показати, хто саме`)
const others = computed(() => store.people.filter((p) => !p.me).length)

function plural(n: number): string {
    const d = n % 10
    const dd = n % 100
    if (d === 1 && dd !== 11) return 'людина'
    if (d >= 2 && d <= 4 && (dd < 12 || dd > 14)) return 'людини'
    return 'людей'
}

function show(): void {
    if (open.value) return
    open.value = true
    void store.refresh()
    document.addEventListener('pointerdown', outside, true)
    document.addEventListener('keydown', onKey)
}

function hide(): void {
    open.value = false
    pinned.value = false
    document.removeEventListener('pointerdown', outside, true)
    document.removeEventListener('keydown', onKey)
}

function toggle(): void {
    if (open.value && pinned.value) return hide()
    pinned.value = true
    show()
}

function outside(e: Event): void {
    if (root.value && !root.value.contains(e.target as Node)) hide()
}

/** Клавіатура: фокус пішов за межі кружечка й списку — ховаємо. */
function focusOut(e: FocusEvent): void {
    if (open.value && root.value && !root.value.contains(e.relatedTarget as Node | null)) hide()
}

function onKey(e: KeyboardEvent): void {
    if (e.key === 'Escape') hide()
}

/** Наведення — лише для миші (на дотику «hover» приходить разом із натисканням). */
const hoverable = () => window.matchMedia('(hover: hover) and (pointer: fine)').matches

function enter(): void {
    if (props.compact || !hoverable()) return
    clearTimeout(hoverTimer)
    hoverTimer = setTimeout(show, 150)
}

function leave(): void {
    clearTimeout(hoverTimer)
    if (props.compact || !hoverable() || pinned.value) return
    hoverTimer = setTimeout(hide, 250)
}

onBeforeUnmount(() => {
    clearTimeout(hoverTimer)
    hide()
})
</script>

<template>
    <div ref="root" class="p-presence" :class="{ compact, open }" data-testid="presence" @mouseenter="enter" @mouseleave="leave" @focusout="focusOut">
        <button
            type="button"
            class="p-presence-btn"
            :aria-label="aria"
            :aria-expanded="open"
            :aria-controls="open ? panelId : undefined"
            data-testid="presence-btn"
            @click="toggle"
        >
            <span class="p-presence-dot" aria-hidden="true" />
            <b data-testid="presence-count">{{ store.ready ? label : '·' }}</b>
        </button>

        <section v-if="open" :id="panelId" class="p-presence-panel" aria-label="Хто зараз у Плані" data-testid="presence-panel">
            <header>
                <strong>Зараз у Плані</strong>
                <small>активні за останні 3 хвилини</small>
            </header>
            <ul v-if="store.people.length">
                <li v-for="p in store.people" :key="p.id" :class="{ me: p.me }" data-testid="presence-row">
                    <span class="p-presence-ava" :style="{ background: p.color }" aria-hidden="true">{{ p.initials }}</span>
                    <span class="p-presence-who">
                        <span class="p-presence-name">
                            <b>{{ p.name }}</b>
                            <em v-if="p.me" class="p-presence-me">ти</em>
                        </span>
                        <small>
                            <span class="p-presence-section">{{ p.sectionLabel }}</span>
                            <template v-if="p.position">{{ ' · ' + p.position }}</template>
                        </small>
                    </span>
                    <template v-if="p.phone && !p.me">
                        <a v-if="p.tel" class="p-presence-phone" :href="'tel:' + p.tel" :aria-label="'Подзвонити: ' + p.name">{{ p.phone }}</a>
                        <span v-else class="p-presence-phone">{{ p.phone }}</span>
                    </template>
                </li>
            </ul>
            <p v-else class="p-presence-empty">{{ store.ready ? 'Зараз нікого немає.' : 'Завантаження…' }}</p>
            <footer v-if="store.ready && store.people.length && !others" class="p-presence-empty">Крім тебе, зараз нікого.</footer>
        </section>
    </div>
</template>
