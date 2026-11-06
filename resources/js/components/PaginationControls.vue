<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

withDefaults(
    defineProps<{
        page: number;
        from: number | null;
        to: number | null;
        hasPrevious: boolean;
        hasMore: boolean;
        noun: string;
        previousLabel?: string;
        nextLabel?: string;
    }>(),
    {
        previousLabel: 'Previous',
        nextLabel: 'Next',
    },
);

defineEmits<{
    previous: [];
    next: [];
}>();
</script>

<template>
    <div
        v-if="hasPrevious || hasMore"
        class="flex items-center justify-between gap-2 border-t border-zinc-200 bg-white px-3 py-2 dark:border-[#1d2125] dark:bg-[#0b0c0d]"
        :aria-label="`${noun} pagination`"
    >
        <p class="min-w-0 truncate text-[11px] text-zinc-500">
            <template v-if="from !== null && to !== null">
                <span class="font-semibold text-zinc-700 dark:text-zinc-300">
                    {{ from }}–{{ to }}
                </span>
                {{ noun }} ·
            </template>
            Page {{ page }}
        </p>
        <div class="flex shrink-0 items-center gap-1">
            <button
                type="button"
                class="inline-flex h-8 items-center gap-1 rounded-md border border-zinc-200 px-2 text-[11px] font-semibold text-zinc-700 transition hover:border-zinc-300 hover:bg-zinc-50 focus-visible:ring-2 focus-visible:ring-teal-400 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-35 dark:border-[#25292d] dark:text-zinc-300 dark:hover:border-[#34393e] dark:hover:bg-[#151719]"
                :disabled="!hasPrevious"
                :aria-label="`Show previous ${noun}`"
                @click="$emit('previous')"
            >
                <ChevronLeft class="size-3.5" />
                {{ previousLabel }}
            </button>
            <button
                type="button"
                class="inline-flex h-8 items-center gap-1 rounded-md border border-zinc-200 px-2 text-[11px] font-semibold text-zinc-700 transition hover:border-zinc-300 hover:bg-zinc-50 focus-visible:ring-2 focus-visible:ring-teal-400 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-35 dark:border-[#25292d] dark:text-zinc-300 dark:hover:border-[#34393e] dark:hover:bg-[#151719]"
                :disabled="!hasMore"
                :aria-label="`Show next ${noun}`"
                @click="$emit('next')"
            >
                {{ nextLabel }}
                <ChevronRight class="size-3.5" />
            </button>
        </div>
    </div>
</template>
