import { ref } from 'vue'

/** Тема «Плану»: як у системі, світла або темна. Памʼятаємо в браузері людини. */
export type Theme = 'auto' | 'light' | 'dark'

const KEY = 'plan.theme'

function read(): Theme {
    try {
        const v = localStorage.getItem(KEY)
        return v === 'light' || v === 'dark' ? v : 'auto'
    } catch {
        return 'auto'
    }
}

export const theme = ref<Theme>(read())

export function applyTheme(value: Theme = theme.value): void {
    theme.value = value
    const root = document.documentElement
    if (value === 'auto') delete root.dataset.theme
    else root.dataset.theme = value
    try {
        if (value === 'auto') localStorage.removeItem(KEY)
        else localStorage.setItem(KEY, value)
    } catch {
        /* приватне вікно — тема живе до перезавантаження */
    }
}
