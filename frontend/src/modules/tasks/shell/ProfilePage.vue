<script setup lang="ts">
import { computed, onMounted } from 'vue'
import Icon from './Icon.vue'
import Avatar from '../Avatar.vue'
import { applyTheme, theme, type Theme } from './theme'
import { useTasks } from '../store'
import { useSession } from '../../../app/store'
import { canSeeAdmin } from '../../admin/access'

/** «Я»: хто я в Плані, тема оформлення, інші розділи й вихід. */
const session = useSession()
const store = useTasks()
// Обірваний запит (пішли зі сторінки) — не необроблена помилка; сесію, що скінчилась, усе одно обробляємо.
onMounted(() => store.load().catch((e) => session.handle(e)))

const me = computed(() => store.meta?.me ?? null)
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
                ><Icon :name="t.icon" :size="18" />{{ t.label }}</button>
            </div>
        </section>

        <!-- На телефоні «Ще» веде сюди: усі розділи, яким не вистачило місця в нижній панелі. -->
        <section class="p-card p-links" aria-label="Розділи">
            <router-link :to="{ name: 'tasks' }"><Icon name="home" />Огляд</router-link>
            <router-link :to="{ name: 'tasks-search' }"><Icon name="search" />Пошук</router-link>
            <router-link :to="{ name: 'supply-board' }"><Icon name="clipboard" />Заявки на постачання</router-link>
            <router-link :to="{ name: 'warehouse' }"><Icon name="warehouse" />Склад</router-link>
            <router-link v-if="session.user?.fleet" :to="{ name: 'schedule' }"><Icon name="truck" />Автопарк</router-link>
            <router-link :to="{ name: 'notifications' }"><Icon name="bell" />Сповіщення</router-link>
            <router-link :to="{ name: 'staff' }"><Icon name="users" />Співробітники й відділи</router-link>
            <router-link v-if="canSeeAdmin(session.user)" :to="{ name: 'admin' }"><Icon name="shield" />Адмін</router-link>
            <a href="/crm/logout" class="danger"><Icon name="logout" />Вийти</a>
        </section>
        <p class="p-muted p-small">Гарячі клавіші: <kbd>/</kbd> пошук · <kbd>N</kbd> нова задача · <kbd>↑</kbd><kbd>↓</kbd> сусідня задача · <kbd>Esc</kbd> закрити.</p>
    </div>
</template>
