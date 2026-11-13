<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import PeoplePicker from './PeoplePicker.vue'
import { tasksApi, type TaskDetail, type TasksMeta } from './api'
import { useTasks } from './store'
import { useSession } from '../../app/store'

/** Нова задача або правка існуючої — та сама форма в модалці. */
const props = defineProps<{ task?: TaskDetail | null }>()
const emit = defineEmits<{ close: []; saved: [TaskDetail] }>()

const store = useTasks()
const session = useSession()
const router = useRouter()
const meta = ref<TasksMeta | null>(null)
const busy = ref(false)
const error = ref('')
const files = ref<File[]>([])

const form = reactive({
    title: props.task?.title ?? '',
    client: props.task?.client ?? '',
    description: props.task?.description ?? '',
    dueAt: props.task?.dueAt ? props.task.dueAt.slice(0, 10) : '',
    labelIds: props.task?.labels.map((l) => l.id) ?? ([] as number[]),
    memberIds: props.task?.members.filter((m) => !m.via).map((m) => m.id) ?? ([] as number[]),
    departmentIds: props.task?.departments.map((d) => d.id) ?? ([] as number[]),
})

onMounted(async () => {
    meta.value = await store.load()
})

function toggleLabel(id: number) {
    form.labelIds = form.labelIds.includes(id) ? form.labelIds.filter((l) => l !== id) : [...form.labelIds, id]
}

function pick(e: Event) {
    const input = e.target as HTMLInputElement
    files.value = [...files.value, ...Array.from(input.files ?? [])]
    input.value = ''
}

async function save() {
    if (!form.title.trim()) {
        error.value = 'Вкажіть назву задачі.'
        return
    }
    busy.value = true
    error.value = ''
    try {
        const payload = { ...form }
        const saved = props.task ? await tasksApi.update(props.task.id, payload) : await tasksApi.create(payload)
        if (!props.task && files.value.length) {
            await tasksApi.post(saved.id, '', files.value, [])
        }
        await store.load(true)
        emit('saved', saved)
        if (!props.task) router.push({ name: 'task', params: { id: saved.id } })
        emit('close')
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="t-modal-wrap" @click.self="emit('close')">
        <form class="t-modal" @submit.prevent="save">
            <header>
                <h2>{{ task ? 'Редагувати задачу' : 'Нова задача' }}</h2>
                <button type="button" class="t-btn ghost icon" aria-label="Закрити" @click="emit('close')">✕</button>
            </header>
            <div v-if="meta" class="body">
                <div v-if="error" class="t-error">{{ error }}</div>
                <label class="t-field">
                    <span>Назва *</span>
                    <input v-model="form.title" class="t-input" placeholder="Напр.: Плити ПК 60-12 для ЖК «Сонячний»" autofocus />
                </label>
                <div class="t-two">
                    <label class="t-field">
                        <span>Контрагент</span>
                        <input v-model="form.client" class="t-input" placeholder="Клієнт, обʼєкт" />
                    </label>
                    <label class="t-field">
                        <span>Термін</span>
                        <input v-model="form.dueAt" type="date" class="t-input" />
                    </label>
                </div>
                <label class="t-field">
                    <span>Опис</span>
                    <textarea v-model="form.description" class="t-textarea" rows="3" placeholder="Що потрібно зробити" />
                </label>
                <div class="t-field">
                    <span>Мітки</span>
                    <div class="t-chips">
                        <button
                            v-for="l in meta.labels"
                            :key="l.id"
                            type="button"
                            class="t-chip"
                            :class="{ on: form.labelIds.includes(l.id) }"
                            :style="form.labelIds.includes(l.id) ? { background: l.color } : {}"
                            @click="toggleLabel(l.id)"
                        >
                            <span class="dot" :style="{ background: form.labelIds.includes(l.id) ? '#fff' : l.color }" />{{ l.name }}
                        </button>
                    </div>
                </div>
                <div class="t-field">
                    <span>Учасники — люди або цілі відділи</span>
                    <PeoplePicker v-model:members="form.memberIds" v-model:departments="form.departmentIds" :meta="meta" />
                </div>
                <div v-if="!task" class="t-field">
                    <span>Файли</span>
                    <label class="t-btn small" style="align-self: flex-start">
                        📎 Додати файли
                        <input type="file" multiple hidden @change="pick" />
                    </label>
                    <div v-if="files.length" class="t-hint">{{ files.map((f) => f.name).join(', ') }}</div>
                </div>
            </div>
            <div v-else class="body t-empty">Завантаження…</div>
            <footer>
                <button type="button" class="t-btn ghost" @click="emit('close')">Скасувати</button>
                <button type="submit" class="t-btn primary" :disabled="busy">{{ busy ? 'Зберігаємо…' : task ? 'Зберегти' : 'Створити задачу' }}</button>
            </footer>
        </form>
    </div>
</template>
