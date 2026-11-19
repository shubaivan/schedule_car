<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import PersonName from '../../plan/PersonName.vue'
import { notify } from '../../plan/undo'
import { useSession } from '../../app/store'
import { tasksApi, type TrashedTask } from './api'
import { TASK_SAVED } from './shell/nav'

/**
 * Кошик задач: видалені задачі 30 днів можна відновити — повертаються як були (статус, учасники,
 * чат, файли). Далі сервер видаляє їх остаточно (tasks:trash:purge). Бачать ті, хто може
 * видаляти задачі (право «Видаляє задачі» в «Ролі й доступ»), і лише задачі, які бачили б.
 */
const session = useSession()
const router = useRouter()
const items = ref<TrashedTask[] | null>(null)
const days = ref(30)
const error = ref('')
const busy = ref<number | null>(null)

const when = (value: string) => new Date(value).toLocaleString('uk-UA', { dateStyle: 'short', timeStyle: 'short' })
const until = (value: string) => new Date(value).toLocaleDateString('uk-UA', { day: 'numeric', month: 'long' })

async function load() {
    try {
        const data = await tasksApi.trash()
        items.value = data.items
        days.value = data.days
        error.value = ''
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function restore(t: TrashedTask) {
    busy.value = t.id
    try {
        await tasksApi.restore(t.id)
        items.value = (items.value ?? []).filter((x) => x.id !== t.id)
        window.dispatchEvent(new Event(TASK_SAVED))
        notify(`Задачу № ${t.number} відновлено`, {
            action: { label: 'Відкрити', run: () => void router.push({ name: 'tasks-board', params: { id: String(t.id) } }) },
        })
    } catch (e) {
        notify(session.handle(e), { tone: 'error' })
    } finally {
        busy.value = null
    }
}

onMounted(load)
</script>

<template>
    <div class="t-page p-tlist">
        <div class="p-tlist-head">
            <router-link :to="{ name: 'tasks-board' }" class="p-link">← Задачі</router-link>
            <h2 data-testid="trash-title">
                Кошик задач
                <span v-if="items" class="count">{{ items.length }}</span>
            </h2>
        </div>
        <div class="t-hint" style="padding: 0 0 8px">
            Видалені задачі лежать тут {{ days }} днів — їх можна відновити разом із чатом і файлами. Потім вони видаляються остаточно.
        </div>

        <div v-if="error" class="t-error">{{ error }}</div>
        <div v-else-if="!items" class="t-empty">Завантаження…</div>

        <table v-else class="t-table responsive" data-testid="trash-table">
            <thead>
                <tr>
                    <th>№</th>
                    <th>Задача</th>
                    <th>Хто видалив</th>
                    <th>Коли</th>
                    <th>Видалиться остаточно</th>
                    <th />
                </tr>
            </thead>
            <tbody>
                <tr v-for="t in items" :key="t.id">
                    <td>{{ t.number }}</td>
                    <td>
                        <strong>{{ t.title }}</strong>
                        <div class="t-hint" style="padding: 0">
                            {{ t.status.label }}
                            <template v-if="t.client">· {{ t.client }}</template>
                        </div>
                    </td>
                    <td>
                        <PersonName v-if="t.deletedBy" :person="t.deletedBy" />
                        <template v-else>—</template>
                    </td>
                    <td>{{ when(t.deletedAt) }}</td>
                    <td>{{ until(t.purgeAt) }}</td>
                    <td style="text-align: right">
                        <button type="button" class="t-btn small" :disabled="busy === t.id" @click="restore(t)">↩ Відновити</button>
                    </td>
                </tr>
                <tr v-if="!items.length"><td colspan="6" class="t-empty">Кошик порожній.</td></tr>
            </tbody>
        </table>
    </div>
</template>
