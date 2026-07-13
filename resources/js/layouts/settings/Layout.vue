<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Palette, ShieldCheck, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';
import GlobalRail from '@/components/GlobalRail.vue';
import { Toaster } from '@/components/ui/sonner';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { section as projectSection } from '@/routes/projects';
import { edit as editSecurity } from '@/routes/security';

type SettingsNavigation = {
    project: {
        name: string;
        slug: string;
        path: string;
    };
    projects: {
        name: string;
        slug: string;
        environment: string;
        provider_label: string;
        href: string;
        is_current: boolean;
    }[];
    counts: Record<string, number>;
    inbox_unread: number;
};

const page = usePage<{ settingsNavigation: SettingsNavigation }>();
const navigation = computed(() => page.props.settingsNavigation);
const dashboardUrl = computed(() =>
    projectSection.url({
        project: navigation.value.project.slug,
        section: 'activity',
    }),
);

const settingsItems = [
    { title: 'Profile', href: editProfile(), icon: UserRound },
    { title: 'Security', href: editSecurity(), icon: ShieldCheck },
    { title: 'Appearance', href: editAppearance(), icon: Palette },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div
        class="h-screen overflow-hidden bg-[#fbfaf7] pb-16 font-sans text-sm text-zinc-900 antialiased lg:pb-0 dark:bg-[#0b0c0d] dark:text-[#e9eaec]"
    >
        <div
            class="grid h-full min-h-0 grid-cols-1 grid-rows-[60px_minmax(0,1fr)] lg:grid-cols-[248px_minmax(0,1fr)] lg:grid-rows-[64px_minmax(0,1fr)]"
        >
            <GlobalRail
                :project-path="navigation.project.path"
                :project-name="navigation.project.name"
                :project-slug="navigation.project.slug"
                section="account"
                :projects="navigation.projects"
                :counts="navigation.counts"
                :inbox-unread="navigation.inbox_unread"
                account-active
            />

            <header
                class="col-start-1 row-start-1 flex min-w-0 items-center gap-3 border-b border-zinc-200 bg-[#fbfaf7] px-3 sm:px-4 lg:col-start-2 dark:border-[#1d2125] dark:bg-[#0b0c0d]"
            >
                <div class="flex min-w-0 items-center gap-2.5 lg:hidden">
                    <Link
                        :href="dashboardUrl"
                        class="grid size-8 shrink-0 place-items-center rounded-lg bg-teal-300 font-mono text-xs font-bold text-[#07221c]"
                    >
                        L
                    </Link>
                    <span class="truncate text-sm font-semibold">
                        {{ navigation.project.name }}
                    </span>
                </div>

                <div class="hidden min-w-0 items-center gap-2 lg:flex">
                    <span class="truncate font-semibold">Account settings</span>
                    <span class="text-zinc-300 dark:text-zinc-700">/</span>
                    <span class="truncate text-xs text-zinc-500">
                        Profile, security, and appearance
                    </span>
                </div>

                <Link
                    :href="dashboardUrl"
                    class="ml-auto inline-flex h-8 items-center rounded-lg border border-zinc-200 bg-white px-3 text-xs font-semibold text-zinc-600 transition hover:border-zinc-300 hover:text-zinc-950 dark:border-[#25292d] dark:bg-[#111315] dark:text-zinc-300 dark:hover:border-zinc-600 dark:hover:text-white"
                >
                    Back to dashboard
                </Link>
            </header>

            <main
                class="col-start-1 row-start-2 min-h-0 overflow-y-auto lg:col-start-2"
            >
                <div class="mx-auto w-full max-w-5xl px-4 py-5 sm:px-6 sm:py-7">
                    <div class="mb-6">
                        <h1 class="text-xl font-semibold tracking-tight">
                            Account settings
                        </h1>
                        <p class="mt-1 text-[13px] text-zinc-500">
                            Manage your Larasend profile, security, and local
                            display preferences.
                        </p>
                    </div>

                    <nav
                        class="mb-7 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-[#25292d]"
                        aria-label="Account settings"
                    >
                        <Link
                            v-for="item in settingsItems"
                            :key="item.title"
                            :href="item.href"
                            class="relative inline-flex h-10 shrink-0 items-center gap-2 px-3 text-xs font-semibold text-zinc-500 transition hover:text-zinc-950 dark:hover:text-zinc-100"
                            :class="{
                                'text-zinc-950 after:absolute after:right-2 after:bottom-0 after:left-2 after:h-0.5 after:rounded-full after:bg-teal-400 dark:text-zinc-100':
                                    isCurrentOrParentUrl(item.href),
                            }"
                        >
                            <component :is="item.icon" class="size-3.5" />
                            {{ item.title }}
                        </Link>
                    </nav>

                    <section
                        class="grid max-w-2xl gap-10 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm shadow-zinc-950/[0.02] sm:p-6 dark:border-[#25292d] dark:bg-[#111315]"
                    >
                        <slot />
                    </section>
                </div>
            </main>
        </div>
        <Toaster />
    </div>
</template>
