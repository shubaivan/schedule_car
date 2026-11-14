<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import type { PlanToast } from './notifications/store'

/**
 * Жовтий тост на всю ширину — важливі події (погодження, згадка, лід).
 * Не модалка: не блокує сторінку, не краде фокус; зникає сам за 15 с
 * (пауза, поки курсор на ньому), «Відкрити» веде на суб'єкт звичайним маршрутом.
 */
const props = defineProps<{ toasts: PlanToast[] }>()
const emit = defineEmits<{ dismiss: [id: number] }>()
const router = useRouter()
const timers = new Map<number, ReturnType<typeof setTimeout>>()
const LIFETIME = 15_000

function arm(id: number) {
    clearTimeout(timers.get(id))
    timers.set(id, setTimeout(() => close(id), LIFETIME))
}

function hold(id: number) {
    clearTimeout(timers.get(id))
    timers.delete(id)
}

function close(id: number) {
    hold(id)
    emit('dismiss', id)
}

function open(toast: PlanToast) {
    close(toast.id)
    const path = toast.url.startsWith('/crm/') ? toast.url.slice(4) : toast.url
    void router.push(path)
}

watch(
    () => props.toasts.map((t) => t.id),
    (ids) => ids.forEach((id) => timers.has(id) || arm(id)),
    { immediate: true },
)
onBeforeUnmount(() => timers.forEach((t) => clearTimeout(t)))
</script>

<template>
    <div class="p-toasts" aria-live="polite" aria-atomic="false">
        <div
            v-for="toast in toasts"
            :key="toast.id"
            class="p-toast"
            :class="{ important: toast.important }"
            role="status"
            data-testid="plan-toast"
            @mouseenter="hold(toast.id)"
            @mouseleave="arm(toast.id)"
        >
            <svg class="p-toast-ico" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0" /></svg>
            <div class="p-toast-text">
                <b>{{ toast.title }}</b>
                <span v-if="toast.detail">{{ toast.detail }}</span>
            </div>
            <button type="button" class="p-toast-open" @click="open(toast)">Відкрити</button>
            <button type="button" class="p-toast-x" aria-label="Закрити" @click="close(toast.id)">×</button>
        </div>
    </div>
</template>

<style>
.p-toasts { position: fixed; z-index: 60; top: 0; left: 0; right: 0; display: flex; flex-direction: column; gap: 1px; pointer-events: none; padding-top: env(safe-area-inset-top); }
.p-toast {
    pointer-events: auto; display: flex; align-items: center; gap: 12px; width: 100%; box-sizing: border-box; min-height: 52px;
    padding: 10px 16px; background: #fde68a; color: #422006; border-bottom: 1px solid #f59e0b;
    box-shadow: 0 6px 18px rgba(120, 53, 15, .18); font: 14px/1.35 var(--p-font, system-ui, sans-serif);
    animation: p-toast-in .18s ease-out;
}
.p-toast-ico { flex: none; color: #b45309; }
.p-toast-text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
.p-toast-text b { font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.p-toast-text span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #713f12; }
.p-toast-open { flex: none; height: 32px; padding: 0 14px; border: 0; border-radius: 8px; background: #78350f; color: #fff; font: 600 13.5px/1 var(--p-font, system-ui, sans-serif); cursor: pointer; }
.p-toast-x { flex: none; width: 32px; height: 32px; border: 0; border-radius: 8px; background: transparent; color: #713f12; font-size: 22px; line-height: 1; cursor: pointer; }
.p-toast-x:hover { background: rgba(120, 53, 15, .1); }
@keyframes p-toast-in { from { transform: translateY(-100%); } to { transform: none; } }
@media (prefers-reduced-motion: reduce) { .p-toast { animation: none; } }
@media (max-width: 760px) {
    .p-toast { padding: 8px 10px 8px 12px; gap: 8px; }
    .p-toast-text b, .p-toast-text span { white-space: normal; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .p-toast-open { padding: 0 10px; }
}
</style>
