<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    Ban,
    ChevronDown,
    FileText,
    Globe2,
    Inbox,
    KeyRound,
    LayoutDashboard,
    Send,
    Settings2,
    SlidersHorizontal,
    Users,
    Webhook,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

type ProjectOption = {
    name: string;
    slug: string;
    environment?: string;
    provider_label?: string;
    href: string;
    is_current?: boolean;
};

const props = withDefaults(
    defineProps<{
        projectPath: string;
        projectName: string;
        projectSlug: string;
        section: string;
        projects?: ProjectOption[];
        counts?: Record<string, number>;
        inboxUnread?: number;
        buildLabel?: string;
    }>(),
    {
        projects: () => [],
        counts: () => ({}),
        inboxUnread: 0,
        buildLabel: '',
    },
);

const page = usePage<{
    auth: { user: { name: string; email: string } };
}>();
const projectMenuOpen = ref(false);
const mobileMenuOpen = ref(false);

const userInitials = computed(() =>
    page.props.auth.user.name
        .split(' ')
        .map((part: string) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);

const navigationGroups = computed(() => [
    {
        label: 'Overview',
        items: [
            {
                label: 'Overview',
                section: 'activity',
                href: `${props.projectPath}/activity`,
                icon: LayoutDashboard,
                count: null,
            },
            {
                label: 'Inbox',
                section: 'inbox',
                href: `${props.projectPath}/inbox`,
                icon: Inbox,
                count: props.inboxUnread || null,
            },
            {
                label: 'Send email',
                section: 'send',
                href: `${props.projectPath}/send`,
                icon: Send,
                count: null,
            },
        ],
    },
    {
        label: 'Sending',
        items: [
            {
                label: 'Sent mail',
                section: 'sent',
                href: `${props.projectPath}/sent`,
                icon: Activity,
                count: props.counts.sent ?? null,
            },
            {
                label: 'Templates',
                section: 'templates',
                href: `${props.projectPath}/templates`,
                icon: FileText,
                count: null,
            },
        ],
    },
    {
        label: 'Deliverability',
        items: [
            {
                label: 'Bounces',
                section: 'bounces',
                href: `${props.projectPath}/bounces`,
                icon: AlertTriangle,
                count: props.counts.bounces ?? null,
            },
            {
                label: 'Complaints',
                section: 'complaints',
                href: `${props.projectPath}/complaints`,
                icon: Ban,
                count: props.counts.complaints ?? null,
            },
            {
                label: 'Suppressions',
                section: 'suppressions',
                href: `${props.projectPath}/suppressions`,
                icon: SlidersHorizontal,
                count: props.counts.suppressions ?? null,
            },
        ],
    },
    {
        label: 'Developer',
        items: [
            {
                label: 'Inbound events',
                section: 'inbound',
                href: `${props.projectPath}/inbound`,
                icon: Activity,
                count: props.counts.inbound ?? null,
            },
            {
                label: 'Webhooks',
                section: 'webhooks',
                href: `${props.projectPath}/webhooks`,
                icon: Webhook,
                count: null,
            },
            {
                label: 'API keys',
                section: 'api-keys',
                href: `${props.projectPath}/api-keys`,
                icon: KeyRound,
                count: null,
            },
        ],
    },
    {
        label: 'Settings',
        items: [
            {
                label: 'Sending source',
                section: 'setup',
                href: `${props.projectPath}/setup`,
                icon: Settings2,
                count: null,
            },
            {
                label: 'Domains',
                section: 'identities',
                href: `${props.projectPath}/identities`,
                icon: Globe2,
                count: null,
            },
            {
                label: 'Workspace',
                section: 'projects',
                href: `${props.projectPath}/projects`,
                icon: Users,
                count: null,
            },
        ],
    },
]);

const mobileItems = computed(() => [
    navigationGroups.value[0].items[0],
    navigationGroups.value[0].items[1],
    navigationGroups.value[0].items[2],
    {
        ...navigationGroups.value[2].items[0],
        label: 'Delivery',
    },
]);
</script>

<template>
    <aside
        class="hidden min-h-0 flex-col border-r border-zinc-200 bg-[#fbfaf7] lg:col-start-1 lg:row-start-1 lg:row-end-3 lg:flex dark:border-[#1d2125] dark:bg-[#0b0c0d]"
    >
        <div class="border-b border-zinc-200 p-3 dark:border-[#1d2125]">
            <Link
                href="/dashboard"
                class="mb-3 flex h-9 items-center gap-2.5 rounded-lg px-2 text-sm font-semibold tracking-tight text-zinc-950 dark:text-zinc-100"
            >
                <span
                    class="grid size-7 place-items-center rounded-lg bg-teal-300 font-mono text-xs font-bold text-[#07221c]"
                >
                    L
                </span>
                larasend
            </Link>

            <div class="relative">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-left transition hover:border-zinc-300 dark:border-[#262a2e] dark:bg-[#111315] dark:hover:border-[#343a40]"
                    @click="projectMenuOpen = !projectMenuOpen"
                >
                    <span class="grid min-w-0 flex-1 gap-0.5">
                        <span class="truncate text-sm font-semibold">
                            {{ projectName }}
                        </span>
                        <span
                            class="truncate font-mono text-[11px] text-zinc-500"
                        >
                            {{ projectSlug }}
                        </span>
                    </span>
                    <ChevronDown
                        class="size-4 shrink-0 text-zinc-400 transition"
                        :class="{ 'rotate-180': projectMenuOpen }"
                    />
                </button>

                <div
                    v-if="projectMenuOpen"
                    class="absolute top-full right-0 left-0 z-40 mt-1 overflow-hidden rounded-lg border border-zinc-200 bg-white p-1 shadow-xl shadow-zinc-950/10 dark:border-[#262a2e] dark:bg-[#111315]"
                >
                    <Link
                        v-for="project in projects"
                        :key="project.slug"
                        :href="project.href"
                        class="grid gap-0.5 rounded-md px-2.5 py-2 hover:bg-zinc-100 dark:hover:bg-[#1a1e22]"
                        :class="{
                            'bg-zinc-100 dark:bg-[#1a1e22]': project.is_current,
                        }"
                    >
                        <span class="truncate text-sm font-semibold">
                            {{ project.name }}
                        </span>
                        <span
                            class="truncate font-mono text-[10.5px] text-zinc-500"
                        >
                            {{ project.environment ?? project.slug }}
                            <template v-if="project.provider_label">
                                · {{ project.provider_label }}
                            </template>
                        </span>
                    </Link>
                    <Link
                        :href="`${projectPath}/projects`"
                        class="mt-1 flex items-center gap-2 border-t border-zinc-100 px-2.5 py-2 text-xs font-semibold text-zinc-600 hover:text-zinc-950 dark:border-[#1d2125] dark:text-zinc-400 dark:hover:text-zinc-100"
                    >
                        <Users class="size-3.5" />
                        Manage projects
                    </Link>
                </div>
            </div>
        </div>

        <nav class="min-h-0 flex-1 overflow-y-auto px-3 py-3">
            <section
                v-for="group in navigationGroups"
                :key="group.label"
                class="mb-4 last:mb-0"
            >
                <h2
                    class="mb-1 px-2 font-mono text-[10px] font-semibold tracking-[0.14em] text-zinc-400 uppercase dark:text-[#6c7177]"
                >
                    {{ group.label }}
                </h2>
                <div class="grid gap-0.5">
                    <Link
                        v-for="item in group.items"
                        :key="item.section"
                        :href="item.href"
                        class="relative flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 text-[13px] font-medium transition"
                        :class="
                            section === item.section
                                ? 'bg-teal-50 text-zinc-950 dark:bg-teal-400/10 dark:text-zinc-100'
                                : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-950 dark:text-[#9aa0a6] dark:hover:bg-[#16191c] dark:hover:text-zinc-100'
                        "
                    >
                        <component :is="item.icon" class="size-4 shrink-0" />
                        <span class="min-w-0 flex-1 truncate">{{
                            item.label
                        }}</span>
                        <span
                            v-if="item.count"
                            class="rounded-full bg-zinc-200 px-1.5 font-mono text-[10px] text-zinc-600 dark:bg-[#25292d] dark:text-zinc-300"
                        >
                            {{ item.count > 999 ? '999+' : item.count }}
                        </span>
                    </Link>
                </div>
            </section>
        </nav>

        <div class="border-t border-zinc-200 p-3 dark:border-[#1d2125]">
            <Link
                href="/settings/profile"
                class="flex items-center gap-3 rounded-lg p-2 transition hover:bg-zinc-100 dark:hover:bg-[#16191c]"
            >
                <span
                    class="grid size-8 shrink-0 place-items-center rounded-full bg-gradient-to-br from-violet-300 to-teal-300 font-mono text-[10px] font-semibold text-[#0b0c0d]"
                >
                    {{ userInitials }}
                </span>
                <span class="grid min-w-0 flex-1 gap-0.5">
                    <span class="truncate text-xs font-semibold">
                        {{ page.props.auth.user.name }}
                    </span>
                    <span class="truncate text-[11px] text-zinc-500">
                        {{ page.props.auth.user.email }}
                    </span>
                </span>
            </Link>
            <p
                v-if="buildLabel"
                class="mt-2 px-2 font-mono text-[9.5px] text-zinc-400 dark:text-[#6c7177]"
            >
                {{ buildLabel }}
            </p>
        </div>
    </aside>

    <button
        v-if="mobileMenuOpen"
        type="button"
        class="fixed inset-0 z-50 bg-zinc-950/45 backdrop-blur-[2px] lg:hidden"
        aria-label="Close navigation"
        @click="mobileMenuOpen = false"
    />

    <section
        v-if="mobileMenuOpen"
        class="fixed right-0 bottom-16 left-0 z-[60] max-h-[78vh] overflow-y-auto rounded-t-2xl border-t border-zinc-200 bg-[#fbfaf7] px-4 pt-4 pb-6 shadow-2xl lg:hidden dark:border-[#262a2e] dark:bg-[#0b0c0d]"
        aria-label="All navigation"
    >
        <div class="mb-4 flex items-center gap-3">
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold">{{ projectName }}</p>
                <p class="truncate font-mono text-[11px] text-zinc-500">
                    {{ projectSlug }}
                </p>
            </div>
            <button
                type="button"
                class="grid size-9 place-items-center rounded-lg border border-zinc-200 text-zinc-500 dark:border-[#262a2e]"
                aria-label="Close navigation"
                @click="mobileMenuOpen = false"
            >
                <X class="size-4" />
            </button>
        </div>

        <nav class="grid gap-5 sm:grid-cols-2">
            <section
                v-for="group in navigationGroups"
                :key="group.label"
                class="grid content-start gap-1"
            >
                <h2
                    class="px-2 font-mono text-[10px] font-semibold tracking-[0.14em] text-zinc-400 uppercase dark:text-[#6c7177]"
                >
                    {{ group.label }}
                </h2>
                <Link
                    v-for="item in group.items"
                    :key="item.section"
                    :href="item.href"
                    class="flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-medium"
                    :class="
                        section === item.section
                            ? 'bg-teal-50 text-zinc-950 dark:bg-teal-400/10 dark:text-zinc-100'
                            : 'text-zinc-600 dark:text-[#9aa0a6]'
                    "
                    @click="mobileMenuOpen = false"
                >
                    <component :is="item.icon" class="size-4 shrink-0" />
                    <span class="min-w-0 flex-1 truncate">{{
                        item.label
                    }}</span>
                    <span
                        v-if="item.count"
                        class="rounded-full bg-zinc-200 px-1.5 font-mono text-[10px] dark:bg-[#25292d]"
                    >
                        {{ item.count > 999 ? '999+' : item.count }}
                    </span>
                </Link>
            </section>
        </nav>
    </section>

    <nav
        class="fixed right-0 bottom-0 left-0 z-50 grid h-16 grid-cols-5 border-t border-zinc-200 bg-[#fbfaf7]/95 px-1 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden dark:border-[#1d2125] dark:bg-[#0b0c0d]/95"
        aria-label="Primary navigation"
    >
        <Link
            v-for="item in mobileItems"
            :key="item.section"
            :href="item.href"
            class="grid place-items-center content-center gap-1 text-[10px] font-medium"
            :class="
                section === item.section
                    ? 'text-teal-700 dark:text-teal-300'
                    : 'text-zinc-500'
            "
        >
            <component :is="item.icon" class="size-5" />
            <span class="truncate">{{ item.label }}</span>
        </Link>
        <button
            type="button"
            class="grid place-items-center content-center gap-1 text-[10px] font-medium text-zinc-500"
            :class="{ 'text-teal-700 dark:text-teal-300': mobileMenuOpen }"
            aria-label="Open all navigation"
            @click="mobileMenuOpen = true"
        >
            <Settings2 class="size-5" />
            <span>More</span>
        </button>
    </nav>
</template>
