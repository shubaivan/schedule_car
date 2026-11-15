<script setup lang="ts">
import { dismiss, toasts } from './undo'

/**
 * Смуга тостів унизу екрана: не перекриває сторінку і не блокує її (не модалка),
 * зникає сама. Для скасування видалення та «файли не завантажились».
 */
</script>

<template>
    <div class="p-undo-toasts" aria-live="polite">
        <div v-for="t in toasts" :key="t.id" class="p-undo-toast" :class="t.tone">
            <span class="txt">{{ t.text }}</span>
            <button v-if="t.action" type="button" class="act" @click="t.action.run()">{{ t.action.label }}</button>
            <button type="button" class="x" aria-label="Сховати" @click="dismiss(t.id)">✕</button>
        </div>
    </div>
</template>

<style>
.p-undo-toasts {
    position: fixed;
    left: 50%;
    bottom: 16px;
    transform: translateX(-50%);
    z-index: 60;
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: min(520px, calc(100vw - 24px));
    pointer-events: none;
}
.p-undo-toast {
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px 10px 14px;
    border-radius: 12px;
    background: #1e293b;
    color: #f8fafc;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.25);
    font-size: 14px;
}
.p-undo-toast.error {
    background: #b91c1c;
}
.p-undo-toast .txt {
    flex: 1;
    min-width: 0;
}
.p-undo-toast .act {
    border: 0;
    background: none;
    color: #93c5fd;
    font: 700 14px/1 inherit;
    cursor: pointer;
    padding: 6px 4px;
    white-space: nowrap;
}
.p-undo-toast.error .act {
    color: #fff;
    text-decoration: underline;
}
.p-undo-toast .x {
    border: 0;
    background: none;
    color: inherit;
    opacity: 0.6;
    cursor: pointer;
    font-size: 13px;
    padding: 4px;
}
@media (max-width: 760px) {
    .p-undo-toasts {
        bottom: calc(var(--p-tabbar, 0px) + 12px + env(safe-area-inset-bottom));
    }
    .p-shell.task-open ~ .p-undo-toasts,
    .layout:not(.plan) .p-undo-toasts {
        bottom: calc(12px + env(safe-area-inset-bottom));
    }
}
</style>
