import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './app/router'
import { installStaleChunkRecovery } from './app/staleChunks'
import './shared/styles.css'
import { applyTheme } from './modules/tasks/shell/theme'

// Тема «Плану» — до першого кадру, щоб «Завантаження…» не блимало іншим кольором.
applyTheme()

// Стара вкладка після деплою: зниклий лінивий файл → одне перезавантаження (див. staleChunks.ts).
installStaleChunkRecovery(router)

createApp(App).use(createPinia()).use(router).mount('#app')
