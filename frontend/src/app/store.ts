import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, SessionExpired, type ApiUser, type Meta } from './api'

/** Хто увійшов і довідники — вантажимо один раз на старті CRM. */
export const useSession = defineStore('session', () => {
    const user = ref<ApiUser | null>(null)
    const meta = ref<Meta | null>(null)
    const expired = ref(false)
    const loading = ref(true)

    async function load() {
        loading.value = true
        try {
            const [me, dictionaries] = await Promise.all([api.me(), api.meta()])
            user.value = me
            meta.value = dictionaries
        } catch (error) {
            if (error instanceof SessionExpired) expired.value = true
            else throw error
        } finally {
            loading.value = false
        }
    }

    function handle(error: unknown): string {
        if (error instanceof SessionExpired) {
            expired.value = true
            return ''
        }

        return error instanceof Error ? error.message : 'Невідома помилка'
    }

    const isAdmin = () => user.value?.role === 'admin'
    // Заявки бачать усі, тож роль вирішує не «пустити чи ні», а що саме показати:
    // кнопки статусів, закупівлі й розділи меню — лише тим, хто закуповує.
    const isManager = () => user.value?.role === 'manager' || isAdmin()
    // Директор нічого не веде, але саме він рухає заявку з «На затвердженні».
    const isDirector = () => user.value?.role === 'director' || isAdmin()

    return { user, meta, expired, loading, load, handle, isAdmin, isManager, isDirector }
})
