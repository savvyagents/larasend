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
        viewBox="0 0 48 48"
        :class="className"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <path
            d="M8.75 14.9 39.25 7.5 31.85 40l-8.3-12.2-10.6 8.25-4.2-21.15Z"
            fill="currentColor"
            opacity=".16"
        />
        <path
            d="M8.75 14.9 39.25 7.5 23.55 27.8m15.7-20.3L31.85 40l-8.3-12.2m0 0-10.6 8.25-4.2-21.15"
            fill="none"
            stroke="currentColor"
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="3.2"
        />
        <path
            d="M13.4 18h11.2M11.5 24.6h7.4"
            fill="none"
            stroke="currentColor"
            stroke-linecap="round"
            stroke-width="3.2"
            opacity=".62"
        />
    </svg>
</template>
