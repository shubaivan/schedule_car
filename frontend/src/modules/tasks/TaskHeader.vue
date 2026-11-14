<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import AvatarStack from './AvatarStack.vue'
import Icon from './shell/Icon.vue'
import { dueLabel, STATUS_TONE } from './format'
import type { TaskDetail } from './api'

/**
 * Шапка задачі в два рядки (як у ПланФіксі): «№ · контрагент» → назва;
 * далі статус-кнопка зі списком переходів, термін, відділи, учасники.
 * Рішення директора — окремою жовтою смугою (наш плюс, лишаємо).
 */
const props = defineProps<{ task: TaskDetail; embedded?: boolean; moving?: boolean; sideOpen?: boolean }>()
const emit = defineEmits<{ move: [to: string, note?: string]; edit: []; close: []; side: [] }>()

const menu = ref(false)
const note = ref('')
const root = ref<HTMLElement | null>(null)

const transitions = computed(() => props.task.can.transitions)
const decisions = computed(() => transitions.value.filter((t) => t.decision))
const moves = computed(() => transitions.value.filter((t) => !t.decision))
const tone = computed(() => 'tone-' + (STATUS_TONE[props.task.status] ?? 'slate'))

function go(to: string) {
    menu.value = false
    emit('move', to)
}

function decide(to: string) {
    emit('move', to, note.value)
    note.value = ''
}

function outside(e: MouseEvent) {
    if (menu.value && root.value && !root.value.querySelector('.p-status')?.contains(e.target as Node)) menu.value = false
}
/** Esc спершу закриває меню статусу й бічну панель, а не всю задачу. */
function esc(e: KeyboardEvent) {
    if (e.key !== 'Escape') return
    if (menu.value) {
        menu.value = false
        e.preventDefault()
    } else if (props.sideOpen && !document.querySelector('.t-modal-wrap, .t-viewer')) {
        emit('side')
        e.preventDefault()
    }
}
onMounted(() => {
    document.addEventListener('click', outside)
    window.addEventListener('keydown', esc, true)
})
onBeforeUnmount(() => {
    document.removeEventListener('click', outside)
    window.removeEventListener('keydown', esc, true)
})
</script>

<template>
    <div ref="root" class="p-thead">
        <div class="p-thead-top">
            <button v-if="embedded" type="button" class="p-icon-btn p-back" title="Назад до дошки (Esc)" aria-label="Назад до дошки" @click="emit('close')"><Icon name="back" /></button>
            <router-link v-else :to="{ name: 'tasks-board' }" class="p-icon-btn p-back" title="До дошки" aria-label="До дошки"><Icon name="back" /></router-link>
            <span class="num">№ {{ task.number }}</span>
            <span v-if="task.client" class="client" :title="task.client">· {{ task.client }}</span>
            <span class="sp" />
            <button v-if="task.can.edit" type="button" class="p-icon-btn" title="Редагувати" aria-label="Редагувати" @click="emit('edit')"><Icon name="edit" :size="18" /></button>
            <button type="button" class="p-icon-btn p-side-toggle" :class="{ on: sideOpen }" title="Деталі, учасники, файли" aria-label="Деталі" @click="emit('side')"><Icon name="info" :size="18" /></button>
            <button v-if="embedded" type="button" class="p-icon-btn p-close" title="Закрити (Esc)" aria-label="Закрити" @click="emit('close')"><Icon name="x" /></button>
        </div>

        <h1 class="p-ttitle" :class="{ closed: task.closed }">{{ task.title }}</h1>

        <div class="p-thead-meta">
            <div class="p-status">
                <button
                    type="button"
                    class="p-status-btn"
                    :class="tone"
                    :disabled="!moves.length || moving"
                    :aria-expanded="menu"
                    :title="moves.length ? 'Змінити статус' : 'Статус'"
                    @click="menu = !menu"
                >
                    {{ task.statusLabel }}<Icon v-if="moves.length" name="down" :size="14" :stroke="2.2" />
                </button>
                <div v-if="menu" class="p-menu" role="menu">
                    <button v-for="t in moves" :key="t.value" type="button" role="menuitem" @click="go(t.value)">
                        <span class="dot" :class="'tone-' + (STATUS_TONE[t.value] ?? 'slate')" />{{ t.action }}
                    </button>
                </div>
            </div>
            <span v-if="task.dueAt" class="p-due" :class="{ over: task.overdue && !task.closed }" :title="new Date(task.dueAt).toLocaleDateString('uk-UA')">
                <Icon name="clock" :size="14" :stroke="2" />{{ dueLabel(task.dueAt) }}
            </span>
            <span v-for="d in task.departments" :key="d.id" class="p-dep">[{{ d.name }}]</span>
            <span v-for="l in task.labels" :key="l.id" class="p-lbl" :title="l.name"><span class="dot" :style="{ background: l.color }" />{{ l.name }}</span>
            <AvatarStack class="p-thead-people" :people="task.members" :max="5" :size="24" />
        </div>

        <div v-if="decisions.length" class="t-decision">
            <span>Рішення директора</span>
            <input v-model="note" class="t-input p-note" placeholder="Коментар до рішення (необовʼязково)" />
            <div class="t-actions">
                <button
                    v-for="d in decisions"
                    :key="d.value"
                    type="button"
                    class="t-btn small"
                    :class="d.value === 'approved' ? 'primary' : d.value === 'rejected' ? 'danger' : ''"
                    :disabled="moving"
                    @click="decide(d.value)"
                >{{ d.action }}</button>
            </div>
        </div>
        <div v-else-if="task.awaitsDecision" class="t-decision">Чекає рішення директора</div>
    </div>
</template>
