import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './app/router'
import './shared/styles.css'
import { applyTheme } from './modules/tasks/shell/theme'

// Тема «Плану» — до першого кадру, щоб «Завантаження…» не блимало іншим кольором.
applyTheme()

createApp(App).use(createPinia()).use(router).mount('#app')
