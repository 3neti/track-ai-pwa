<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 40"
        :class="className"
        v-bind="$attrs"
        role="img"
        aria-label="Track AI"
    >
        <rect width="40" height="40" rx="8" fill="currentColor" opacity="0.18" />
        <path
            fill="currentColor"
            d="M7.5 11.5h13v4h-4.2v15h-4.1v-15H7.5v-4Zm19.1 0h4.2l6.7 19h-4.4l-1.1-3.5h-6.8L24 30.5h-4.2l6.8-19Zm.1 11.9h4.2l-2.1-6.8-2.1 6.8Z"
        />
    </svg>
</template>
