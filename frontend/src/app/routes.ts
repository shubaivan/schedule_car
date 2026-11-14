import 'vue-router'

/**
 * Мета маршрутів, спільна для всіх модулів.
 * shell: 'plan' — сторінка «Плану» (ліва панель / нижні вкладки);
 *        'auto' — «План», якщо стенд відкривається з задач (CRM_HOME=tasks), інакше стара шапка заявок;
 *        немає — стара шапка (стенди заявок).
 */
declare module 'vue-router' {
    interface RouteMeta {
        shell?: 'plan' | 'auto'
        title?: string
    }
}

export {}
