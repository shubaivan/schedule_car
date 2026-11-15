import { defineStore } from 'pinia'
import { ref } from 'vue'
import { tasksApi, type TasksMeta } from './api'
import { sortByName } from '../../plan/uk'

/** Довідники розділу «Задачі»: люди, відділи, мітки, статуси. */
export const useTasks = defineStore('tasks', () => {
    const meta = ref<TasksMeta | null>(null)
    let loading: Promise<TasksMeta> | null = null

    async function load(force = false): Promise<TasksMeta> {
        if (meta.value && !force) return meta.value
        if (!loading || force) {
            loading = tasksApi
                .meta()
                .then((m) => (meta.value = { ...m, people: sortByName(m.people) }))
                .finally(() => (loading = null))
        }
        return loading
    }

    const person = (id: number) => meta.value?.people.find((p) => p.id === id) ?? null

    return { meta, load, person }
})
