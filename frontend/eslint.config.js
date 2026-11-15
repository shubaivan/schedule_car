// ESLint «Плану»: JS/TS/Vue — рекомендовані правила + typescript-eslint; форматування — Prettier
// (eslint-config-prettier вимикає стильові правила, щоб вони не сперечались).
import js from '@eslint/js'
import prettier from 'eslint-config-prettier'
import vue from 'eslint-plugin-vue'
import globals from 'globals'
import tseslint from 'typescript-eslint'

export default tseslint.config(
    { ignores: ['node_modules/**', 'dist/**'] },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    ...vue.configs['flat/recommended'],
    {
        files: ['**/*.vue'],
        languageOptions: { parserOptions: { parser: tseslint.parser } },
    },
    {
        languageOptions: { globals: { ...globals.browser } },
        rules: {
            // Сторінки — це маршрути (TasksHome, AuditPage…), однослівні імена компонентів — норма.
            'vue/multi-word-component-names': 'off',
            // Невідомі імена ловить vue-tsc (TypeScript), no-undef для TS лише заважає.
            'no-undef': 'off',
            '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
        },
    },
    prettier,
)
