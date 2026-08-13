<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ATTACHMENT_TYPES, type ApiSupplier, type PurchasePayload, type SupplyRequest } from '../../app/api'
import { useSession } from '../../app/store'
import StatusBadge from '../../shared/StatusBadge.vue'
import { formatDate, formatDateTime } from '../../shared/format'

const props = defineProps<{ id: string }>()

const session = useSession()
const request = ref<SupplyRequest | null>(null)
const error = ref('')
const busy = ref(false)

// Картку відкриває будь-хто зі своїх — щоб бачити спільну картину. Статуси й
// закупівлі веде менеджер, а автор ще й пише коментарі та носить документи.
const canManage = computed(() => session.isManager())
const canWrite = computed(
    () => canManage.value || session.isDirector() || request.value?.author.id === session.user?.id,
)
// Заявку на затвердженні рухає директор, решту ланцюжка — менеджер.
const canMoveStatus = computed(
    () => canManage.value || (session.isDirector() && request.value?.status === 'approval'),
)

// Закупівля: у кого купили. Без неї заявку не перевести в «Оплачено» й далі.
const suppliers = ref<ApiSupplier[]>([])
const addingPurchase = ref(false)
const blankPurchase = (): PurchasePayload => ({
    supplierId: 0,
    totalAmount: '',
    quantity: '',
    pricePerUnit: '',
    payment: 'bank',
    vatIncluded: true,
    invoiceNumber: '',
})
const purchase = ref<PurchasePayload>(blankPurchase())

// Документи: накладні, рахунки, договори, фото товару.
const attachmentType = ref('invoice')
const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref(false)

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

async function openPurchaseForm() {
    addingPurchase.value = true
    purchase.value = blankPurchase()

    try {
        suppliers.value = (await api.suppliers({ active: true })).items
    } catch (e) {
        error.value = session.handle(e)
    }
}

async function savePurchase() {
    if (!purchase.value.supplierId) return

    await run(() => api.addPurchase(Number(props.id), purchase.value))

    if (!error.value) {
        addingPurchase.value = false
    }
}

async function removePurchase(purchaseId: number) {
    await run(() => api.deletePurchase(Number(props.id), purchaseId))
}

async function uploadFile(event: Event) {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]

    if (!file) return

    uploading.value = true
    error.value = ''

    try {
        request.value = await api.uploadAttachment(Number(props.id), file, attachmentType.value)
    } catch (e) {
        error.value = session.handle(e)
    } finally {
        uploading.value = false
        // Скидаємо, щоб той самий файл можна було вибрати ще раз.
        input.value = ''
    }
}

async function removeAttachment(attachmentId: number) {
    await run(() => api.deleteAttachment(Number(props.id), attachmentId))
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
            <div class="row" style="justify-content: space-between">
                <h3 style="margin:0">Закупівля</h3>
                <span v-if="request.purchases?.length" class="muted">
                    Разом: <b>{{ request.purchaseTotal?.toFixed(2) }} ₴</b>
                </span>
            </div>

            <p v-if="!request.purchases?.length" class="muted" style="margin-top:.5rem">
                Ще не вказано, у кого купили. Без цього заявку не перевести в «Оплачено», «Доставка» чи «На складі».
            </p>

            <div v-else class="table-wrap" style="margin-top:.5rem">
                <table>
                    <thead>
                    <tr>
                        <th>Постачальник</th>
                        <th>Кількість</th>
                        <th>Ціна</th>
                        <th>Сума</th>
                        <th>Оплата</th>
                        <th>Накладна</th>
                        <th v-if="canManage"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="item in request.purchases" :key="item.id">
                        <td class="wrap">{{ item.supplier.name }}</td>
                        <td>{{ item.quantity ?? '—' }}</td>
                        <td>{{ item.pricePerUnit?.toFixed(2) ?? '—' }}</td>
                        <td>
                            {{ item.totalLabel }}
                            <span v-if="!item.vatIncluded" class="muted">без ПДВ</span>
                        </td>
                        <td>{{ item.paymentLabel }}</td>
                        <td>{{ item.invoiceNumber ?? '—' }}</td>
                        <td v-if="canManage">
                            <button class="danger" :disabled="busy" @click="removePurchase(item.id)">Прибрати</button>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="addingPurchase && canManage" style="margin-top:.75rem">
                <div class="filters">
                    <select v-model.number="purchase.supplierId">
                        <option :value="0" disabled>— оберіть постачальника —</option>
                        <option v-for="item in suppliers" :key="item.id" :value="item.id">{{ item.name }}</option>
                    </select>
                    <input v-model="purchase.totalAmount" type="text" placeholder="Сума, ₴" />
                    <input v-model="purchase.quantity" type="text" placeholder="Кількість" />
                    <input v-model="purchase.pricePerUnit" type="text" placeholder="Ціна за одиницю" />
                    <select v-model="purchase.payment">
                        <option value="bank">Безготівка</option>
                        <option value="cash">Готівка</option>
                    </select>
                    <input v-model="purchase.invoiceNumber" type="text" placeholder="Накладна" />
                    <label class="flag">
                        <input v-model="purchase.vatIncluded" type="checkbox" />
                        з ПДВ
                    </label>
                </div>
                <p class="muted">Досить суми — або ціни разом із кількістю, решту дорахуємо.</p>
                <div class="row">
                    <button class="primary" :disabled="busy || !purchase.supplierId" @click="savePurchase">
                        Зберегти закупівлю
                    </button>
                    <button :disabled="busy" @click="addingPurchase = false">Скасувати</button>
                    <router-link :to="{ name: 'suppliers' }" class="muted">Довідник постачальників →</router-link>
                </div>
            </div>

            <div v-else-if="canManage" class="row" style="margin-top:.75rem">
                <button :disabled="busy" @click="openPurchaseForm">
                    {{ request.purchases?.length ? '+ Ще постачальник' : '+ Вказати постачальника' }}
                </button>
            </div>
        </div>

        <div v-if="canMoveStatus" class="card">
            <p v-if="request.status === 'approval'" class="muted" style="margin-top:0">
                ⏳ Заявка чекає рішення про оплату: «Оплачено» тут ставить директор.
            </p>
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
            <h3 style="margin-top:0">Документи</h3>

            <p v-if="!request.attachments?.length" class="muted">
                Накладних, рахунків і договорів ще немає.
            </p>

            <div v-else class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Документ</th>
                        <th>Тип</th>
                        <th>Розмір</th>
                        <th>Завантажив</th>
                        <th>Drive</th>
                        <th v-if="canManage"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="file in request.attachments" :key="file.id">
                        <td class="wrap">
                            <a :href="api.attachmentUrl(Number(props.id), file.id)">{{ file.name }}</a>
                        </td>
                        <td>{{ file.typeLabel }}</td>
                        <td>{{ file.sizeLabel }}</td>
                        <td class="wrap">
                            {{ file.uploadedBy ?? '—' }}<br>
                            <span class="muted">{{ formatDateTime(file.uploadedAt) }}</span>
                        </td>
                        <td>
                            <a v-if="file.driveUrl" :href="file.driveUrl" target="_blank" rel="noopener">відкрити</a>
                            <span v-else class="muted">у черзі</span>
                        </td>
                        <td v-if="canManage">
                            <button class="danger" :disabled="busy" @click="removeAttachment(file.id)">Прибрати</button>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="canWrite" class="filters" style="margin-top:.75rem">
                <select v-model="attachmentType">
                    <option v-for="type in ATTACHMENT_TYPES" :key="type.value" :value="type.value">
                        {{ type.label }}
                    </option>
                </select>
                <input
                    ref="fileInput"
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png,.heic,.webp,.doc,.docx,.xls,.xlsx"
                    :disabled="uploading"
                    @change="uploadFile"
                />
                <span v-if="uploading" class="muted">Завантаження…</span>
            </div>

            <p v-if="canWrite" class="muted">
                До 20 МБ. Копія автоматично лягає в Google Drive заводу — у теку
                за роком, місяцем, заявником і номером заявки.
                Накладну можна надіслати й фото з бота — кнопка «📎 Накладна» на картці заявки.
            </p>
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

            <div v-if="canWrite" style="margin-top:1rem">
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
