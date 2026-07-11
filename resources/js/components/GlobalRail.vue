<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity as ActivityIcon,
    Inbox as InboxIcon,
    Settings2,
} from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    projectPath: string;
    area: 'inbox' | 'mail' | 'configure';
    inboxUnread?: number;
}>();

const page = usePage<{
    auth: { user: { name: string; email: string } };
}>();

const userInitials = computed(() =>
    page.props.auth.user.name
        .split(' ')
        .map((part: string) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);

const areas = computed(() => [
    {
        key: 'inbox',
        label: 'Inbox',
        icon: InboxIcon,
        href: `${props.projectPath}/inbox`,
        badge: props.inboxUnread || null,
    },
    {
        key: 'mail',
        label: 'Mail',
        icon: ActivityIcon,
        href: `${props.projectPath}/activity`,
        badge: null,
    },
    {
        key: 'configure',
        label: 'Configure',
        icon: Settings2,
        href: `${props.projectPath}/setup`,
        badge: null,
    },
]);
</script>

<template>
    <nav
        class="flex min-h-0 flex-col items-center gap-1 border-r border-zinc-200 bg-[#fbfaf7] px-1.5 py-2.5 dark:border-[#1d2125] dark:bg-[#0b0c0d]"
    >
        <Link
            href="/dashboard"
            class="mb-2 grid size-8 place-items-center rounded-lg bg-teal-300 font-mono text-[13px] font-bold text-[#07221c]"
            title="Larasend"
        >
            L
        </Link>

        <Link
            v-for="entry in areas"
            :key="entry.key"
            :href="entry.href"
            class="relative grid w-12 justify-items-center gap-0.5 rounded-lg py-2 transition"
            :class="
                area === entry.key
                    ? 'bg-teal-50 text-zinc-950 dark:bg-teal-400/10 dark:text-zinc-100'
                    : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-[#9aa0a6] dark:hover:bg-[#16191c] dark:hover:text-zinc-100'
            "
            :title="entry.label"
        >
            <component :is="entry.icon" class="size-[18px]" />
            <span class="font-sans text-[9.5px] font-medium">
                {{ entry.label }}
            </span>
            <span
                v-if="entry.badge"
                class="absolute top-1 right-1.5 grid min-w-4 place-items-center rounded-full bg-teal-300 px-1 font-mono text-[9px] font-bold text-[#07221c]"
            >
                {{ entry.badge > 99 ? '99+' : entry.badge }}
            </span>
        </Link>

        <div class="flex-1" />

        <Link
            href="/settings/profile"
            class="grid size-[26px] place-items-center rounded-full bg-gradient-to-br from-violet-300 to-teal-300 font-mono text-[10px] font-semibold text-[#0b0c0d]"
            :title="`${page.props.auth.user.name} · ${page.props.auth.user.email}`"
        >
            {{ userInitials }}
        </Link>
    </nav>
</template>
