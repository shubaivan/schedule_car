<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import TaskForm from './TaskForm.vue'
import TaskRow from './TaskRow.vue'
import { tasksApi, type Dashboard } from './api'
import { today } from './format'
import { useTasks } from './store'
import { useSession } from '../../app/store'

/** «Мій день»: що на мені, що горить, що в моєму відділі, що чекає мого рішення. */
const session = useSession()
const store = useTasks()
const data = ref<Dashboard | null>(null)
const error = ref('')
const creating = ref(false)
let timer: number | undefined

async function load() {
    try {
        await store.load()
        data.value = await tasksApi.dashboard()
    } catch (e) {
        error.value = session.handle(e)
    }
}

const hello = () => {
    const h = new Date().getHours()
    return h < 12 ? 'Доброго ранку' : h < 18 ? 'Добрий день' : 'Добрий вечір'
}

onMounted(() => {
    load()
    timer = window.setInterval(() => !document.hidden && load(), 30000)
})
onBeforeUnmount(() => window.clearInterval(timer))
</script>

<template>
    <div class="t-page">
        <div class="t-head">
            <div>
                <h1>{{ hello() }}, {{ store.meta?.me.name.split(' ')[0] ?? session.user?.firstName }}</h1>
                <div class="t-sub">{{ today() }} · {{ store.meta?.me.position ?? store.meta?.me.roleLabel }}<template v-if="store.meta?.me.department"> · {{ store.meta?.me.department }}</template></div>
            </div>
            <button type="button" class="t-btn primary" @click="creating = true">＋ Нова задача</button>
        </div>

        <div v-if="error" class="t-error">{{ error }}</div>
        <div v-if="!data" class="t-empty">Завантаження…</div>

        <template v-else>
            <div class="t-stats">
                <router-link :to="{ name: 'tasks-board' }" class="t-stat"><span class="v">{{ data.stats.mine }}</span><span class="k">Мої відкриті</span></router-link>
                <router-link :to="{ name: 'tasks-board' }" class="t-stat" :class="{ alert: data.stats.overdue }"><span class="v">{{ data.stats.overdue }}</span><span class="k">Прострочені</span></router-link>
                <router-link :to="{ name: 'tasks-board' }" class="t-stat" :class="{ alert: data.stats.unread }"><span class="v">{{ data.stats.unread }}</span><span class="k">Непрочитані</span></router-link>
                <router-link v-if="store.meta?.me.can.decide" :to="{ name: 'tasks-board' }" class="t-stat" :class="{ warn: data.stats.decisions }"><span class="v">{{ data.stats.decisions }}</span><span class="k">Чекають рішення</span></router-link>
                <router-link v-else :to="{ name: 'tasks-board' }" class="t-stat"><span class="v">{{ data.stats.open }}</span><span class="k">Відкриті · усього</span></router-link>
            </div>

            <section v-if="data.decisions.length" class="t-panel t-decide">
                <h2>⚖️ Чекають вашого рішення <span class="count">{{ data.decisions.length }}</span></h2>
                <div class="t-rows"><TaskRow v-for="t in data.decisions" :key="t.id" :task="t" /></div>
            </section>

            <div class="t-grid">
                <section class="t-panel">
                    <h2>📌 Мої задачі <span class="count">{{ data.mine.length }}</span></h2>
                    <div v-if="data.mine.length" class="t-rows"><TaskRow v-for="t in data.mine" :key="t.id" :task="t" /></div>
                    <div v-else class="t-empty">Відкритих задач немає 🎉</div>
                </section>
                <section class="t-panel">
                    <h2>🔥 Прострочені <span class="count">{{ data.overdue.length }}</span></h2>
                    <div v-if="data.overdue.length" class="t-rows"><TaskRow v-for="t in data.overdue" :key="t.id" :task="t" /></div>
                    <div v-else class="t-empty">Усе в строк.</div>
                </section>
                <section class="t-panel">
                    <h2>🏢 {{ data.departmentName ?? 'Мій відділ' }} <span class="count">{{ data.department.length }}</span></h2>
                    <div v-if="data.department.length" class="t-rows"><TaskRow v-for="t in data.department" :key="t.id" :task="t" /></div>
                    <div v-else class="t-empty">{{ data.departmentName ? 'У відділу відкритих задач немає.' : 'Вас ще не додали до відділу.' }}</div>
                </section>
                <section class="t-panel">
                    <h2>🕘 Остання активність</h2>
                    <div v-if="data.recent.length" class="t-rows"><TaskRow v-for="t in data.recent" :key="t.id" :task="t" /></div>
                    <div v-else class="t-empty">Поки тихо.</div>
                </section>
            </div>
        </template>

        <TaskForm v-if="creating" @close="creating = false" />
    </div>
</template>
