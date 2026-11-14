<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FileViewer from '../tasks/FileViewer.vue'
import type { Attachment } from '../tasks/api'
import { useSession } from '../../app/store'
import { goBack } from '../../plan/layers'
import { filesApi, type FileType, type PlanFileMeta } from '../../plan/files'

/**
 * Файл на весь екран окремою сторінкою — для посилань (пошук, сповіщення
 * «поділились файлом», сторінка «Диск»):
 *   /files/:id          — файл задачі (так посилання вже ходять);
 *   /files/:type/:id    — документ заявки (supply) чи складу (wh).
 * ?share=1 — одразу з панеллю «Поділитися». «Назад» повертає, звідки прийшли;
 * прийшли за посиланням — «Закрити» веде до задачі / заявки цього файлу.
 */
const props = defineProps<{ id: string; type?: FileType }>()
const session = useSession()
const route = useRoute()
const router = useRouter()
const meta = ref<PlanFileMeta | null>(null)
const error = ref('')
const type = computed<FileType>(() => props.type ?? 'task')

onMounted(async () => {
    try {
        meta.value = await filesApi.meta(type.value, Number(props.id))
    } catch (e) {
        error.value = session.handle(e) || 'Файл не знайдено або він вам недоступний.'
    }
})

function close() {
    const target = meta.value?.subject.route
    goBack(router, target ? { name: target.name, params: target.params } : { name: 'tasks' })
}
</script>

<template>
    <div class="p-filepage">
        <div v-if="error" class="t-panel t-empty">
            <p>{{ error }}</p>
            <button type="button" class="t-btn" @click="close">← Назад</button>
        </div>
        <div v-else-if="!meta" class="t-empty">Завантаження…</div>
        <FileViewer v-else :files="[meta.file as Attachment]" :start-id="meta.file.id" :share-open="route.query.share === '1'" @close="close" />
    </div>
</template>
