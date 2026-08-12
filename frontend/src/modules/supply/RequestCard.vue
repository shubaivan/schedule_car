<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, type SupplyRequest } from '../../app/api'
import { useSession } from '../../app/store'
import StatusBadge from '../../shared/StatusBadge.vue'
import { formatDate, formatDateTime } from '../../shared/format'

const props = defineProps<{ id: string }>()

const session = useSession()
const request = ref<SupplyRequest | null>(null)
const error = ref('')
const busy = ref(false)

const comment = ref('')
// Відхилення без причини не пропускає ані бек, ані ця форма.
const rejecting = ref(false)
const reason = ref('')

async function load() {
    try {
        request.value = await api.request(Number(props.id))
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function changeStatus(to: string) {
    if (to === 'rejected') {
        rejecting.value = true

        return
    }

    await run(() => api.changeStatus(Number(props.id), to))
}

async function reject() {
    if (!reason.value.trim()) return

    await run(() => api.changeStatus(Number(props.id), 'rejected', reason.value))
    rejecting.value = false
    reason.value = ''
}

async function sendComment() {
    if (!comment.value.trim()) return

    await run(() => api.comment(Number(props.id), comment.value))
    comment.value = ''
}

async function run(action: () => Promise<SupplyRequest>) {
    busy.value = true
    error.value = ''

    try {
        request.value = await action()
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        busy.value = false
    }
}

onMounted(load)
</script>

<template>
    <p><router-link :to="{ name: 'requests' }">← До списку</router-link></p>

    <div v-if="error" class="error">{{ error }}</div>
    <div v-if="!request" class="center">Завантаження…</div>

    <template v-else>
        <div class="card">
            <div class="row" style="justify-content: space-between">
                <h2 style="margin:0">
                    Заявка №{{ request.number }}
                    <span v-if="request.urgent" class="flag">🔥 терміново</span>
                </h2>
                <StatusBadge :status="request.status" :label="request.statusLabel" />
            </div>

            <dl class="grid" style="margin-top:1rem">
                <div>
                    <dt>Матеріал</dt>
                    <dd>{{ request.item }}</dd>
                </div>
                <div>
                    <dt>Кількість</dt>
                    <dd>{{ request.quantityLabel }}</dd>
                </div>
                <div>
                    <dt>Потрібно до</dt>
                    <dd>
                        {{ request.needBy ? formatDate(request.needBy) : '—' }}
                        <span v-if="request.overdue" class="flag">прострочено</span>
                    </dd>
                </div>
                <div>
                    <dt>Заявник</dt>
                    <dd>{{ request.author.name }}<br><span class="muted">{{ request.author.phone ?? '' }}</span></dd>
                </div>
                <div>
                    <dt>Підрозділ</dt>
                    <dd>{{ request.department ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Створено</dt>
                    <dd>{{ formatDateTime(request.createdAt) }}</dd>
                </div>
            </dl>

            <p v-if="request.note" class="muted" style="margin-top:1rem">📝 {{ request.note }}</p>
        </div>

        <div class="card">
            <div class="row">
                <button
                    v-for="transition in request.allowedTransitions"
                    :key="transition.value"
                    :class="transition.value === 'rejected' ? 'danger' : 'primary'"
                    :disabled="busy"
                    @click="changeStatus(transition.value)"
                >
                    {{ transition.label }}
                </button>
                <span v-if="!request.allowedTransitions?.length" class="muted">Заявка закрита.</span>
            </div>

            <div v-if="rejecting" style="margin-top:.75rem">
                <textarea v-model="reason" placeholder="Причина відхилення — вона піде заявнику"></textarea>
                <div class="row" style="margin-top:.5rem">
                    <button class="danger" :disabled="busy || !reason.trim()" @click="reject">Відхилити</button>
                    <button :disabled="busy" @click="rejecting = false">Скасувати</button>
                </div>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-top:0">Хронологія</h3>

            <ul class="timeline">
                <li v-for="(event, index) in request.timeline" :key="index">
                    <div>
                        <template v-if="event.type === 'status'">
                            <StatusBadge :status="event.status!" :label="event.statusLabel!" />
                            <span v-if="event.text"> — {{ event.text }}</span>
                        </template>
                        <template v-else>💬 {{ event.text }}</template>
                    </div>
                    <div class="meta">
                        {{ formatDateTime(event.at) }}<span v-if="event.author"> · {{ event.author.name }}</span>
                    </div>
                </li>
            </ul>

            <div style="margin-top:1rem">
                <textarea v-model="comment" placeholder="Коментар — заявник отримає його в Telegram"></textarea>
                <div class="row" style="margin-top:.5rem">
                    <button class="primary" :disabled="busy || !comment.trim()" @click="sendComment">
                        Надіслати
                    </button>
                </div>
            </div>
        </div>
    </template>
</template>
