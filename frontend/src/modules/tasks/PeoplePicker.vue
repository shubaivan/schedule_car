<script setup lang="ts">
import PersonName from '../../plan/PersonName.vue'
import { people as peopleLabel } from '../../plan/uk'
import { computed, ref } from 'vue'
import Avatar from './Avatar.vue'
import type { TasksMeta } from './api'

/**
 * Учасники задачі: люди й відділи. Обрав відділ — у задачі всі його люди
 * (логіка ПланФікса, вимога клієнта 13.11.2026).
 */
const props = defineProps<{ meta: TasksMeta; members: number[]; departments: number[] }>()
const emit = defineEmits<{ 'update:members': [number[]]; 'update:departments': [number[]] }>()

const tab = ref<'people' | 'deps'>('people')
const q = ref('')

const people = computed(() => {
    const s = q.value.trim().toLowerCase()
    return props.meta.people.filter((p) => !s || p.name.toLowerCase().includes(s) || (p.position ?? '').toLowerCase().includes(s) || (p.department ?? '').toLowerCase().includes(s))
})
const deps = computed(() => {
    const s = q.value.trim().toLowerCase()
    return props.meta.departments.filter((d) => !s || d.name.toLowerCase().includes(s))
})
const chosenPeople = computed(() => props.meta.people.filter((p) => props.members.includes(p.id)))
const chosenDeps = computed(() => props.meta.departments.filter((d) => props.departments.includes(d.id)))
const viaDeps = computed(() => new Set(chosenDeps.value.flatMap((d) => d.people)))

function togglePerson(id: number) {
    emit('update:members', props.members.includes(id) ? props.members.filter((m) => m !== id) : [...props.members, id])
}
function toggleDep(id: number) {
    emit('update:departments', props.departments.includes(id) ? props.departments.filter((d) => d !== id) : [...props.departments, id])
}
</script>

<template>
    <div class="t-picker">
        <div class="chosen">
            <span v-if="!chosenPeople.length && !chosenDeps.length" class="t-hint">Нікого не обрано — задачу бачитимете ви й керівництво.</span>
            <span v-for="d in chosenDeps" :key="'d' + d.id" class="c dep">
                🏢 {{ d.name }} · {{ d.people.length }}
                <button type="button" aria-label="Прибрати" @click="toggleDep(d.id)">✕</button>
            </span>
            <span v-for="p in chosenPeople" :key="p.id" class="c">
                <Avatar :person="p" :size="20" />
                <PersonName :person="p" :link="false" />
                <button type="button" aria-label="Прибрати" @click="togglePerson(p.id)">✕</button>
            </span>
        </div>
        <div class="tabs">
            <button type="button" :class="{ on: tab === 'people' }" @click="tab = 'people'">Люди</button>
            <button type="button" :class="{ on: tab === 'deps' }" @click="tab = 'deps'">Відділи</button>
        </div>
        <input v-model="q" class="t-input search" placeholder="Пошук: імʼя, посада, відділ" />
        <div class="list">
            <template v-if="tab === 'people'">
                <button v-for="p in people" :key="p.id" type="button" class="item" :class="{ on: members.includes(p.id) || viaDeps.has(p.id) }" @click="togglePerson(p.id)">
                    <Avatar :person="p" :size="30" />
                    <span class="grow">
                        <PersonName :person="p" :link="false" />
                        <small>{{ p.position ?? p.roleLabel }} · {{ p.department ?? 'без відділу' }}</small>
                    </span>
                    <span v-if="members.includes(p.id)">✓</span>
                    <small v-else-if="viaDeps.has(p.id)">через відділ</small>
                </button>
            </template>
            <template v-else>
                <button v-for="d in deps" :key="d.id" type="button" class="item" :class="{ on: departments.includes(d.id) }" @click="toggleDep(d.id)">
                    <span class="t-ava" :style="{ width: '30px', height: '30px', fontSize: '12px', background: '#8b5cf6' }">{{ d.name.replace(/^\d+\.\s*/, '').charAt(0) }}</span>
                    <span class="grow">
                        {{ d.name }}
                        <small>{{ peopleLabel(d.people.length) }} — {{ d.people.length === 1 ? 'додасться' : 'додадуться всі' }}</small>
                    </span>
                    <span v-if="departments.includes(d.id)">✓</span>
                </button>
            </template>
        </div>
    </div>
</template>
