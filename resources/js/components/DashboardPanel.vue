<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowUpRight,
    CheckCircle2,
    Inbox,
    Send,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { section as projectSection } from '@/routes/projects';

type Metric = {
    label: string;
    value: string;
    delta: string | null;
    trend: 'up' | 'down' | 'neutral';
    tone: 'good' | 'bad' | 'neutral';
};

type EmailRow = {
    id: string;
    recipient: string;
    subject: string;
    status: string;
    time: string;
};

type ThreadSummary = {
    public_id: string;
    subject: string | null;
    participants: string[];
    snippet: string | null;
    direction: string | null;
    message_count: number;
    unread: boolean;
    last_activity_human: string | null;
};

const props = defineProps<{
    projectSlug: string;
    metrics: Metric[];
    emails: EmailRow[];
    recentThreads: ThreadSummary[];
    inboxUnread: number;
    sourceReady: boolean;
    system: {
        worker_alive: boolean;
        worker_last_seen: string | null;
        scheduler_alive: boolean;
        scheduler_last_seen: string | null;
        stuck_queued: number;
    };
}>();

const recentOutbound = computed(() => props.emails.slice(0, 5));
const systemReady = computed(
    () => props.system.worker_alive && props.system.scheduler_alive,
);

function metricTone(metric: Metric): string {
    if (metric.tone === 'good') {
        return 'text-emerald-600 dark:text-emerald-300';
    }

    if (metric.tone === 'bad') {
        return 'text-red-600 dark:text-red-300';
    }

    return 'text-zinc-500';
}

function statusTone(status: string): string {
    if (['delivered', 'opened', 'clicked', 'sent'].includes(status)) {
        return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }

    if (['bounced', 'complained', 'failed'].includes(status)) {
        return 'bg-red-500/10 text-red-700 dark:text-red-300';
    }

    return 'bg-amber-500/10 text-amber-700 dark:text-amber-300';
}

function sectionUrl(section: string): string {
    return projectSection.url({ project: props.projectSlug, section });
}
</script>

<template>
    <div class="grid max-w-7xl gap-5">
        <section
            class="grid grid-cols-2 overflow-hidden rounded-xl border border-zinc-200 bg-white sm:grid-cols-3 xl:grid-cols-6 dark:border-[#25292d] dark:bg-[#111315]"
        >
            <div
                v-for="metric in metrics"
                :key="metric.label"
                class="min-w-0 border-r border-b border-zinc-200 p-4 last:border-r-0 sm:nth-[3n]:border-r-0 xl:border-b-0 xl:nth-[3n]:border-r xl:nth-[6n]:border-r-0 dark:border-[#25292d]"
            >
                <div
                    class="truncate font-mono text-[10px] font-semibold tracking-widest text-zinc-500 uppercase"
                >
                    {{ metric.label }}
                </div>
                <div class="mt-2 text-2xl font-semibold tracking-tight">
                    {{ metric.value }}
                </div>
                <div
                    v-if="metric.delta"
                    class="mt-1.5 font-mono text-[10px]"
                    :class="metricTone(metric)"
                >
                    {{
                        metric.trend === 'up'
                            ? '▲'
                            : metric.trend === 'down'
                              ? '▼'
                              : '•'
                    }}
                    {{ metric.delta }}
                </div>
                <div v-else class="mt-1.5 font-mono text-[10px] text-zinc-400">
                    selected period
                </div>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-3">
            <Link
                :href="sectionUrl('inbox')"
                class="group rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 hover:shadow-sm dark:border-[#25292d] dark:bg-[#111315] dark:hover:border-zinc-600"
            >
                <div class="flex items-center justify-between gap-3">
                    <span
                        class="grid size-9 place-items-center rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-300"
                    >
                        <Inbox class="size-4" />
                    </span>
                    <ArrowUpRight
                        class="size-4 text-zinc-400 transition group-hover:text-zinc-700 dark:group-hover:text-zinc-200"
                    />
                </div>
                <div class="mt-4 text-2xl font-semibold">{{ inboxUnread }}</div>
                <div class="mt-1 text-sm font-medium">Unread conversations</div>
                <p class="mt-1 text-xs text-zinc-500">
                    Messages that may need a reply.
                </p>
            </Link>

            <Link
                :href="sectionUrl('outbound')"
                class="group rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 hover:shadow-sm dark:border-[#25292d] dark:bg-[#111315] dark:hover:border-zinc-600"
            >
                <div class="flex items-center justify-between gap-3">
                    <span
                        class="grid size-9 place-items-center rounded-lg bg-teal-500/10 text-teal-700 dark:text-teal-300"
                    >
                        <Send class="size-4" />
                    </span>
                    <ArrowUpRight
                        class="size-4 text-zinc-400 transition group-hover:text-zinc-700 dark:group-hover:text-zinc-200"
                    />
                </div>
                <div class="mt-4 text-2xl font-semibold">
                    {{ emails.length }}
                </div>
                <div class="mt-1 text-sm font-medium">
                    Outbound in this view
                </div>
                <p class="mt-1 text-xs text-zinc-500">
                    All delivery states in the selected period.
                </p>
            </Link>

            <Link
                :href="sectionUrl(sourceReady ? 'source' : 'setup')"
                class="group rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 hover:shadow-sm dark:border-[#25292d] dark:bg-[#111315] dark:hover:border-zinc-600"
            >
                <div class="flex items-center justify-between gap-3">
                    <span
                        class="grid size-9 place-items-center rounded-lg"
                        :class="
                            sourceReady
                                ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300'
                                : 'bg-amber-500/10 text-amber-600 dark:text-amber-300'
                        "
                    >
                        <CheckCircle2 v-if="sourceReady" class="size-4" />
                        <AlertTriangle v-else class="size-4" />
                    </span>
                    <ArrowUpRight
                        class="size-4 text-zinc-400 transition group-hover:text-zinc-700 dark:group-hover:text-zinc-200"
                    />
                </div>
                <div class="mt-4 text-lg font-semibold">
                    {{ sourceReady ? 'Ready to send' : 'Setup required' }}
                </div>
                <div class="mt-1 text-sm font-medium">Email provider</div>
                <p class="mt-1 text-xs text-zinc-500">
                    {{
                        sourceReady
                            ? 'Provider and domain are ready.'
                            : 'Finish the required sending setup.'
                    }}
                </p>
            </Link>
        </section>

        <section class="grid gap-5 xl:grid-cols-2">
            <div
                class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-[#25292d] dark:bg-[#111315]"
            >
                <div
                    class="flex items-center justify-between gap-4 border-b border-zinc-200 px-4 py-3.5 dark:border-[#25292d]"
                >
                    <div>
                        <h2 class="font-semibold">Recent outbound</h2>
                        <p class="mt-0.5 text-xs text-zinc-500">
                            Latest transactional delivery activity.
                        </p>
                    </div>
                    <Link
                        :href="sectionUrl('outbound')"
                        class="text-xs font-semibold text-teal-700 hover:underline dark:text-teal-300"
                    >
                        View all
                    </Link>
                </div>
                <div
                    v-if="recentOutbound.length"
                    class="divide-y divide-zinc-200 dark:divide-[#25292d]"
                >
                    <Link
                        v-for="email in recentOutbound"
                        :key="email.id"
                        :href="`${sectionUrl('outbound')}?q=${encodeURIComponent(email.id)}`"
                        class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-4 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-[#16191c]"
                    >
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">
                                {{ email.subject }}
                            </div>
                            <div
                                class="mt-0.5 truncate font-mono text-[10.5px] text-zinc-500"
                            >
                                {{ email.recipient || 'No recipient' }}
                            </div>
                        </div>
                        <div class="text-right">
                            <span
                                class="rounded-md px-2 py-1 font-mono text-[10px] uppercase"
                                :class="statusTone(email.status)"
                                >{{ email.status }}</span
                            >
                            <div
                                class="mt-1 font-mono text-[10px] text-zinc-400"
                            >
                                {{ email.time }}
                            </div>
                        </div>
                    </Link>
                </div>
                <div v-else class="p-8 text-center text-sm text-zinc-500">
                    No outbound messages in this period.
                </div>
            </div>

            <div
                class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-[#25292d] dark:bg-[#111315]"
            >
                <div
                    class="flex items-center justify-between gap-4 border-b border-zinc-200 px-4 py-3.5 dark:border-[#25292d]"
                >
                    <div>
                        <h2 class="font-semibold">Recent conversations</h2>
                        <p class="mt-0.5 text-xs text-zinc-500">
                            Inbox threads with the latest activity.
                        </p>
                    </div>
                    <Link
                        :href="sectionUrl('inbox')"
                        class="text-xs font-semibold text-teal-700 hover:underline dark:text-teal-300"
                    >
                        Open Inbox
                    </Link>
                </div>
                <div
                    v-if="recentThreads.length"
                    class="divide-y divide-zinc-200 dark:divide-[#25292d]"
                >
                    <Link
                        v-for="thread in recentThreads"
                        :key="thread.public_id"
                        :href="`${sectionUrl('inbox')}?thread=${encodeURIComponent(thread.public_id)}`"
                        class="grid grid-cols-[8px_minmax(0,1fr)_auto] items-center gap-3 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-[#16191c]"
                    >
                        <span
                            class="size-2 rounded-full"
                            :class="
                                thread.unread
                                    ? 'bg-violet-500'
                                    : 'bg-zinc-300 dark:bg-zinc-700'
                            "
                        />
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium">
                                {{ thread.subject || '(no subject)' }}
                            </div>
                            <div class="mt-0.5 truncate text-xs text-zinc-500">
                                {{
                                    thread.snippet ||
                                    thread.participants.join(', ')
                                }}
                            </div>
                        </div>
                        <div
                            class="text-right font-mono text-[10px] text-zinc-400"
                        >
                            <div>{{ thread.last_activity_human || 'now' }}</div>
                            <div class="mt-1">
                                {{ thread.message_count }} msg
                            </div>
                        </div>
                    </Link>
                </div>
                <div v-else class="p-8 text-center text-sm text-zinc-500">
                    No active Inbox conversations yet.
                </div>
            </div>
        </section>

        <section
            class="flex flex-wrap items-center gap-4 rounded-xl border p-4"
            :class="
                systemReady
                    ? 'border-zinc-200 bg-white dark:border-[#25292d] dark:bg-[#111315]'
                    : 'border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10'
            "
        >
            <span
                class="grid size-9 place-items-center rounded-lg"
                :class="
                    systemReady
                        ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300'
                        : 'bg-amber-500/10 text-amber-600 dark:text-amber-300'
                "
            >
                <CheckCircle2 v-if="systemReady" class="size-4" />
                <AlertTriangle v-else class="size-4" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-sm font-semibold">System health</h2>
                <p class="mt-0.5 text-xs text-zinc-500">
                    Queue worker
                    {{
                        system.worker_alive
                            ? `running · ${system.worker_last_seen || 'recently seen'}`
                            : 'not detected'
                    }}
                    · Scheduler
                    {{
                        system.scheduler_alive
                            ? `running · ${system.scheduler_last_seen || 'recently seen'}`
                            : 'not detected'
                    }}
                </p>
            </div>
            <span
                v-if="system.stuck_queued"
                class="rounded-md bg-amber-500/10 px-2.5 py-1 font-mono text-xs text-amber-700 dark:text-amber-300"
            >
                {{ system.stuck_queued }} queued
            </span>
        </section>
    </div>
</template>
