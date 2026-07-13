<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    ArrowUpRight,
    CheckCircle2,
    CircleDot,
    Clock3,
    Globe2,
    Inbox,
    KeyRound,
    Server,
    UserRoundCheck,
    Webhook,
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
    message_count: number;
    unread: boolean;
    status: string;
    priority: string;
    assigned_to: string | null;
    last_activity_human: string | null;
};

type Dashboard = {
    outbound: {
        total: number;
        failed: number;
        queued: number;
        bounced: number;
        complained: number;
    };
    inbox: {
        open: number;
        unread: number;
        mine: number;
        unassigned: number;
        urgent: number;
        pending: number;
        snoozed: number;
    };
    configuration: {
        provider: string;
        source_ready: boolean;
        domains: number;
        verified_domains: number;
        inbound_domains: number;
        quota: {
            sent: number;
            limit: number | null;
            sentLast24Hours: number | null;
            checkedAt: string | null;
        };
    };
    developer: {
        active_webhooks: number;
        failing_webhooks: number;
        api_keys: number;
        expiring_api_keys: number;
    };
    trend: {
        label: string;
        sent: number;
        delivered: number;
        failed: number;
    }[];
    attention: {
        key: string;
        label: string;
        description: string;
        count: number;
        section: string;
        tone: string;
    }[];
};

const props = defineProps<{
    projectSlug: string;
    metrics: Metric[];
    emails: EmailRow[];
    recentThreads: ThreadSummary[];
    dashboard: Dashboard;
    system: {
        worker_alive: boolean;
        worker_last_seen: string | null;
        scheduler_alive: boolean;
        scheduler_last_seen: string | null;
        stuck_queued: number;
    };
}>();

const recentOutbound = computed(() => props.emails.slice(0, 5));
const maxTrendValue = computed(() =>
    Math.max(...props.dashboard.trend.map((point) => point.sent), 1),
);
const quotaPercent = computed(() => {
    const quota = props.dashboard.configuration.quota;
    const sent = quota.sentLast24Hours ?? quota.sent;

    return quota.limit
        ? Math.min(100, Math.round((sent / quota.limit) * 100))
        : null;
});

function sectionUrl(section: string, query?: Record<string, string>): string {
    return projectSection.url(
        { project: props.projectSlug, section },
        query ? { query } : undefined,
    );
}

function metricUrl(metric: Metric): string {
    if (metric.label === 'Bounce rate') {
        return sectionUrl('bounces');
    }

    if (metric.label === 'Complaints') {
        return sectionUrl('complaints');
    }

    return sectionUrl('outbound');
}

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

function attentionTone(tone: string): string {
    return tone === 'danger'
        ? 'border-red-200 bg-red-50/80 text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-200'
        : 'border-amber-200 bg-amber-50/80 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200';
}
</script>

<template>
    <div class="grid max-w-7xl gap-5 pb-2">
        <section
            v-if="dashboard.attention.length"
            class="rounded-xl border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-500/20 dark:bg-amber-500/5"
        >
            <div class="flex items-start gap-3">
                <span
                    class="grid size-9 shrink-0 place-items-center rounded-lg bg-amber-500/10 text-amber-700 dark:text-amber-300"
                >
                    <AlertTriangle class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <div>
                            <h2 class="text-sm font-semibold">
                                Needs attention
                            </h2>
                            <p class="mt-0.5 text-xs text-zinc-500">
                                Operational issues and team work that should be
                                handled next.
                            </p>
                        </div>
                        <span
                            class="rounded-full bg-amber-500/10 px-2.5 py-1 font-mono text-[10px] font-semibold text-amber-700 dark:text-amber-300"
                        >
                            {{ dashboard.attention.length }}
                            {{
                                dashboard.attention.length === 1
                                    ? 'item'
                                    : 'items'
                            }}
                        </span>
                    </div>
                    <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                        <Link
                            v-for="item in dashboard.attention"
                            :key="item.key"
                            :href="
                                sectionUrl(
                                    item.section,
                                    item.key === 'unassigned'
                                        ? { assigned: 'unassigned' }
                                        : undefined,
                                )
                            "
                            class="group flex items-center gap-3 rounded-lg border px-3 py-2.5 transition hover:-translate-y-0.5 hover:shadow-sm"
                            :class="attentionTone(item.tone)"
                        >
                            <strong class="font-mono text-lg">{{
                                item.count
                            }}</strong>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-xs font-semibold"
                                    >{{ item.label }}</span
                                >
                                <span
                                    class="mt-0.5 block truncate text-[11px] opacity-70"
                                    >{{ item.description }}</span
                                >
                            </span>
                            <ArrowUpRight
                                class="size-3.5 shrink-0 opacity-60 transition group-hover:opacity-100"
                            />
                        </Link>
                    </div>
                </div>
            </div>
        </section>

        <section
            v-else
            class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-500/20 dark:bg-emerald-500/5"
        >
            <span
                class="grid size-9 place-items-center rounded-lg bg-emerald-500/10 text-emerald-700 dark:text-emerald-300"
            >
                <CheckCircle2 class="size-4" />
            </span>
            <div>
                <h2 class="text-sm font-semibold">Everything looks healthy</h2>
                <p class="mt-0.5 text-xs text-zinc-500">
                    No delivery, inbox, webhook, or infrastructure issues need
                    attention.
                </p>
            </div>
        </section>

        <section
            class="grid grid-cols-2 overflow-hidden rounded-xl border border-zinc-200 bg-white sm:grid-cols-3 xl:grid-cols-6 dark:border-[#25292d] dark:bg-[#111315]"
        >
            <Link
                v-for="metric in metrics"
                :key="metric.label"
                :href="metricUrl(metric)"
                class="group min-w-0 border-r border-b border-zinc-200 p-4 transition hover:bg-zinc-50 sm:nth-[3n]:border-r-0 xl:border-b-0 xl:nth-[3n]:border-r xl:nth-[6n]:border-r-0 dark:border-[#25292d] dark:hover:bg-[#16191c]"
            >
                <div class="flex items-center justify-between gap-2">
                    <div
                        class="truncate font-mono text-[10px] font-semibold tracking-widest text-zinc-500 uppercase"
                    >
                        {{ metric.label }}
                    </div>
                    <ArrowUpRight
                        class="size-3 text-zinc-300 transition group-hover:text-zinc-600 dark:group-hover:text-zinc-300"
                    />
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
            </Link>
        </section>

        <section
            class="grid gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(300px,1fr)]"
        >
            <div
                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-[#25292d] dark:bg-[#111315]"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Delivery trend</h2>
                        <p class="mt-0.5 text-xs text-zinc-500">
                            Submitted, delivered, and failed messages in the
                            selected period.
                        </p>
                    </div>
                    <div
                        class="flex items-center gap-3 font-mono text-[10px] text-zinc-500"
                    >
                        <span class="flex items-center gap-1.5"
                            ><i class="size-2 rounded-full bg-teal-500"></i
                            >submitted</span
                        >
                        <span class="flex items-center gap-1.5"
                            ><i class="size-2 rounded-full bg-emerald-300"></i
                            >delivered</span
                        >
                        <span class="flex items-center gap-1.5"
                            ><i class="size-2 rounded-full bg-red-400"></i
                            >failed</span
                        >
                    </div>
                </div>
                <div
                    class="mt-5 grid h-40 grid-cols-6 items-end gap-2 sm:grid-cols-8"
                >
                    <div
                        v-for="point in dashboard.trend"
                        :key="point.label"
                        class="group flex h-full min-w-0 flex-col justify-end gap-1"
                    >
                        <div
                            class="relative flex min-h-1 flex-1 items-end overflow-hidden rounded-t-md bg-zinc-100 dark:bg-zinc-800/70"
                        >
                            <div
                                class="absolute inset-x-0 bottom-0 bg-teal-500/35 transition group-hover:bg-teal-500/50"
                                :style="{
                                    height: `${Math.max(4, (point.sent / maxTrendValue) * 100)}%`,
                                }"
                            ></div>
                            <div
                                class="absolute inset-x-0 bottom-0 bg-emerald-400/70"
                                :style="{
                                    height: `${(point.delivered / maxTrendValue) * 100}%`,
                                }"
                            ></div>
                            <div
                                v-if="point.failed"
                                class="absolute inset-x-0 top-0 h-1 bg-red-400"
                            ></div>
                        </div>
                        <div
                            class="truncate text-center font-mono text-[9px] text-zinc-400"
                        >
                            {{ point.label }}
                        </div>
                        <div
                            class="text-center font-mono text-[9px] text-zinc-500"
                        >
                            {{ point.sent }}
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-[#25292d] dark:bg-[#111315]"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Sending readiness</h2>
                        <p class="mt-0.5 text-xs text-zinc-500">
                            Provider, domains, inbound, and quota.
                        </p>
                    </div>
                    <span
                        class="grid size-9 place-items-center rounded-lg"
                        :class="
                            dashboard.configuration.source_ready
                                ? 'bg-emerald-500/10 text-emerald-600'
                                : 'bg-amber-500/10 text-amber-600'
                        "
                    >
                        <CheckCircle2
                            v-if="dashboard.configuration.source_ready"
                            class="size-4"
                        />
                        <AlertTriangle v-else class="size-4" />
                    </span>
                </div>
                <div class="mt-4 grid gap-2">
                    <Link
                        :href="sectionUrl('source')"
                        class="flex items-center gap-3 rounded-lg bg-zinc-50 px-3 py-2.5 hover:bg-zinc-100 dark:bg-[#16191c] dark:hover:bg-[#1b1f23]"
                    >
                        <Server class="size-4 text-teal-600" /><span
                            class="min-w-0 flex-1 text-xs"
                            ><b class="block truncate">{{
                                dashboard.configuration.provider
                            }}</b
                            ><span class="text-zinc-500"
                                >Email provider</span
                            ></span
                        ><ArrowUpRight class="size-3.5 text-zinc-400" />
                    </Link>
                    <Link
                        :href="sectionUrl('identities')"
                        class="flex items-center gap-3 rounded-lg bg-zinc-50 px-3 py-2.5 hover:bg-zinc-100 dark:bg-[#16191c] dark:hover:bg-[#1b1f23]"
                    >
                        <Globe2 class="size-4 text-violet-500" /><span
                            class="min-w-0 flex-1 text-xs"
                            ><b class="block"
                                >{{
                                    dashboard.configuration.verified_domains
                                }}/{{
                                    dashboard.configuration.domains
                                }}
                                verified</b
                            ><span class="text-zinc-500"
                                >Sending domains ·
                                {{ dashboard.configuration.inbound_domains }}
                                inbound</span
                            ></span
                        ><ArrowUpRight class="size-3.5 text-zinc-400" />
                    </Link>
                    <div
                        class="rounded-lg bg-zinc-50 px-3 py-2.5 dark:bg-[#16191c]"
                    >
                        <div
                            class="flex items-center justify-between gap-3 text-xs"
                        >
                            <span class="flex items-center gap-2"
                                ><Activity class="size-4 text-blue-500" /><b
                                    >Provider quota</b
                                ></span
                            ><span
                                class="font-mono text-[10px] text-zinc-500"
                                >{{
                                    quotaPercent === null
                                        ? 'not synced'
                                        : `${quotaPercent}% used`
                                }}</span
                            >
                        </div>
                        <div
                            class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"
                        >
                            <div
                                class="h-full rounded-full bg-blue-500"
                                :style="{ width: `${quotaPercent ?? 0}%` }"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Link
                :href="sectionUrl('inbox')"
                class="group rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-violet-300 hover:shadow-sm dark:border-[#25292d] dark:bg-[#111315]"
            >
                <div class="flex items-center justify-between">
                    <span
                        class="grid size-9 place-items-center rounded-lg bg-violet-500/10 text-violet-600"
                        ><Inbox class="size-4" /></span
                    ><ArrowUpRight class="size-4 text-zinc-400" />
                </div>
                <div class="mt-4 text-2xl font-semibold">
                    {{ dashboard.inbox.open }}
                </div>
                <div class="mt-1 text-sm font-medium">Open conversations</div>
                <p class="mt-1 text-xs text-zinc-500">
                    {{ dashboard.inbox.unread }} unread ·
                    {{ dashboard.inbox.pending }} pending
                </p>
            </Link>
            <Link
                :href="sectionUrl('inbox', { assigned: 'mine' })"
                class="group rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-teal-300 hover:shadow-sm dark:border-[#25292d] dark:bg-[#111315]"
            >
                <div class="flex items-center justify-between">
                    <span
                        class="grid size-9 place-items-center rounded-lg bg-teal-500/10 text-teal-700"
                        ><UserRoundCheck class="size-4" /></span
                    ><ArrowUpRight class="size-4 text-zinc-400" />
                </div>
                <div class="mt-4 text-2xl font-semibold">
                    {{ dashboard.inbox.mine }}
                </div>
                <div class="mt-1 text-sm font-medium">Assigned to me</div>
                <p class="mt-1 text-xs text-zinc-500">
                    Your active inbox workload.
                </p>
            </Link>
            <Link
                :href="sectionUrl('inbox', { assigned: 'unassigned' })"
                class="group rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-amber-300 hover:shadow-sm dark:border-[#25292d] dark:bg-[#111315]"
            >
                <div class="flex items-center justify-between">
                    <span
                        class="grid size-9 place-items-center rounded-lg bg-amber-500/10 text-amber-700"
                        ><CircleDot class="size-4" /></span
                    ><ArrowUpRight class="size-4 text-zinc-400" />
                </div>
                <div class="mt-4 text-2xl font-semibold">
                    {{ dashboard.inbox.unassigned }}
                </div>
                <div class="mt-1 text-sm font-medium">Unassigned</div>
                <p class="mt-1 text-xs text-zinc-500">
                    {{ dashboard.inbox.urgent }} urgent conversation{{
                        dashboard.inbox.urgent === 1 ? '' : 's'
                    }}.
                </p>
            </Link>
            <Link
                :href="sectionUrl('inbox', { mailbox: 'snoozed' })"
                class="group rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-blue-300 hover:shadow-sm dark:border-[#25292d] dark:bg-[#111315]"
            >
                <div class="flex items-center justify-between">
                    <span
                        class="grid size-9 place-items-center rounded-lg bg-blue-500/10 text-blue-600"
                        ><Clock3 class="size-4" /></span
                    ><ArrowUpRight class="size-4 text-zinc-400" />
                </div>
                <div class="mt-4 text-2xl font-semibold">
                    {{ dashboard.inbox.snoozed }}
                </div>
                <div class="mt-1 text-sm font-medium">Snoozed</div>
                <p class="mt-1 text-xs text-zinc-500">
                    Conversations scheduled to return.
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
                        >View all {{ dashboard.outbound.total }}</Link
                    >
                </div>
                <div
                    v-if="recentOutbound.length"
                    class="divide-y divide-zinc-200 dark:divide-[#25292d]"
                >
                    <Link
                        v-for="email in recentOutbound"
                        :key="email.id"
                        :href="sectionUrl('outbound', { q: email.id })"
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
                            Ownership and priority at a glance.
                        </p>
                    </div>
                    <Link
                        :href="sectionUrl('inbox')"
                        class="text-xs font-semibold text-teal-700 hover:underline dark:text-teal-300"
                        >Open Inbox</Link
                    >
                </div>
                <div
                    v-if="recentThreads.length"
                    class="divide-y divide-zinc-200 dark:divide-[#25292d]"
                >
                    <Link
                        v-for="thread in recentThreads"
                        :key="thread.public_id"
                        :href="
                            sectionUrl('inbox', { thread: thread.public_id })
                        "
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
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="truncate text-sm font-medium">{{
                                    thread.subject || '(no subject)'
                                }}</span
                                ><span
                                    v-if="thread.priority === 'urgent'"
                                    class="rounded bg-red-500/10 px-1.5 py-0.5 font-mono text-[9px] text-red-600"
                                    >urgent</span
                                >
                            </div>
                            <div class="mt-0.5 truncate text-xs text-zinc-500">
                                {{ thread.assigned_to || 'Unassigned' }} ·
                                {{ thread.status }} ·
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

        <section class="grid gap-3 sm:grid-cols-3">
            <Link
                :href="sectionUrl('webhooks')"
                class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-[#25292d] dark:bg-[#111315]"
                ><span
                    class="grid size-9 place-items-center rounded-lg bg-violet-500/10 text-violet-600"
                    ><Webhook class="size-4" /></span
                ><span class="min-w-0 flex-1"
                    ><b class="block text-sm"
                        >{{ dashboard.developer.active_webhooks }} active
                        webhooks</b
                    ><span class="text-xs text-zinc-500"
                        >{{ dashboard.developer.failing_webhooks }} failures
                        this period</span
                    ></span
                ><ArrowUpRight class="size-4 text-zinc-400"
            /></Link>
            <Link
                :href="sectionUrl('api-keys')"
                class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-[#25292d] dark:bg-[#111315]"
                ><span
                    class="grid size-9 place-items-center rounded-lg bg-blue-500/10 text-blue-600"
                    ><KeyRound class="size-4" /></span
                ><span class="min-w-0 flex-1"
                    ><b class="block text-sm"
                        >{{ dashboard.developer.api_keys }} API keys</b
                    ><span class="text-xs text-zinc-500"
                        >{{ dashboard.developer.expiring_api_keys }} expiring
                        soon</span
                    ></span
                ><ArrowUpRight class="size-4 text-zinc-400"
            /></Link>
            <div
                class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-[#25292d] dark:bg-[#111315]"
            >
                <span
                    class="grid size-9 place-items-center rounded-lg"
                    :class="
                        system.worker_alive && system.scheduler_alive
                            ? 'bg-emerald-500/10 text-emerald-600'
                            : 'bg-amber-500/10 text-amber-600'
                    "
                    ><Server class="size-4" /></span
                ><span class="min-w-0 flex-1"
                    ><b class="block text-sm">Infrastructure</b
                    ><span class="text-xs text-zinc-500"
                        >Worker
                        {{ system.worker_alive ? 'online' : 'offline' }} ·
                        Scheduler
                        {{
                            system.scheduler_alive ? 'online' : 'offline'
                        }}</span
                    ></span
                >
            </div>
        </section>
    </div>
</template>
