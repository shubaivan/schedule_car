<script setup lang="ts">
import { computed, onMounted } from 'vue'
import Icon from './Icon.vue'
import Avatar from '../Avatar.vue'
import { applyTheme, theme, type Theme } from './theme'
import { useTasks } from '../store'
import { useSession } from '../../../app/store'

/** «Я»: хто я в Плані, тема оформлення, інші розділи й вихід. */
const session = useSession()
const store = useTasks()
onMounted(() => store.load())

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

        <section class="p-card p-links">
            <router-link :to="{ name: 'staff' }"><Icon name="users" />Співробітники й відділи</router-link>
            <router-link :to="{ name: 'requests' }"><Icon name="package" />Заявки на постачання</router-link>
            <a href="/crm/logout" class="danger"><Icon name="logout" />Вийти</a>
        </section>
        <p class="p-muted p-small">Гарячі клавіші: <kbd>/</kbd> пошук · <kbd>N</kbd> нова задача · <kbd>↑</kbd><kbd>↓</kbd> сусідня задача · <kbd>Esc</kbd> закрити.</p>
    </div>
</template>
