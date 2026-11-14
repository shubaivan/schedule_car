<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AvatarStack from './AvatarStack.vue'
import Icon from './shell/Icon.vue'
import { ago, dueLabel, STATUS_TONE } from './format'
import type { TaskItem } from './api'

/**
 * Щільна картка дошки, як у ПланФіксі: назва, контрагент, відділи-виконавці
 * «[3. Відділ продажу]», статус, термін, файли, аватари, «+N» непрочитаних.
 * Мітки — кольорова смужка зліва, а не таблетки. Клік відкриває задачу
 * праворуч (дошка не перезавантажується); Ctrl/середня кнопка — нова вкладка.
 */
const props = defineProps<{ task: TaskItem; showStatus?: boolean; selected?: boolean }>()
const emit = defineEmits<{ open: [id: number] }>()
const route = useRoute()
const router = useRouter()

const href = computed(() => router.resolve({ name: 'tasks-board', params: { id: props.task.id }, query: route.query }).href)
const stripe = computed(() => {
    const colors = props.task.labels.map((l) => l.color)
    if (!colors.length) return undefined
    if (colors.length === 1) return colors[0]
    const step = 100 / colors.length
    return `linear-gradient(${colors.map((c, i) => `${c} ${i * step}% ${(i + 1) * step}%`).join(', ')})`
})
const labelTitle = computed(() => props.task.labels.map((l) => l.name).join(', '))

function click(e: MouseEvent) {
    if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return
    e.preventDefault()
    emit('open', props.task.id)
}
</script>

<template>
    <a
        :href="href"
        class="p-card-task"
        :class="{ closed: task.closed, selected, unread: task.unread > 0, over: task.overdue }"
        :data-task="task.id"
        :aria-current="selected ? 'true' : undefined"
        @click="click"
    >
        <span v-if="stripe" class="stripe" :style="{ background: stripe }" :title="labelTitle" />
        <span class="row top">
            <span class="num">№ {{ task.number }}</span>
            <span v-if="task.fileStats.count" class="files" :title="task.fileStats.count + ' файлів' + (task.fileStats.pdf ? ', PDF: ' + task.fileStats.pdf : '')">
                <Icon :name="task.fileStats.pdf ? 'file' : task.fileStats.images ? 'image' : 'clip'" :size="12" :stroke="2" />{{ task.fileStats.count }}<span v-if="task.fileStats.pdf" class="pdf">PDF</span>
            </span>
            <span class="when" :title="'Активність: ' + new Date(task.lastActivityAt).toLocaleString('uk-UA')">{{ ago(task.lastActivityAt) }}</span>
            <span v-if="task.unread" class="badge" :title="task.unread + ' непрочитаних'">+{{ task.unread }}</span>
        </span>
        <span class="title">{{ task.title }}</span>
        <span v-if="task.client" class="client">{{ task.client }}</span>
        <span v-if="task.departmentNames.length" class="deps" :title="task.departmentNames.join(', ')">
            <span v-for="d in task.departmentNames.slice(0, 3)" :key="d" class="dep">[{{ d }}]</span>
            <span v-if="task.departmentNames.length > 3" class="dep more">+{{ task.departmentNames.length - 3 }}</span>
        </span>
        <span class="row foot">
            <span v-if="showStatus" class="st" :class="'tone-' + (STATUS_TONE[task.status] ?? 'slate')">{{ task.statusLabel }}</span>
            <span v-if="task.dueAt" class="due" :class="{ over: task.overdue && !task.closed }" :title="'Термін: ' + new Date(task.dueAt).toLocaleDateString('uk-UA')">
                <Icon name="clock" :size="13" :stroke="2" />{{ dueLabel(task.dueAt) }}
            </span>
            <AvatarStack class="people" :people="task.members" :total="task.membersCount" :max="2" :size="22" />
        </span>
    </a>
</template>
