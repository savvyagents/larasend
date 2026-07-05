<script setup lang="ts">
import {
    Activity,
    AlertTriangle,
    Copy,
    KeyRound,
    MailCheck,
    Power,
    RefreshCw,
    Search,
    Send,
    SlidersHorizontal,
    X,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

type PreviewRow = {
    id: string;
    recipient: string;
    email: string;
    subject: string;
    template: string;
    status: 'sent' | 'delivered' | 'opened' | 'clicked' | 'bounced';
    opens: number;
    clicks: number;
    time: string;
};

type NavItem = {
    label: string;
    icon: Component;
    count?: string;
};

const rows: PreviewRow[] = [
    {
        id: 'email_hG1L04nAQf',
        recipient: 'Northwind Support',
        email: 'support@northwind.io',
        subject: 'Form submission received',
        template: 'custom',
        status: 'sent',
        opens: 0,
        clicks: 0,
        time: 'now',
    },
    {
        id: 'email_01HZK9XQ8',
        recipient: 'Maya Okafor',
        email: 'maya@northwind.io',
        subject: 'Your receipt from Northwind',
        template: 'tx.receipt.v3',
        status: 'clicked',
        opens: 3,
        clicks: 2,
        time: '13m',
    },
    {
        id: 'email_01HZK7R2M',
        recipient: 'Darren Lin',
        email: 'darren@level.io',
        subject: 'Reset your password',
        template: 'auth.password-reset',
        status: 'opened',
        opens: 1,
        clicks: 0,
        time: '16m',
    },
    {
        id: 'email_01HZK5E8V',
        recipient: 'Hexabrew Team',
        email: 'team@hexabrew.shop',
        subject: 'Weekly digest',
        template: 'digest.weekly.v1',
        status: 'delivered',
        opens: 0,
        clicks: 0,
        time: '20m',
    },
    {
        id: 'email_01HZK3L9C',
        recipient: 'Ana del Pino',
        email: 'ana.delpino@gmail.cm',
        subject: 'Welcome to Harborlight',
        template: 'onb.welcome.v6',
        status: 'bounced',
        opens: 0,
        clicks: 0,
        time: '42m',
    },
    {
        id: 'email_01HZK2P4Q',
        recipient: 'Oliver Brandt',
        email: 'oliver@tealgrid.dev',
        subject: 'Invoice #4821',
        template: 'tx.invoice.v4',
        status: 'opened',
        opens: 2,
        clicks: 0,
        time: '52m',
    },
];

const earlierRows: PreviewRow[] = [
    {
        id: 'email_01HZJX2K7',
        recipient: 'Priya Raman',
        email: 'priya@atlaslabs.co',
        subject: 'Your export is ready',
        template: 'tx.export.v2',
        status: 'delivered',
        opens: 1,
        clicks: 0,
        time: '1h',
    },
    {
        id: 'email_01HZJT8M3',
        recipient: 'Jonas Weber',
        email: 'jonas@ferrogmbh.de',
        subject: 'Verify your email address',
        template: 'auth.verify.v2',
        status: 'clicked',
        opens: 2,
        clicks: 1,
        time: '2h',
    },
    {
        id: 'email_01HZJR5W9',
        recipient: 'Callie Mercer',
        email: 'callie@driftwoodco.com',
        subject: 'Payment failed — action needed',
        template: 'billing.dunning.v1',
        status: 'opened',
        opens: 4,
        clicks: 0,
        time: '2h',
    },
    {
        id: 'email_01HZJN1F5',
        recipient: 'Studio Nomad',
        email: 'hello@studionomad.works',
        subject: 'Your proposal was viewed',
        template: 'crm.notify.v1',
        status: 'delivered',
        opens: 0,
        clicks: 0,
        time: '3h',
    },
    {
        id: 'email_01HZJK9B2',
        recipient: 'Tomás Rivera',
        email: 'tomas@quintaverde.mx',
        subject: 'Shipping confirmation',
        template: 'tx.shipping.v2',
        status: 'clicked',
        opens: 3,
        clicks: 2,
        time: '4h',
    },
    {
        id: 'email_01HZJG4D8',
        recipient: 'June Park',
        email: 'june@luminaryapp.io',
        subject: 'Your trial ends in 3 days',
        template: 'lifecycle.trial.v3',
        status: 'opened',
        opens: 1,
        clicks: 0,
        time: '5h',
    },
];

const sentCount = ref(18420);

const metrics = computed(() => [
    {
        label: 'Sent',
        value: sentCount.value.toLocaleString('en-US'),
        delta: '+12.4%',
        tone: 'up',
    },
    { label: 'Delivery rate', value: '98.71%', delta: '+0.12%', tone: 'up' },
    { label: 'Open rate', value: '56.4%', delta: '+2.1%', tone: 'up' },
    { label: 'Click rate', value: '14.8%', delta: '-0.4%', tone: 'down' },
    { label: 'Bounce rate', value: '1.18%', delta: '+0.06%', tone: 'down' },
]);

const navItems: NavItem[] = [
    { label: 'Activity', icon: Activity, count: '16' },
    { label: 'Sent', icon: Send, count: '16' },
    { label: 'Bounces', icon: Activity, count: '2' },
    { label: 'Complaints', icon: AlertTriangle, count: '1' },
    { label: 'Suppressions', icon: Power, count: '2' },
];

const configItems: NavItem[] = [
    { label: 'Identities', icon: MailCheck },
    { label: 'Webhooks', icon: Activity },
    { label: 'API keys', icon: KeyRound },
];

const visibleCount = ref(1);
const selectedId = ref(rows[0].id);
const activeTab = ref<'timeline' | 'preview' | 'headers' | 'metrics'>(
    'preview',
);
const range = ref('14d');
const isInspectorOpen = ref(true);
const isSearching = ref(false);
let timer: ReturnType<typeof window.setInterval> | undefined;

const visibleRows = computed(() => rows.slice(0, visibleCount.value));
const selectedRow = computed(
    () =>
        [...rows, ...earlierRows].find((row) => row.id === selectedId.value) ??
        rows[0],
);
const deliveredCount = computed(
    () =>
        visibleRows.value.filter((row) =>
            ['delivered', 'opened', 'clicked'].includes(row.status),
        ).length,
);

function statusClass(status: PreviewRow['status']): string {
    return (
        {
            sent: 'is-sent',
            delivered: 'is-delivered',
            opened: 'is-opened',
            clicked: 'is-clicked',
            bounced: 'is-bounced',
        }[status] ?? 'is-sent'
    );
}

function selectRow(row: PreviewRow): void {
    selectedId.value = row.id;
    isInspectorOpen.value = true;
}

function closeInspector(): void {
    isInspectorOpen.value = false;
}

function replay(): void {
    visibleCount.value = 1;
    selectedId.value = rows[0].id;
    isInspectorOpen.value = true;
}

const tiltX = ref(2);
const tiltY = ref(-1.2);
const isTilting = ref(false);

const shellStyle = computed(() => ({
    '--tilt-x': `${tiltX.value}deg`,
    '--tilt-y': `${tiltY.value}deg`,
}));

function handleShellPointerMove(event: PointerEvent): void {
    if (
        window.matchMedia('(pointer: coarse)').matches ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
        return;
    }

    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const relativeX = (event.clientX - rect.left) / rect.width - 0.5;
    const relativeY = (event.clientY - rect.top) / rect.height - 0.5;

    tiltX.value = relativeY * -3.5;
    tiltY.value = relativeX * 4.5;
    isTilting.value = true;
}

function resetShellTilt(): void {
    tiltX.value = 2;
    tiltY.value = -1.2;
    isTilting.value = false;
}

onMounted(() => {
    timer = window.setInterval(() => {
        sentCount.value += 1 + Math.floor(Math.random() * 3);

        if (visibleCount.value < rows.length) {
            visibleCount.value += 1;
            selectedId.value = rows[visibleCount.value - 1].id;

            return;
        }

        const index = rows.findIndex((row) => row.id === selectedId.value);
        selectedId.value = rows[(index + 1) % rows.length].id;
    }, 1650);
});

onBeforeUnmount(() => {
    if (timer) {
        window.clearInterval(timer);
    }
});
</script>

<template>
    <div
        class="preview-shell"
        :class="{ 'is-tilting': isTilting }"
        :style="shellStyle"
        aria-label="Animated Larasend dashboard preview"
        @pointermove="handleShellPointerMove"
        @pointerleave="resetShellTilt"
    >
        <div class="preview-glass"></div>

        <header class="preview-topbar">
            <div class="preview-brand">
                <span><AppLogoIcon class="preview-brand-icon" /></span>
                <b>larasend</b>
                <code>prod · us-east-1</code>
            </div>
            <div class="preview-crumb">
                <span>Project</span><i>/</i><b>harborlight</b><i>/</i
                ><strong>Activity</strong>
            </div>
            <button
                class="preview-search"
                type="button"
                :class="{ 'is-searching': isSearching }"
                @click="isSearching = !isSearching"
            >
                <Search />
                <span>{{
                    isSearching
                        ? 'Filtering: receipt, opened, clicked...'
                        : 'Search messages, recipients, message IDs...'
                }}</span>
                <kbd>⌘K</kbd>
            </button>
            <button class="icon-button" type="button" @click="replay">
                <RefreshCw />
            </button>
            <div class="range-toggle">
                <button
                    v-for="item in ['1h', '24h', '7d', '14d', '30d']"
                    :key="item"
                    type="button"
                    :class="{ active: range === item }"
                    @click="range = item"
                >
                    {{ item }}
                </button>
            </div>
            <button class="send-button" type="button"><Send /> Send</button>
            <div class="avatar">GH</div>
        </header>

        <div class="preview-body">
            <aside class="preview-sidebar">
                <div class="nav-group">
                    <span>Mail</span>
                    <button
                        v-for="item in navItems"
                        :key="item.label"
                        type="button"
                        :class="{ active: item.label === 'Activity' }"
                    >
                        <component :is="item.icon" />
                        <b>{{ item.label }}</b>
                        <em>{{ item.count }}</em>
                    </button>
                </div>
                <div class="nav-group config">
                    <span>Configuration</span>
                    <button
                        v-for="item in configItems"
                        :key="item.label"
                        type="button"
                    >
                        <component :is="item.icon" />
                        <b>{{ item.label }}</b>
                    </button>
                </div>
                <div class="quota-card">
                    <span>Stored sends</span>
                    <strong>209,340</strong>
                    <div><i></i></div>
                    <small>Last 30 days · SES quota sync optional</small>
                </div>
            </aside>

            <main class="preview-main">
                <section class="preview-titlebar">
                    <h3>Activity</h3>
                    <span><i></i> live</span>
                    <button type="button">Export</button>
                </section>

                <section class="preview-metrics">
                    <button
                        v-for="(metric, index) in metrics"
                        :key="metric.label"
                        type="button"
                        :style="{ '--metric-delay': `${index * 90}ms` }"
                    >
                        <span>{{ metric.label }}</span>
                        <strong>{{ metric.value }}</strong>
                        <em :class="metric.tone"
                            >{{ metric.delta }} vs prior</em
                        >
                        <svg viewBox="0 0 90 34" preserveAspectRatio="none">
                            <path
                                d="M0 23 L10 18 L20 21 L30 12 L40 16 L50 8 L60 11 L70 5 L80 13 L90 7"
                            />
                            <path
                                class="fill"
                                d="M0 23 L10 18 L20 21 L30 12 L40 16 L50 8 L60 11 L70 5 L80 13 L90 7 L90 34 L0 34 Z"
                            />
                        </svg>
                    </button>
                </section>

                <section class="preview-workspace">
                    <div class="preview-table">
                        <div class="filter-row">
                            <button type="button" class="active">
                                All <span>{{ visibleRows.length }}</span>
                            </button>
                            <button type="button">
                                Delivered <span>{{ deliveredCount }}</span>
                            </button>
                            <button type="button">Opened <span>3</span></button>
                            <button type="button">
                                Clicked <span>2</span>
                            </button>
                            <button type="button" class="filter">
                                <SlidersHorizontal /> Clear
                            </button>
                        </div>

                        <div class="table-head">
                            <span></span>
                            <span>Recipient · Subject</span>
                            <span>Template</span>
                            <span>Engagement</span>
                            <span>Status</span>
                            <span>Time</span>
                        </div>

                        <div class="table-scroll">
                            <div class="group-label">
                                Last hour · {{ visibleRows.length }}
                            </div>
                            <TransitionGroup name="row-pop">
                                <button
                                    v-for="row in visibleRows"
                                    :key="row.id"
                                    type="button"
                                    class="email-row"
                                    :class="{
                                        selected:
                                            isInspectorOpen &&
                                            selectedRow.id === row.id,
                                    }"
                                    @click="selectRow(row)"
                                >
                                    <span
                                        class="status-dot"
                                        :class="statusClass(row.status)"
                                    ></span>
                                    <span class="recipient-cell">
                                        <b>{{ row.recipient }}</b>
                                        <i>›</i>
                                        <strong>{{ row.subject }}</strong>
                                        <small
                                            >{{ row.email }} ·
                                            {{ row.id }}</small
                                        >
                                    </span>
                                    <code>{{ row.template }}</code>
                                    <span class="engagement"
                                        >◎ {{ row.opens }} ↗
                                        {{ row.clicks }}</span
                                    >
                                    <span
                                        class="status-pill"
                                        :class="statusClass(row.status)"
                                        ><i></i>{{ row.status }}</span
                                    >
                                    <time>{{ row.time }}</time>
                                </button>
                            </TransitionGroup>

                            <div class="group-label">
                                Earlier today · {{ earlierRows.length }}
                            </div>
                            <button
                                v-for="row in earlierRows"
                                :key="row.id"
                                type="button"
                                class="email-row"
                                :class="{
                                    selected:
                                        isInspectorOpen &&
                                        selectedRow.id === row.id,
                                }"
                                @click="selectRow(row)"
                            >
                                <span
                                    class="status-dot"
                                    :class="statusClass(row.status)"
                                ></span>
                                <span class="recipient-cell">
                                    <b>{{ row.recipient }}</b>
                                    <i>›</i>
                                    <strong>{{ row.subject }}</strong>
                                    <small
                                        >{{ row.email }} · {{ row.id }}</small
                                    >
                                </span>
                                <code>{{ row.template }}</code>
                                <span class="engagement"
                                    >◎ {{ row.opens }} ↗ {{ row.clicks }}</span
                                >
                                <span
                                    class="status-pill"
                                    :class="statusClass(row.status)"
                                    ><i></i>{{ row.status }}</span
                                >
                                <time>{{ row.time }}</time>
                            </button>
                        </div>
                    </div>

                    <Transition name="inspector">
                        <aside v-if="isInspectorOpen" class="preview-inspector">
                            <div class="inspector-actions">
                                <span
                                    class="status-pill"
                                    :class="statusClass(selectedRow.status)"
                                    ><i></i>{{ selectedRow.status }}</span
                                >
                                <code>{{ selectedRow.id }}</code>
                                <button type="button"><Copy /></button>
                                <button type="button">Retry</button>
                                <button type="button" @click="closeInspector">
                                    <X />
                                </button>
                            </div>
                            <h4>{{ selectedRow.subject }}</h4>
                            <dl>
                                <dt>From</dt>
                                <dd>
                                    Larasend Receipts
                                    &lt;receipts@larasend.app&gt;
                                </dd>
                                <dt>To</dt>
                                <dd>
                                    {{ selectedRow.recipient }} &lt;{{
                                        selectedRow.email
                                    }}&gt;
                                </dd>
                                <dt>Sent</dt>
                                <dd>{{ selectedRow.time }} ago</dd>
                                <dt>Template</dt>
                                <dd>{{ selectedRow.template }}</dd>
                            </dl>

                            <div class="tabs">
                                <button
                                    v-for="tab in [
                                        'timeline',
                                        'preview',
                                        'headers',
                                        'metrics',
                                    ]"
                                    :key="tab"
                                    type="button"
                                    :class="{ active: activeTab === tab }"
                                    @click="
                                        activeTab = tab as
                                            | 'timeline'
                                            | 'preview'
                                            | 'headers'
                                            | 'metrics'
                                    "
                                >
                                    {{ tab }}
                                </button>
                            </div>

                            <div class="tab-panel">
                                <div
                                    v-if="activeTab === 'timeline'"
                                    class="timeline"
                                >
                                    <div
                                        v-for="(event, index) in [
                                            'send',
                                            'delivery',
                                            'open',
                                            'click',
                                        ]"
                                        :key="event"
                                        :style="{
                                            '--timeline-delay': `${index * 120}ms`,
                                        }"
                                    >
                                        <span></span>
                                        <b>{{ event }}</b>
                                        <small>{{
                                            event === 'send'
                                                ? 'SES accepted message'
                                                : event === 'delivery'
                                                  ? 'Recipient server returned 250 OK'
                                                  : event === 'open'
                                                    ? 'Opened in Apple Mail'
                                                    : 'Receipt link clicked'
                                        }}</small>
                                        <em>{{ index + 1 }}m</em>
                                    </div>
                                </div>
                                <div
                                    v-else-if="activeTab === 'preview'"
                                    class="mail-preview"
                                >
                                    <span>Northwind</span>
                                    <h5>Thanks for your order, Maya.</h5>
                                    <p>
                                        Order #INV-4821 · delivered May 12, 2026
                                    </p>
                                    <div>
                                        <span>Saltspring chef's knife</span>
                                        <b>$184.00</b>
                                        <span>Honing rod, 10&quot;</span>
                                        <b>$42.00</b>
                                        <span>Total charged</span>
                                        <b>$290.00</b>
                                    </div>
                                </div>
                                <pre v-else-if="activeTab === 'headers'">
Message-ID: {{ selectedRow.id }}
X-SES-Message-ID: 010f018f...
List-Unsubscribe-Post: List-Unsubscribe=One-Click
X-Larasend-Project: harborlight</pre
                                >
                                <div v-else class="mini-metrics">
                                    <div>
                                        <span>Opens</span
                                        ><strong>{{
                                            selectedRow.opens
                                        }}</strong>
                                    </div>
                                    <div>
                                        <span>Clicks</span
                                        ><strong>{{
                                            selectedRow.clicks
                                        }}</strong>
                                    </div>
                                </div>
                            </div>
                        </aside>
                    </Transition>
                </section>
            </main>
        </div>
    </div>
</template>

<style scoped>
.preview-shell {
    position: relative;
    overflow: hidden;
    min-height: 760px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    background: #08090b;
    box-shadow:
        0 50px 160px rgba(0, 0, 0, 0.66),
        0 0 80px rgba(84, 224, 192, 0.08),
        inset 0 0 0 1px rgba(255, 255, 255, 0.025);
    color: #e9eaec;
    font-family: 'Geist', ui-sans-serif, system-ui, sans-serif;
    text-align: left;
    transform: rotateX(var(--tilt-x, 2deg)) rotateY(var(--tilt-y, -1.2deg));
    transform-origin: center top;
    transition:
        transform 0.45s cubic-bezier(0.16, 1, 0.3, 1),
        box-shadow 0.3s ease;
}

.preview-shell.is-tilting {
    transition:
        transform 0.12s ease-out,
        box-shadow 0.3s ease;
}

.preview-shell:hover {
    box-shadow:
        0 58px 180px rgba(0, 0, 0, 0.72),
        0 0 110px rgba(84, 224, 192, 0.11),
        inset 0 0 0 1px rgba(255, 255, 255, 0.035);
}

.preview-glass {
    position: absolute;
    inset: 0;
    z-index: 5;
    background: linear-gradient(
        108deg,
        transparent 0%,
        transparent 38%,
        rgba(255, 255, 255, 0.11) 48%,
        transparent 58%
    );
    opacity: 0.32;
    transform: translateX(-120%);
    animation: preview-sweep 7s ease-in-out infinite;
    pointer-events: none;
}

.preview-topbar {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: 224px auto minmax(260px, 1fr) 32px auto auto 28px;
    gap: 10px;
    align-items: center;
    height: 54px;
    border-bottom: 1px solid #1d2125;
    background: rgba(11, 12, 13, 0.92);
    padding: 0 12px;
}

.preview-brand,
.preview-crumb,
.preview-search,
.range-toggle,
.send-button,
.avatar,
.icon-button {
    animation: preview-rise 0.65s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.preview-brand {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}

.preview-brand span {
    display: grid;
    width: 24px;
    height: 24px;
    place-items: center;
    border-radius: 7px;
    background: #54e0c0;
    color: #062019;
}

.preview-brand-icon {
    width: 18px;
    height: 18px;
}

.preview-brand b {
    font-size: 15px;
}

.preview-brand code {
    margin-left: 6px;
    padding: 5px 8px;
    border: 1px solid #1d2125;
    border-radius: 6px;
    color: #8c939b;
    font:
        500 11px 'Geist Mono',
        ui-monospace,
        monospace;
}

.preview-crumb {
    display: flex;
    gap: 8px;
    color: #8c939b;
    font-size: 12px;
}

.preview-crumb strong {
    color: #e9eaec;
}

.preview-search {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    height: 34px;
    border: 1px solid #1d2125;
    border-radius: 9px;
    background: #111315;
    color: #767d86;
    padding: 0 10px;
    text-align: left;
    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease,
        color 0.2s ease;
}

.preview-search.is-searching {
    border-color: rgba(84, 224, 192, 0.36);
    box-shadow: 0 0 40px rgba(84, 224, 192, 0.12);
    color: #cdd1d6;
}

.preview-search svg,
.icon-button svg,
.send-button svg,
.nav-group svg,
.filter svg,
.inspector-actions svg {
    width: 14px;
    height: 14px;
}

.preview-search span {
    min-width: 0;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.preview-search kbd {
    border: 1px solid #262b30;
    border-radius: 5px;
    padding: 2px 5px;
    font:
        500 10px 'Geist Mono',
        ui-monospace,
        monospace;
}

.icon-button,
.send-button,
.range-toggle button,
.filter-row button,
.inspector-actions button {
    border: 1px solid #1d2125;
    border-radius: 8px;
    background: #111315;
    color: #9aa0a6;
    transition:
        transform 0.15s ease,
        background 0.15s ease,
        color 0.15s ease,
        border-color 0.15s ease;
}

.icon-button:hover,
.send-button:hover,
.range-toggle button:hover,
.filter-row button:hover,
.inspector-actions button:hover {
    border-color: #2a3037;
    background: #171b1f;
    color: #f4f6f8;
    transform: translateY(-1px);
}

.icon-button {
    display: grid;
    width: 32px;
    height: 32px;
    place-items: center;
}

.range-toggle {
    display: flex;
    padding: 3px;
    border: 1px solid #1d2125;
    border-radius: 9px;
}

.range-toggle button {
    height: 25px;
    border: 0;
    padding: 0 8px;
    background: transparent;
    font-size: 11px;
}

.range-toggle button.active {
    background: #1a1e22;
    color: #fff;
}

.send-button {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    height: 34px;
    background: #54e0c0;
    color: #062019;
    padding: 0 13px;
    font-weight: 700;
}

.avatar {
    display: grid;
    width: 28px;
    height: 28px;
    place-items: center;
    border-radius: 999px;
    background: linear-gradient(135deg, #b08af3, #54e0c0);
    color: #06140f;
    font:
        700 11px 'Geist Mono',
        ui-monospace,
        monospace;
}

.preview-body {
    display: grid;
    grid-template-columns: 224px minmax(0, 1fr);
    min-height: 706px;
}

.preview-sidebar {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 18px;
    border-right: 1px solid #1d2125;
    background: rgba(8, 9, 11, 0.7);
    padding: 18px 10px;
}

.nav-group {
    display: grid;
    gap: 4px;
}

.nav-group > span {
    padding: 0 8px 6px;
    color: #69717a;
    font:
        700 10px 'Geist Mono',
        ui-monospace,
        monospace;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.nav-group button {
    display: grid;
    grid-template-columns: 18px 1fr auto;
    gap: 8px;
    align-items: center;
    height: 30px;
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: #9aa0a6;
    padding: 0 8px;
    text-align: left;
}

.nav-group button.active,
.nav-group button:hover {
    background: #1a1e22;
    color: #f4f6f8;
}

.nav-group b {
    font-size: 12px;
    font-weight: 500;
}

.nav-group em {
    color: #69717a;
    font:
        normal 500 11px 'Geist Mono',
        ui-monospace,
        monospace;
}

.config {
    border-top: 1px solid #16191c;
    padding-top: 12px;
}

.quota-card {
    margin-top: auto;
    border: 1px solid #1d2125;
    border-radius: 10px;
    background: #111315;
    padding: 12px;
}

.quota-card span,
.quota-card small {
    color: #69717a;
    font:
        600 10px 'Geist Mono',
        ui-monospace,
        monospace;
    text-transform: uppercase;
}

.quota-card strong {
    display: block;
    margin-top: 12px;
    font-size: 13px;
}

.quota-card div {
    margin: 12px 0;
    height: 4px;
    border-radius: 999px;
    background: #1a1e22;
}

.quota-card i {
    display: block;
    width: 42%;
    height: 100%;
    border-radius: inherit;
    background: #54e0c0;
    animation: quota-fill 2.6s ease-in-out infinite alternate;
}

.preview-main {
    display: flex;
    min-width: 0;
    flex-direction: column;
}

.preview-titlebar {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 54px;
    border-bottom: 1px solid #1d2125;
    padding: 0 18px;
}

.preview-titlebar h3 {
    margin: 0;
    font-size: 22px;
    letter-spacing: -0.03em;
}

.preview-titlebar span {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 1px solid #1d2125;
    border-radius: 999px;
    padding: 4px 9px;
    color: #9aa0a6;
    font:
        500 11px 'Geist Mono',
        ui-monospace,
        monospace;
}

.preview-titlebar i {
    width: 7px;
    height: 7px;
    border-radius: 999px;
    background: #5cd494;
    box-shadow: 0 0 0 5px rgba(92, 212, 148, 0.12);
}

.preview-titlebar button {
    margin-left: auto;
    border: 0;
    background: transparent;
    color: #9aa0a6;
    font-weight: 600;
}

.preview-metrics {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    border-bottom: 1px solid #1d2125;
}

.preview-metrics button {
    position: relative;
    min-height: 100px;
    border: 0;
    border-right: 1px solid #1d2125;
    background: transparent;
    color: #e9eaec;
    padding: 14px 100px 12px 18px;
    text-align: left;
    opacity: 0;
    animation: metric-enter 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    animation-delay: var(--metric-delay);
}

.preview-metrics button:hover {
    background: rgba(255, 255, 255, 0.025);
}

.preview-metrics span {
    color: #69717a;
    font:
        700 10px 'Geist Mono',
        ui-monospace,
        monospace;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.preview-metrics strong {
    display: block;
    margin-top: 9px;
    font-size: 23px;
}

.preview-metrics em {
    display: block;
    margin-top: 6px;
    color: #5cd494;
    font:
        normal 600 11px 'Geist Mono',
        ui-monospace,
        monospace;
}

.preview-metrics em.down {
    color: #f06f6f;
}

.preview-metrics svg {
    position: absolute;
    top: 18px;
    right: 14px;
    width: 74px;
    height: 36px;
}

.preview-metrics path {
    fill: none;
    stroke: #54e0c0;
    stroke-width: 3;
    stroke-linecap: round;
    stroke-dasharray: 160;
    stroke-dashoffset: 160;
    animation: spark-draw 2s ease forwards;
}

.preview-metrics .fill {
    fill: rgba(84, 224, 192, 0.1);
    stroke: none;
}

.preview-workspace {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 420px;
    min-height: 498px;
}

.preview-table {
    min-width: 0;
    border-right: 1px solid #1d2125;
}

.filter-row {
    display: flex;
    gap: 8px;
    min-height: 50px;
    align-items: center;
    border-bottom: 1px solid #1d2125;
    padding: 0 14px;
}

.filter-row button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 28px;
    padding: 0 11px;
    font-size: 12px;
}

.filter-row .active {
    background: #1a1e22;
    color: #f4f6f8;
}

.filter-row .filter {
    margin-left: auto;
}

.table-head,
.email-row {
    display: grid;
    grid-template-columns: 20px minmax(220px, 1fr) 114px 92px 112px 56px;
    gap: 12px;
    align-items: center;
}

.table-head {
    height: 32px;
    border-bottom: 1px solid #1d2125;
    padding: 0 14px;
    color: #69717a;
    font:
        700 10px 'Geist Mono',
        ui-monospace,
        monospace;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.table-scroll {
    max-height: 416px;
    overflow: hidden;
}

.group-label {
    padding: 13px 14px 7px;
    color: #69717a;
    font:
        700 10px 'Geist Mono',
        ui-monospace,
        monospace;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.email-row {
    position: relative;
    width: 100%;
    height: 50px;
    border: 0;
    border-bottom: 1px solid #16191c;
    background: transparent;
    color: #cdd1d6;
    padding: 0 14px;
    text-align: left;
}

.email-row:hover,
.email-row.selected {
    background: #14181c;
}

.email-row.selected::before {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: 2px;
    background: #54e0c0;
    content: '';
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
}

.recipient-cell {
    min-width: 0;
    line-height: 1.2;
}

.recipient-cell b,
.recipient-cell strong,
.recipient-cell small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.recipient-cell b {
    display: inline-block;
    max-width: 42%;
    color: #f4f6f8;
    vertical-align: bottom;
}

.recipient-cell i {
    margin: 0 7px;
    color: #4a4f55;
    font-style: normal;
}

.recipient-cell strong {
    display: inline-block;
    max-width: 44%;
    color: #9aa0a6;
    font-weight: 500;
    vertical-align: bottom;
}

.recipient-cell small {
    display: block;
    margin-top: 3px;
    color: #69717a;
    font:
        500 10.5px 'Geist Mono',
        ui-monospace,
        monospace;
}

.email-row code,
.engagement,
.email-row time {
    overflow: hidden;
    color: #8c939b;
    font:
        500 11px 'Geist Mono',
        ui-monospace,
        monospace;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.email-row time {
    text-align: right;
}

.status-pill {
    display: inline-flex;
    width: fit-content;
    align-items: center;
    gap: 6px;
    border-radius: 5px;
    padding: 3px 7px;
    font:
        600 10.5px 'Geist Mono',
        ui-monospace,
        monospace;
}

.status-pill i {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: currentColor;
}

.is-sent {
    background: rgba(156, 163, 175, 0.12);
    color: #9ca3af;
}

.is-delivered {
    background: rgba(92, 212, 148, 0.12);
    color: #5cd494;
}

.is-opened {
    background: rgba(127, 180, 255, 0.14);
    color: #7fb4ff;
}

.is-clicked {
    background: rgba(84, 224, 192, 0.14);
    color: #54e0c0;
}

.is-bounced {
    background: rgba(240, 111, 111, 0.14);
    color: #f06f6f;
}

.status-dot.is-sent,
.status-dot.is-delivered,
.status-dot.is-opened,
.status-dot.is-clicked,
.status-dot.is-bounced {
    background: currentColor;
}

.preview-inspector {
    min-width: 0;
    background:
        radial-gradient(
            circle at 20% 0,
            rgba(84, 224, 192, 0.08),
            transparent 36%
        ),
        #0b0c0d;
}

.inspector-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 1px solid #1d2125;
    padding: 13px;
}

.inspector-actions code {
    min-width: 0;
    flex: 1;
    overflow: hidden;
    color: #8c939b;
    font:
        500 11px 'Geist Mono',
        ui-monospace,
        monospace;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.inspector-actions button {
    display: inline-flex;
    height: 28px;
    align-items: center;
    justify-content: center;
    padding: 0 8px;
    font-size: 12px;
    font-weight: 600;
}

.preview-inspector h4 {
    margin: 14px 16px 0;
    font-size: 17px;
    letter-spacing: -0.02em;
}

.preview-inspector dl {
    display: grid;
    grid-template-columns: 60px minmax(0, 1fr);
    gap: 9px 12px;
    margin: 15px 16px;
    font-size: 12px;
}

.preview-inspector dt {
    color: #69717a;
    font:
        700 10px 'Geist Mono',
        ui-monospace,
        monospace;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.preview-inspector dd {
    min-width: 0;
    margin: 0;
    overflow: hidden;
    color: #cdd1d6;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.tabs {
    display: flex;
    gap: 18px;
    border-top: 1px solid #1d2125;
    border-bottom: 1px solid #1d2125;
    padding: 0 16px;
}

.tabs button {
    border: 0;
    border-bottom: 2px solid transparent;
    background: transparent;
    color: #8c939b;
    padding: 12px 0 10px;
    font-size: 12px;
    font-weight: 700;
    text-transform: capitalize;
}

.tabs button.active {
    border-color: #54e0c0;
    color: #f4f6f8;
}

.tab-panel {
    padding: 14px;
}

.timeline {
    display: grid;
    gap: 8px;
}

.timeline div {
    display: grid;
    grid-template-columns: 12px 76px 1fr auto;
    gap: 9px;
    align-items: center;
    border: 1px solid #1d2125;
    border-radius: 8px;
    background: #111315;
    padding: 10px;
    opacity: 0;
    transform: translateY(8px);
    animation: timeline-enter 0.55s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    animation-delay: var(--timeline-delay);
}

.timeline div > span {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: #54e0c0;
    box-shadow: 0 0 0 5px rgba(84, 224, 192, 0.1);
}

.timeline b,
.timeline small,
.timeline em {
    overflow: hidden;
    color: #9aa0a6;
    font:
        500 10.5px 'Geist Mono',
        ui-monospace,
        monospace;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.timeline b {
    color: #f4f6f8;
    text-transform: uppercase;
}

.timeline em {
    color: #69717a;
    font-style: normal;
}

.mail-preview {
    border-radius: 10px;
    background: #e6e5e1;
    color: #25272b;
    padding: 28px;
}

.mail-preview > span {
    color: #7a7f87;
    font:
        700 11px 'Geist Mono',
        ui-monospace,
        monospace;
    letter-spacing: 0.22em;
    text-transform: uppercase;
}

.mail-preview h5 {
    margin: 28px 0 10px;
    font-size: 24px;
    letter-spacing: -0.04em;
}

.mail-preview p {
    margin: 0 0 22px;
    color: #6a7079;
}

.mail-preview div {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 12px;
    border: 1px solid #cfd2d5;
    border-radius: 9px;
    padding: 16px;
}

.tab-panel pre {
    min-height: 220px;
    overflow: auto;
    border: 1px solid #1d2125;
    border-radius: 9px;
    background: #08090b;
    color: #9aa0a6;
    padding: 14px;
    font:
        500 11px/1.7 'Geist Mono',
        ui-monospace,
        monospace;
}

.mini-metrics {
    display: grid;
    grid-template-columns: 1fr 1fr;
    overflow: hidden;
    border: 1px solid #1d2125;
    border-radius: 9px;
}

.mini-metrics div {
    padding: 18px;
}

.mini-metrics div:first-child {
    border-right: 1px solid #1d2125;
}

.mini-metrics span {
    color: #69717a;
    font:
        700 10px 'Geist Mono',
        ui-monospace,
        monospace;
    text-transform: uppercase;
}

.mini-metrics strong {
    display: block;
    margin-top: 8px;
    font-size: 28px;
}

.row-pop-enter-active,
.row-pop-leave-active,
.inspector-enter-active,
.inspector-leave-active {
    transition:
        opacity 0.45s cubic-bezier(0.16, 1, 0.3, 1),
        transform 0.45s cubic-bezier(0.16, 1, 0.3, 1);
}

.row-pop-enter-from {
    opacity: 0;
    transform: translateY(-12px) scale(0.99);
}

.inspector-enter-from,
.inspector-leave-to {
    opacity: 0;
    transform: translateX(24px);
}

@keyframes preview-sweep {
    0%,
    42% {
        transform: translateX(-120%);
    }

    58%,
    100% {
        transform: translateX(120%);
    }
}

@keyframes preview-rise {
    from {
        opacity: 0;
        transform: translateY(10px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes metric-enter {
    from {
        opacity: 0;
        transform: translateY(12px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes spark-draw {
    to {
        stroke-dashoffset: 0;
    }
}

@keyframes timeline-enter {
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes quota-fill {
    from {
        width: 36%;
    }

    to {
        width: 46%;
    }
}

@media (max-width: 1100px) {
    .preview-topbar {
        grid-template-columns: 180px minmax(0, 1fr) 32px auto auto 28px;
    }

    .preview-crumb {
        display: none;
    }

    .preview-body {
        grid-template-columns: 190px minmax(0, 1fr);
    }

    .preview-workspace {
        grid-template-columns: minmax(0, 1fr);
    }

    .preview-inspector {
        display: none;
    }
}

@media (max-width: 820px) {
    .preview-shell {
        min-height: 640px;
        transform: none;
    }

    .preview-sidebar,
    .range-toggle,
    .preview-search kbd {
        display: none;
    }

    .preview-topbar {
        grid-template-columns: 1fr 32px auto 28px;
    }

    .preview-body {
        grid-template-columns: minmax(0, 1fr);
        min-height: 586px;
    }

    .preview-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .preview-metrics button {
        min-height: 88px;
    }

    .preview-metrics button:nth-child(n + 3) {
        display: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .preview-shell,
    .preview-shell *,
    .preview-shell *::before,
    .preview-shell *::after {
        animation-duration: 0.001ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.001ms !important;
    }
}
</style>
