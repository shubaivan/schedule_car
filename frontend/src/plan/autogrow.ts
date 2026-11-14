/**
 * Назва в формі (задача, заявка) — textarea, що росте разом із текстом.
 * Порожнє поле міряємо за підказкою: довга підказка на телефоні теж не обрізається.
 */
export function autogrow(el: HTMLTextAreaElement | null | undefined): void {
    if (!el) return
    const empty = el.value === ''
    if (empty) el.value = el.placeholder
    el.style.height = 'auto'
    el.style.height = el.scrollHeight + 'px'
    if (empty) el.value = ''
}
