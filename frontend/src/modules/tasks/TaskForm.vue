<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import Avatar from './Avatar.vue'
import PeoplePicker from './PeoplePicker.vue'
import Icon from './shell/Icon.vue'
import { TASK_SAVED } from './shell/nav'
import { tasksApi, type TaskDetail, type TasksMeta } from './api'
import { useTasks } from './store'
import { useSession } from '../../app/store'

/**
 * Нова задача — коротка форма, як у ПланФіксі: назва, виконавці (люди або
 * відділи), термін, контрагент. «Більше полів» — опис, мітки, файли.
 * «Створити й відкрити» (Ctrl+Enter) або «Створити». Чернетка нової задачі
 * живе в браузері, поки її не створили або не скасували.
 * Правка існуючої — та сама форма, одразу розгорнута.
 *
 * Не модалка: форму показує сторінка (/tasks/new, /tasks/:id/edit — TaskFormPage),
 * вона ж вирішує, куди йти після збереження чи «Скасувати».
 */
const props = defineProps<{ task?: TaskDetail | null }>()
/** saved: задача, «відкрити її», попередження (напр. файли не завантажились) — для банера на сторінці задачі. */
const emit = defineEmits<{ close: []; saved: [task: TaskDetail, open: boolean, warning: string | null] }>()

const DRAFT = 'plan.task-draft'
const store = useTasks()
const session = useSession()
const meta = ref<TasksMeta | null>(null)
const busy = ref(false)
const error = ref('')
const files = ref<File[]>([])
const more = ref(!!props.task)
const picking = ref(false)
const titleBox = ref<HTMLInputElement | null>(null)

const empty = { title: '', client: '', description: '', dueAt: '', labelIds: [] as number[], memberIds: [] as number[], departmentIds: [] as number[] }

function draft(): typeof empty | null {
    if (props.task) return null
    try {
        const raw = localStorage.getItem(DRAFT)
        return raw ? { ...empty, ...(JSON.parse(raw) as Partial<typeof empty>) } : null
    } catch {
        return null
    }
}

const form = reactive(
    props.task
        ? {
              title: props.task.title,
              client: props.task.client ?? '',
              description: props.task.description ?? '',
              dueAt: props.task.dueAt ? props.task.dueAt.slice(0, 10) : '',
              labelIds: props.task.labels.map((l) => l.id),
              memberIds: props.task.members.filter((m) => !m.via).map((m) => m.id),
              departmentIds: props.task.departments.map((d) => d.id),
          }
        : (draft() ?? { ...empty, labelIds: [], memberIds: [], departmentIds: [] }),
)
if (!props.task && (form.description || form.labelIds.length)) more.value = true

watch(form, (value) => {
    if (props.task) return
    try {
        localStorage.setItem(DRAFT, JSON.stringify(value))
    } catch {
        /* приватне вікно — без чернетки */
    }
}, { deep: true })

function dropDraft() {
    try {
        localStorage.removeItem(DRAFT)
    } catch {
        /* нічого */
    }
}

const chosen = computed(() => {
    if (!meta.value) return { deps: [], people: [] }
    return {
        deps: meta.value.departments.filter((d) => form.departmentIds.includes(d.id)),
        people: meta.value.people.filter((p) => form.memberIds.includes(p.id)),
    }
})

/** Швидкі терміни: сьогодні, завтра, через 3 дні, через тиждень. */
function iso(days: number): string {
    const d = new Date()
    d.setDate(d.getDate() + days)
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const QUICK = [
    { label: 'Сьогодні', days: 0 },
    { label: 'Завтра', days: 1 },
    { label: '3 дні', days: 3 },
    { label: 'Тиждень', days: 7 },
]

function toggleLabel(id: number) {
    form.labelIds = form.labelIds.includes(id) ? form.labelIds.filter((l) => l !== id) : [...form.labelIds, id]
}

function pick(e: Event) {
    const input = e.target as HTMLInputElement
    files.value = [...files.value, ...Array.from(input.files ?? [])]
    input.value = ''
    more.value = true
}

async function save(go: boolean) {
    if (busy.value) return
    if (!form.title.trim()) {
        error.value = 'Вкажіть назву задачі.'
        titleBox.value?.focus()
        return
    }
    busy.value = true
    error.value = ''
    try {
        const payload = { ...form }
        const saved = props.task ? await tasksApi.update(props.task.id, payload) : await tasksApi.create(payload)
        // Задача вже є: чернетку прибираємо одразу, щоб повтор не створив дубль.
        if (!props.task) dropDraft()
        let warning: string | null = null
        if (!props.task && files.value.length) {
            try {
                await tasksApi.post(saved.id, '', files.value, [])
            } catch (e) {
                warning = `Задачу створено, але ${files.value.length > 1 ? 'файли' : 'файл'} не завантажились: ${session.handle(e)} Додайте їх у чат задачі ще раз.`
            }
        }
        await store.load(true)
        window.dispatchEvent(new Event(TASK_SAVED))
        emit('saved', saved, !props.task && go, warning)
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        busy.value = false
    }
}

function cancel() {
    if (!props.task) dropDraft()
    emit('close')
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault()
        save(true)
    } else if (e.key === 'Escape') {
        e.preventDefault()
        emit('close')
    }
}

onMounted(async () => {
    try {
        meta.value = await store.load()
    } catch (e) {
        // Обірваний запит довідників — показуємо помилку, а не необроблений reject.
        error.value = session.handle(e)
        return
    }
    await nextTick()
    titleBox.value?.focus()
})
</script>

<template>
    <form class="t-formcard p-form p-form-page" aria-labelledby="p-form-title" @submit.prevent="save(!task)" @keydown="onKey">
            <header>
                <button type="button" class="p-icon-btn p-form-back" aria-label="Назад" title="Назад (Esc)" @click="emit('close')"><Icon name="back" /></button>
                <h2 id="p-form-title">{{ task ? 'Редагувати задачу' : 'Нова задача' }}</h2>
                <button type="button" class="p-icon-btn p-form-x" aria-label="Закрити" title="Закрити (Esc)" @click="emit('close')"><Icon name="x" /></button>
            </header>
            <div v-if="meta" class="body">
                <div v-if="error" class="t-error">{{ error }}</div>
                <input
                    ref="titleBox"
                    v-model="form.title"
                    class="p-title-input"
                    placeholder="Назва: напр. «Кільця КС 10-9 — 40 шт, с. Ракове»"
                    aria-label="Назва задачі"
                    maxlength="255"
                />

                <div class="p-frow">
                    <span class="k"><Icon name="users" :size="16" />Виконавці</span>
                    <div class="v">
                        <span v-for="d in chosen.deps" :key="'d' + d.id" class="p-dep">[{{ d.name }}]</span>
                        <span v-for="p in chosen.people" :key="p.id" class="p-person-chip"><Avatar :person="p" :size="18" />{{ p.name }}</span>
                        <button type="button" class="p-link" @click="picking = !picking">{{ chosen.deps.length || chosen.people.length ? (picking ? 'Готово' : 'Змінити') : 'Додати відділ або людей' }}</button>
                    </div>
                </div>
                <PeoplePicker v-if="picking" v-model:members="form.memberIds" v-model:departments="form.departmentIds" :meta="meta" />

                <div class="p-frow">
                    <span class="k"><Icon name="calendar" :size="16" />Термін</span>
                    <div class="v">
                        <button
                            v-for="qd in QUICK"
                            :key="qd.days"
                            type="button"
                            class="p-chip"
                            :class="{ on: form.dueAt === iso(qd.days) }"
                            @click="form.dueAt = form.dueAt === iso(qd.days) ? '' : iso(qd.days)"
                        >{{ qd.label }}</button>
                        <input v-model="form.dueAt" type="date" class="p-date" aria-label="Термін" />
                    </div>
                </div>

                <div class="p-frow">
                    <span class="k"><Icon name="building" :size="16" />Контрагент</span>
                    <div class="v"><input v-model="form.client" class="p-inline-input" placeholder="Клієнт, обʼєкт" aria-label="Контрагент" /></div>
                </div>

                <button v-if="!more" type="button" class="p-more" @click="more = true"><Icon name="down" :size="16" />Більше полів: опис, мітки, файли</button>
                <template v-else>
                    <label class="t-field">
                        <span>Опис</span>
                        <textarea v-model="form.description" class="t-textarea" rows="3" placeholder="Що потрібно зробити" />
                    </label>
                    <div class="t-field">
                        <span>Мітки</span>
                        <div class="p-chips wrap">
                            <button
                                v-for="l in meta.labels"
                                :key="l.id"
                                type="button"
                                class="p-chip"
                                :class="{ on: form.labelIds.includes(l.id) }"
                                :style="form.labelIds.includes(l.id) ? { background: l.color, borderColor: l.color } : {}"
                                @click="toggleLabel(l.id)"
                            >
                                <span class="dot" :style="{ background: form.labelIds.includes(l.id) ? '#fff' : l.color }" />{{ l.name }}
                            </button>
                        </div>
                    </div>
                    <div v-if="!task" class="t-field">
                        <span>Файли</span>
                        <label class="p-btn" style="align-self: flex-start">
                            <Icon name="clip" :size="16" />Додати файли
                            <input type="file" multiple hidden @change="pick" />
                        </label>
                        <div v-if="files.length" class="t-hint">{{ files.map((f) => f.name).join(', ') }}</div>
                    </div>
                </template>
            </div>
            <div v-else class="body t-empty">Завантаження…</div>
            <footer>
                <span class="p-hint-keys">Ctrl+Enter — створити й відкрити</span>
                <button type="button" class="p-btn ghost" @click="cancel">Скасувати</button>
                <template v-if="task">
                    <button type="submit" class="p-btn primary" :disabled="busy">{{ busy ? 'Зберігаємо…' : 'Зберегти' }}</button>
                </template>
                <template v-else>
                    <button type="button" class="p-btn" :disabled="busy" @click="save(false)">Створити</button>
                    <button type="submit" class="p-btn primary" :disabled="busy">{{ busy ? 'Створюємо…' : 'Створити й відкрити' }}</button>
                </template>
            </footer>
    </form>
</template>
