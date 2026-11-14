<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import FileViewer from '../tasks/FileViewer.vue'
import { call } from '../../app/api'
import type { Attachment } from '../tasks/api'
import { useSession } from '../../app/store'
import { goBack } from '../../plan/layers'

/**
 * Файл задачі на весь екран окремою сторінкою /files/:id — для посилань
 * (пошук, сповіщення, «поділитися»). «Назад» повертає, звідки прийшли;
 * прийшли за посиланням — «Закрити» веде до задачі цього файлу.
 */
const props = defineProps<{ id: string }>()
const session = useSession()
const router = useRouter()
const file = ref<Attachment | null>(null)
const task = ref<{ id: number; number: string; title: string } | null>(null)
const error = ref('')

onMounted(async () => {
    try {
        const r = await call<{ file: Attachment; task: { id: number; number: string; title: string } }>(`/api/tasks/files/${props.id}`)
        file.value = r.file
        task.value = r.task
    } catch (e) {
        error.value = session.handle(e) || 'Файл не знайдено.'
    }
})

function close() {
    goBack(router, task.value ? { name: 'tasks-board', params: { id: String(task.value.id) } } : { name: 'tasks' })
}
</script>

<template>
    <div class="p-filepage">
        <div v-if="error" class="t-panel t-empty">
            <p>{{ error }}</p>
            <button type="button" class="t-btn" @click="close">← Назад</button>
        </div>
        <div v-else-if="!file" class="t-empty">Завантаження…</div>
        <FileViewer v-else :files="[file]" :start-id="file.id" @close="close" />
    </div>
</template>
