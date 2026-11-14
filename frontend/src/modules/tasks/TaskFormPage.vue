<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import TaskForm from './TaskForm.vue'
import { tasksApi, type TaskDetail } from './api'
import { useSession } from '../../app/store'
import { goBack } from '../../plan/layers'
import { putFlash } from '../../plan/flash'
import { notify } from '../../plan/undo'

/**
 * Нова задача (/tasks/new) і правка (/tasks/:id/edit) — окремою сторінкою, не модалкою:
 * «Назад» у браузері чи на телефоні закриває форму, адресу можна переслати.
 * «Створити й відкрити» замінює форму в історії задачею, щоб «Назад» із задачі
 * вів туди, звідки людина почала, а не знову у форму.
 */
const props = defineProps<{ id?: string }>()
const session = useSession()
const router = useRouter()
const task = ref<TaskDetail | null>(null)
const error = ref('')

onMounted(async () => {
    if (!props.id) return
    try {
        task.value = await tasksApi.get(Number(props.id))
    } catch (e) {
        error.value = session.handle(e)
    }
})

function close() {
    goBack(router, props.id ? { name: 'tasks-board', params: { id: props.id } } : { name: 'tasks-board' })
}

function saved(t: TaskDetail, open: boolean, warning: string | null) {
    if (props.id) {
        close()
        return
    }
    if (open) {
        if (warning) putFlash('task-' + t.id, warning)
        router.replace({ name: 'tasks-board', params: { id: String(t.id) } })
        return
    }
    if (warning) notify(warning, { tone: 'error', ms: 12000, action: { label: 'Відкрити', run: () => router.push({ name: 'tasks-board', params: { id: String(t.id) } }) } })
    else notify(`Задачу № ${t.number} створено`, { action: { label: 'Відкрити', run: () => router.push({ name: 'tasks-board', params: { id: String(t.id) } }) } })
    close()
}
</script>

<template>
    <div class="p-formpage">
        <div v-if="error" class="t-error">{{ error }}</div>
        <TaskForm v-if="!id || task" :key="id ?? 'new'" :task="task" @close="close" @saved="saved" />
        <div v-else-if="!error" class="t-empty">Завантаження…</div>
    </div>
</template>
