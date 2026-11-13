<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import TaskCard from './TaskCard.vue'
import TaskForm from './TaskForm.vue'
import { tasksApi, type Board } from './api'
import { useTasks } from './store'
import { useSession } from '../../app/store'

/**
 * Дошка — колонки за відповіддю клієнта 13.11.2026:
 * Хроніка (що я відкривав) · Мої повідомлення (де я писав останнім або мене згадали)
 * · Нова заявка · В роботі · Виконана. На телефоні — вкладки замість колонок.
 */
const session = useSession()
const store = useTasks()
const board = ref<Board | null>(null)
const q = ref('')
const label = ref<number | null>(null)
const error = ref('')
const creating = ref(false)
const active = ref<keyof Board>('new')

const COLUMNS: { key: keyof Board; title: string; color: string; empty: string; status?: boolean }[] = [
    { key: 'chronicle', title: 'Хроніка', color: '#64748b', empty: 'Тут зʼявляться задачі, які ви відкривали.', status: true },
    { key: 'messages', title: 'Мої повідомлення', color: '#8b5cf6', empty: 'Тут задачі, де ви писали останнім або вас згадали.', status: true },
    { key: 'new', title: 'Нова заявка', color: '#0ea5e9', empty: 'Нових заявок немає.' },
    { key: 'work', title: 'В роботі', color: '#6366f1', empty: 'Нічого в роботі.' },
    { key: 'done', title: 'Виконана', color: '#16a34a', empty: 'Ще нічого не закрито.', status: true },
]

const unreadTotal = computed(() => (board.value ? board.value.chronicle.reduce((s, t) => s + t.unread, 0) : 0))

let debounce: number | undefined
async function load() {
    try {
        await store.load()
        board.value = await tasksApi.board(q.value.trim(), label.value)
    } catch (e) {
        error.value = session.handle(e)
    }
}

watch(q, () => {
    window.clearTimeout(debounce)
    debounce = window.setTimeout(load, 300)
})
watch(label, load)

let timer: number | undefined
onMounted(() => {
    load()
    timer = window.setInterval(() => !document.hidden && load(), 20000)
})
onBeforeUnmount(() => window.clearInterval(timer))
</script>

<template>
    <div class="t-page">
        <div class="t-head">
            <div>
                <h1>Угоди й задачі</h1>
                <div class="t-sub">Уся робота — у чаті задачі: повідомлення, фото, документи.<template v-if="unreadTotal"> Непрочитаних: {{ unreadTotal }}.</template></div>
            </div>
            <button type="button" class="t-btn primary" @click="creating = true">＋ Нова задача</button>
        </div>

        <div class="t-toolbar">
            <label class="t-search"><input v-model="q" class="t-input" placeholder="Пошук: назва, клієнт, № задачі" /></label>
            <div v-if="store.meta" class="t-chips">
                <button
                    v-for="l in store.meta.labels"
                    :key="l.id"
                    type="button"
                    class="t-chip"
                    :class="{ on: label === l.id }"
                    :style="label === l.id ? { background: l.color } : {}"
                    @click="label = label === l.id ? null : l.id"
                >
                    <span class="dot" :style="{ background: label === l.id ? '#fff' : l.color }" />{{ l.name }}
                </button>
            </div>
        </div>

        <div v-if="error" class="t-error">{{ error }}</div>
        <div v-if="!board" class="t-empty">Завантаження…</div>

        <template v-else>
            <div class="t-tabs">
                <button v-for="c in COLUMNS" :key="c.key" type="button" :class="{ on: active === c.key }" @click="active = c.key">
                    {{ c.title }}<span class="n">{{ board[c.key].length }}</span>
                </button>
            </div>
            <div class="t-board">
                <section v-for="c in COLUMNS" :key="c.key" class="t-col" :class="{ on: active === c.key }">
                    <div class="t-col-head">
                        <span class="bar" :style="{ background: c.color }" />{{ c.title }}
                        <span class="n">{{ board[c.key].length }}</span>
                    </div>
                    <div class="t-col-body">
                        <TaskCard v-for="t in board[c.key]" :key="t.id" :task="t" :show-status="c.status" />
                        <div v-if="!board[c.key].length" class="t-empty">{{ c.empty }}</div>
                    </div>
                </section>
            </div>
        </template>

        <TaskForm v-if="creating" @close="creating = false" />
    </div>
</template>
