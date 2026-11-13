<script setup lang="ts">
import AvatarStack from './AvatarStack.vue'
import LabelChip from './LabelChip.vue'
import StatusPill from './StatusPill.vue'
import { ago, dueLabel } from './format'
import type { TaskItem } from './api'

defineProps<{ task: TaskItem; showStatus?: boolean }>()
</script>

<template>
    <router-link :to="{ name: 'task', params: { id: task.id } }" class="t-card" :class="{ closed: task.closed }">
        <span v-if="task.unread" class="t-unread" :title="task.unread + ' непрочитаних'">{{ task.unread }}</span>
        <div class="t-card-top">
            <span class="num">№ {{ task.number }}</span>
            <StatusPill v-if="showStatus || task.column === 'work'" :status="task.status" :label="task.statusLabel" />
            <span style="margin-left: auto">{{ ago(task.lastActivityAt) }}</span>
        </div>
        <div class="t-card-title">{{ task.title }}</div>
        <div v-if="task.client" class="t-card-client">{{ task.client }}</div>
        <div v-if="task.labels.length" class="t-labels">
            <LabelChip v-for="l in task.labels" :key="l.id" :label="l" />
        </div>
        <div v-if="task.last" class="t-card-last">
            <div class="clamp">
                <b>{{ task.last.author }}:</b>
                {{ task.last.text || '' }}
                <template v-if="task.last.files"> 📎 {{ task.last.files }}</template>
            </div>
        </div>
        <div class="t-card-foot">
            <span v-if="task.dueAt" class="t-due" :class="{ over: task.overdue }">⏰ {{ dueLabel(task.dueAt) }}</span>
            <span v-else />
            <AvatarStack :people="task.members" :total="task.membersCount" :max="4" :size="24" />
        </div>
    </router-link>
</template>
