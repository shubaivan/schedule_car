<script setup lang="ts">
import { computed } from 'vue'
import type { Attachment } from './api'
import { extClass, pagesLabel } from './files'

/**
 * Вкладення в повідомленні чату.
 * Фото — плиткою (одне велике, кілька — сіткою); документи — карткою
 * з першою сторінкою, бейджем типу й кількістю сторінок.
 */
const props = defineProps<{ files: Attachment[] }>()
const emit = defineEmits<{ open: [id: number] }>()

const images = computed(() => props.files.filter((f) => f.kind === 'image' && f.urls.thumb))
const docs = computed(() => props.files.filter((f) => !(f.kind === 'image' && f.urls.thumb)))
const gridClass = computed(() => {
    const n = images.value.length
    return n === 1 ? 'n1' : n === 2 ? 'n2' : n === 3 ? 'n3' : 'nmany'
})
</script>

<template>
    <div v-if="images.length" class="t-files" :class="gridClass">
        <button v-for="f in images" :key="f.id" type="button" class="t-img" :title="f.name" @click="emit('open', f.id)">
            <img :src="f.urls.thumb!" :alt="f.name" loading="lazy" :width="f.width ?? undefined" :height="f.height ?? undefined" />
        </button>
    </div>

    <button
        v-for="f in docs"
        :key="f.id"
        type="button"
        class="t-doc"
        :class="{ compact: f.kind === 'other' || f.kind === 'image' || f.preview === 'failed' || f.preview === 'none' }"
        @click="emit('open', f.id)"
    >
        <div class="pv">
            <span class="badge" :class="extClass(f)">{{ f.ext }}</span>
            <img v-if="f.urls.thumb" :src="f.urls.thumb" :alt="f.name" loading="lazy" />
            <div v-else class="t-skel">
                <i style="width: 70%" /><i style="width: 90%" /><i style="width: 55%" /><i style="width: 80%" />
                <span>Готуємо превʼю…</span>
            </div>
            <span v-if="f.pages" class="pages">{{ pagesLabel(f.pages) }}</span>
        </div>
        <div class="info">
            <span class="icon" :class="extClass(f)">{{ f.ext.slice(0, 4) }}</span>
            <span style="min-width: 0">
                <div class="name">{{ f.name }}</div>
                <div class="size">{{ f.sizeLabel }}</div>
            </span>
        </div>
    </button>
</template>
