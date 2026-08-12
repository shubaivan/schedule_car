import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Збірка лягає в public/crm — той самий домен, що й API,
// тому сесійна кука працює без CORS і без токенів.
export default defineConfig({
    plugins: [vue()],
    base: '/crm/',
    build: {
        outDir: '../public/crm',
        emptyOutDir: true,
    },
    server: {
        proxy: {
            '/api': 'http://127.0.0.1:8000',
        },
    },
})
