<script setup lang="ts">
import { computed } from 'vue'
import { formatPhone, usePhoneBook } from './people'

/**
 * Ім'я людини з телефоном у дужках — «Олександр Чигрин (093 350 44 42)». Один компонент на весь План.
 *
 * - person — об'єкт із API ({ id, name, phone?, tel? }), або окремо id / name (тоді телефон — з довідника людей);
 * - link=false — коли ім'я вже всередині посилання чи кнопки (вкладене <a> неприпустиме): номер текстом;
 * - на вузькому місці ім'я обрізається «…», номер не переноситься (CSS .p-person).
 */
const props = withDefaults(
    defineProps<{
        person?: { id?: number | null; name: string; phone?: string | null; tel?: string | null } | null
        id?: number | null
        name?: string | null
        link?: boolean
        bold?: boolean
    }>(),
    { person: null, id: null, name: null, link: true, bold: false },
)

const book = usePhoneBook()
const shownName = computed(() => props.person?.name ?? props.name ?? '')
const phone = computed(() => {
    // Номер у самому об'єкті (будь-який запис — сирі цифри чи вже відформатований) — нормалізуємо.
    const own = formatPhone(props.person?.phone ?? props.person?.tel)
    if (own) return own
    return book.lookup(props.person?.id ?? props.id, shownName.value)
})
</script>

<template>
    <span class="p-person" :title="phone ? `${shownName} · ${phone.phone}` : shownName">
        <component :is="bold ? 'b' : 'span'" class="p-person-name">{{ shownName }}</component>
        <template v-if="phone">
            <a v-if="link" class="p-person-phone" :href="'tel:' + phone.tel" @click.stop>({{ phone.phone }})</a>
            <span v-else class="p-person-phone">({{ phone.phone }})</span>
        </template>
    </span>
</template>

<style>
.p-person { display: inline-flex; align-items: baseline; gap: .3em; min-width: 0; max-width: 100%; vertical-align: bottom; }
.p-person-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.p-person-phone { flex: none; white-space: nowrap; color: var(--text-muted, #6b7280); font-weight: 400; font-size: .92em; text-decoration: none; font-variant-numeric: tabular-nums; }
a.p-person-phone:hover { text-decoration: underline; }
/* Телефон: вузька клітинка таблиці не має розпирати сторінку — номер переходить на свій рядок. */
@media (max-width: 560px) {
    .p-person { flex-wrap: wrap; row-gap: 0; }
    td .p-person, .t-table .p-person { max-width: 46vw; }
}
</style>
