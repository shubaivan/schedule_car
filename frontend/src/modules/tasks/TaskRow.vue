<script setup lang="ts">
import AvatarStack from './AvatarStack.vue'
import LabelChip from './LabelChip.vue'
import StatusPill from './StatusPill.vue'
import { ago, dueLabel } from './format'
import type { TaskItem } from './api'

defineProps<{ task: TaskItem }>()
</script>

<template>
    <router-link :to="{ name: 'task', params: { id: task.id } }" class="t-row">
        <span class="title">
            <span v-if="task.unread" class="t-status tone-rose" style="margin-right: 0.35rem; padding: 0 0.45rem">{{ task.unread }}</span>
            {{ task.title }}
        </span>
        <AvatarStack :people="task.members" :total="task.membersCount" :max="3" :size="22" />
        <span class="meta">
            <span>№ {{ task.number }}</span>
            <StatusPill :status="task.status" :label="task.statusLabel" />
            <span v-if="task.dueAt" class="t-due" :class="{ over: task.overdue }">⏰ {{ dueLabel(task.dueAt) }}</span>
            <LabelChip v-for="l in task.labels.slice(0, 2)" :key="l.id" :label="l" />
            <span v-if="task.client">{{ task.client }}</span>
            <span style="margin-left: auto">{{ ago(task.lastActivityAt) }}</span>
        </span>
    </router-link>
</template>
