import type { Directive } from 'vue'

/**
 * v-sticky-head на `.t-task-main`: міряє висоту шапки `.p-thead` і кладе її в
 * `--p-thead-h`, щоб на телефоні смуга вкладок (Чат / Деталі / Файли) липла
 * одразу під шапкою і не їхала за верх екрана в довгій стрічці.
 */
const observers = new WeakMap<HTMLElement, ResizeObserver>()

function measure(el: HTMLElement) {
    const head = el.querySelector<HTMLElement>(':scope > .p-thead')
    el.style.setProperty('--p-thead-h', head ? head.offsetHeight + 'px' : '0px')
}

export const vStickyHead: Directive<HTMLElement> = {
    mounted(el) {
        measure(el)
        if (typeof ResizeObserver === 'undefined') return
        const ro = new ResizeObserver(() => measure(el))
        const head = el.querySelector<HTMLElement>(':scope > .p-thead')
        if (head) ro.observe(head)
        observers.set(el, ro)
    },
    updated(el) {
        const ro = observers.get(el)
        const head = el.querySelector<HTMLElement>(':scope > .p-thead')
        if (ro && head) ro.observe(head)
        measure(el)
    },
    unmounted(el) {
        observers.get(el)?.disconnect()
        observers.delete(el)
    },
}
