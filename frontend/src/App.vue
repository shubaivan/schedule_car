<script setup lang="ts">
import { onMounted } from 'vue'
import { useSession } from './app/store'

const session = useSession()

onMounted(() => session.load())
</script>

<template>
    <div class="layout">
        <div v-if="session.expired" class="center">
            <p>Сесія завершилась.</p>
            <p class="muted">Відкрийте бота, натисніть «🔐 Вхід у CRM» і перейдіть за новим посиланням.</p>
        </div>

        <div v-else-if="session.loading" class="center">Завантаження…</div>

        <template v-else>
            <header class="topbar">
                <span class="brand">📦 Постачання</span>
                <nav>
                    <router-link :to="{ name: 'requests' }">Заявки</router-link>
                    <router-link :to="{ name: 'suppliers' }">Постачальники</router-link>
                    <router-link :to="{ name: 'users' }">Люди</router-link>
                </nav>
                <span class="who">
                    {{ session.user?.name }} · {{ session.user?.roleLabel }}
                    · <a href="/crm/logout">вийти</a>
                </span>
            </header>

            <router-view />
        </template>
    </div>
</template>
