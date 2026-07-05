<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-[#f7f7f4] p-6 text-zinc-950 md:p-10 dark:bg-[#090a0a] dark:text-zinc-100"
    >
        <div class="w-full max-w-[420px]">
            <div
                class="flex flex-col gap-8 rounded-xl border border-zinc-200 bg-white p-7 shadow-sm dark:border-[#1d2125] dark:bg-[#101111]"
            >
                <div class="flex flex-col items-start gap-4">
                    <Link
                        :href="home()"
                        class="flex items-center gap-3 font-medium"
                    >
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-md bg-teal-300 text-zinc-950"
                        >
                            <AppLogoIcon
                                class="size-5 fill-current"
                            />
                        </div>
                        <span class="text-sm font-semibold tracking-tight"
                            >Larasend</span
                        >
                    </Link>
                    <div class="space-y-2">
                        <h1 class="text-xl font-semibold tracking-tight">
                            {{ title }}
                        </h1>
                        <p class="text-sm leading-6 text-zinc-500">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
