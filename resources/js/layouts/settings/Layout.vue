<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Activity, KeyRound, Palette, ShieldCheck, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Toaster } from '@/components/ui/sonner';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { dashboard } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

const sidebarNavItems = [
    {
        title: 'Profile',
        href: editProfile(),
        icon: UserRound,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: Palette,
    },
];

const page = usePage();
const user = computed(() => page.props.auth.user);
const initials = computed(() =>
    String(user.value.name ?? 'User')
        .split(' ')
        .map((part) => part.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase(),
);

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div
        class="h-screen overflow-hidden bg-[#fbfaf7] font-sans text-[13px] text-zinc-900 antialiased dark:bg-[#0b0c0d] dark:text-[#e9eaec]"
    >
        <div
            class="grid h-screen min-h-0 grid-cols-[224px_minmax(0,1fr)] grid-rows-[52px_minmax(0,1fr)]"
        >
            <header
                class="col-span-2 flex h-[52px] shrink-0 items-center gap-3 border-b border-zinc-200 bg-[#fbfaf7] px-3.5 dark:border-[#1d2125] dark:bg-[#0b0c0d]"
            >
                <div
                    class="flex h-full w-[210px] items-center gap-2 border-r border-zinc-200 pr-3 dark:border-[#1d2125]"
                >
                    <Link
                        :href="dashboard()"
                        class="grid size-[22px] place-items-center rounded-md bg-teal-300 text-[#0b0c0d]"
                    >
                        <AppLogoIcon class="size-[17px]" />
                    </Link>
                    >
                    <Link
                        :href="dashboard()"
                        class="font-sans text-sm font-semibold tracking-tight"
                        >larasend</Link
                    >
                </div>

                <div
                    class="flex items-center gap-2 font-sans text-[12.5px] text-zinc-500 dark:text-[#9aa0a6]"
                >
                    <Link
                        :href="dashboard()"
                        class="hover:text-zinc-950 dark:hover:text-zinc-100"
                        >Dashboard</Link
                    >
                    <span class="text-zinc-400 dark:text-[#4a4f55]">/</span>
                    <span class="font-medium text-zinc-950 dark:text-zinc-100"
                        >Settings</span
                    >
                </div>

                <Link
                    :href="dashboard()"
                    class="ml-auto inline-flex h-8 items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-3 font-sans text-[12.5px] font-medium text-zinc-700 hover:bg-zinc-100 dark:border-[#1d2125] dark:bg-[#111315] dark:text-zinc-200 dark:hover:bg-[#16191c]"
                >
                    <Activity class="size-3.5" />
                    Activity
                </Link>
                <div
                    class="grid size-[26px] place-items-center rounded-full bg-gradient-to-br from-violet-300 to-teal-300 font-mono text-[11px] font-semibold text-[#0b0c0d]"
                >
                    {{ initials }}
                </div>
            </header>

            <aside
                class="relative row-start-2 flex min-h-0 flex-col border-r border-zinc-200 bg-[#fbfaf7] px-2 py-2 dark:border-[#1d2125] dark:bg-[#0b0c0d]"
            >
                <div class="min-h-0 flex-1 overflow-y-auto">
                    <div
                        class="px-2 pt-1 pb-1.5 font-mono text-[10.5px] font-medium tracking-widest text-zinc-500 uppercase dark:text-[#6c7177]"
                    >
                        Account
                    </div>
                    <nav class="space-y-0.5" aria-label="Settings">
                        <Link
                            v-for="item in sidebarNavItems"
                            :key="item.title"
                            :href="item.href"
                            class="group relative flex h-7 w-full items-center gap-2.5 rounded-md px-2 text-left font-sans text-[12.5px] text-zinc-600 hover:bg-zinc-100 hover:text-zinc-950 dark:text-[#9aa0a6] dark:hover:bg-[#16191c] dark:hover:text-zinc-100"
                            :class="{
                                'bg-zinc-200/70 font-medium text-zinc-950 before:absolute before:top-1.5 before:bottom-1.5 before:-left-2 before:w-0.5 before:rounded-r before:bg-teal-300 dark:bg-[#1a1e22] dark:text-zinc-100':
                                    isCurrentOrParentUrl(item.href),
                            }"
                        >
                            <component :is="item.icon" class="size-3.5" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </nav>
                </div>

                <div
                    class="rounded-lg border border-zinc-200 bg-white p-3 dark:border-[#1d2125] dark:bg-[#111315]"
                >
                    <div
                        class="flex items-center gap-2 font-mono text-[11px] text-zinc-500"
                    >
                        <KeyRound class="size-3.5 text-teal-400" />
                        Account settings
                    </div>
                    <p class="mt-2 text-[12px] leading-5 text-zinc-500">
                        Profile, access, and local display preferences.
                    </p>
                </div>
            </aside>

            <main class="row-start-2 min-h-0 overflow-y-auto">
                <div class="mx-auto w-full max-w-5xl px-6 py-7">
                    <div class="mb-7">
                        <h1 class="text-xl font-semibold tracking-tight">
                            Settings
                        </h1>
                        <p class="mt-1 text-[13px] text-zinc-500">
                            Manage your Larasend profile and account settings.
                        </p>
                    </div>

                    <section class="max-w-2xl space-y-12">
                        <slot />
                    </section>
                </div>
            </main>
        </div>
    </div>
    <Toaster />
</template>
