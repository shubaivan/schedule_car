<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Icon from './Icon.vue'
import Avatar from '../Avatar.vue'
import { applyTheme, theme, type Theme } from './theme'
import { useTasks } from '../store'
import { useSession } from '../../../app/store'
import { canManageRoles, canSeeAdmin, canSeeDrive, canSeeSupply, canSeeWarehouse } from '../../admin/access'
import { canSeeCounterparties } from '../../counterparties/access'
import { call } from '../../../app/api'

/** «Я»: хто я в Плані, тема оформлення, інші розділи й вихід. */
const session = useSession()
const store = useTasks()
// Обірваний запит (пішли зі сторінки) — не необроблена помилка; сесію, що скінчилась, усе одно обробляємо.
onMounted(() => store.load().catch((e) => session.handle(e)))

const me = computed(() => store.meta?.me ?? null)
/**
 * Пароль для входу за телефоном: його задає лише сама людина — бот надсилає одноразове
 * посилання в її Telegram (PasswordSetup). Тут — лише попросити це посилання.
 */
const pwState = ref<'idle' | 'busy' | 'sent' | 'error'>('idle')
const pwError = ref('')
async function passwordLink() {
    pwState.value = 'busy'
    try {
        await call('/api/plan/password/link', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })
        pwState.value = 'sent'
    } catch (e) {
        pwState.value = 'error'
        pwError.value = session.handle(e)
    }
}

const THEMES: { value: Theme; label: string; icon: string }[] = [
    { value: 'auto', label: 'Як у системі', icon: 'monitor' },
    { value: 'light', label: 'Світла', icon: 'sun' },
    { value: 'dark', label: 'Темна', icon: 'moon' },
]
</script>

<template>
    <div class="p-profile">
        <section class="p-card p-profile-head">
            <Avatar :person="me" :size="56" />
            <div>
                <h2>{{ me?.name ?? session.user?.name }}</h2>
                <div class="p-muted">{{ me?.position ?? session.user?.workRoleLabel }}</div>
                <div v-if="me?.department" class="p-dep">[{{ me.department }}]</div>
            </div>
        </section>

        <section v-if="session.user?.passwordLink" class="p-card" data-testid="profile-password">
            <h3>Пароль для входу</h3>
            <p class="p-muted">
                {{
                    session.user?.hasPassword
                        ? 'Пароль задано: можна входити за телефоном і паролем або через Telegram.'
                        : 'Можна входити не лише через Telegram, а й за телефоном і паролем.'
                }}
                Пароль задаєте лише ви — бот надішле посилання у ваш Telegram.
            </p>
            <button type="button" class="t-btn" :disabled="pwState === 'busy' || pwState === 'sent'" @click="passwordLink">
                {{ pwState === 'sent' ? 'Посилання надіслано в Telegram' : session.user?.hasPassword ? 'Змінити пароль' : 'Встановити пароль' }}
            </button>
            <p v-if="pwState === 'sent'" class="p-muted">Відкрийте бота Плану в Telegram і натисніть «🔑 …». Посилання діє 30 хвилин.</p>
            <p v-if="pwState === 'error' && pwError" class="t-error">{{ pwError }}</p>
        </section>

        <section class="p-card">
            <h3>Оформлення</h3>
            <div class="p-themes" role="radiogroup" aria-label="Тема">
                <button
                    v-for="t in THEMES"
                    :key="t.value"
                    type="button"
                    role="radio"
                    :aria-checked="theme === t.value"
                    :class="{ on: theme === t.value }"
                    @click="applyTheme(t.value)"
                >
                    <Icon :name="t.icon" :size="18" />
                    {{ t.label }}
                </button>
            </div>
        </section>

        <!-- На телефоні «Ще» веде сюди: усі розділи, яким не вистачило місця в нижній панелі. -->
        <section class="p-card p-links" aria-label="Розділи">
            <router-link :to="{ name: 'tasks' }">
                <Icon name="home" />
                Огляд
            </router-link>
            <router-link :to="{ name: 'tasks-search' }">
                <Icon name="search" />
                Пошук
            </router-link>
            <router-link v-if="session.user?.zvyazok" :to="{ name: 'zvyazok' }" data-testid="more-zvyazok">
                <Icon name="chat" />
                Звʼязок
            </router-link>
            <router-link v-if="canSeeSupply(session.user)" :to="{ name: 'supply-board' }">
                <Icon name="clipboard" />
                Постачання
            </router-link>
            <router-link v-if="canSeeWarehouse(session.user)" :to="{ name: 'warehouse' }">
                <Icon name="warehouse" />
                Склад
            </router-link>
            <router-link v-if="canSeeCounterparties(session.user)" :to="{ name: 'counterparties' }">
                <Icon name="building" />
                Контрагенти
            </router-link>
            <router-link v-if="session.user?.fleet" :to="{ name: 'schedule' }">
                <Icon name="truck" />
                Автопарк
            </router-link>
            <router-link :to="{ name: 'notifications' }">
                <Icon name="bell" />
                Сповіщення
            </router-link>
            <router-link :to="{ name: 'staff' }">
                <Icon name="users" />
                Співробітники й відділи
            </router-link>
            <router-link v-if="canManageRoles(session.user)" :to="{ name: 'admin-roles' }" data-testid="more-roles">
                <Icon name="users" />
                Ролі й доступ
            </router-link>
            <router-link v-if="canSeeAdmin(session.user)" :to="{ name: 'admin' }">
                <Icon name="shield" />
                Адмін
            </router-link>
            <router-link v-if="canSeeDrive(session.user)" :to="{ name: 'drive-status' }">
                <Icon name="file" />
                Google Диск
            </router-link>
            <a href="/crm/logout" class="danger">
                <Icon name="logout" />
                Вийти
            </a>
        </section>
        <p class="p-muted p-small">
            Гарячі клавіші:
            <kbd>/</kbd>
            пошук ·
            <kbd>N</kbd>
            нова задача ·
            <kbd>↑</kbd>
            <kbd>↓</kbd>
            сусідня задача ·
            <kbd>Esc</kbd>
            закрити.
        </p>
    </div>
</template>
