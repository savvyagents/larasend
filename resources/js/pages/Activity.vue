<script setup lang="ts">
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Archive,
    ArrowUpRight,
    Check,
    Cloud,
    Copy,
    Inbox,
    KeyRound,
    Pencil,
    RefreshCw,
    Search,
    Send,
    SlidersHorizontal,
    Trash2,
    X,
} from 'lucide-vue-next';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import GlobalRail from '@/components/GlobalRail.vue';
import { Toaster } from '@/components/ui/sonner';

type Metric = {
    label: string;
    value: string;
    delta: string | null;
    trend: 'up' | 'down' | 'neutral';
    tone: 'good' | 'bad' | 'neutral';
    spark: number[];
};

type EmailRow = {
    id: string;
    recipient: string;
    recipientEmail: string;
    recipientEmails: string;
    recipientCount: number;
    subject: string;
    template: string | null;
    status: string;
    opens: number;
    clicks: number;
    time: string;
    createdAt: string;
};

type EmailDetail = EmailRow & {
    from: string;
    to: string;
    cc: string;
    bcc: string;
    html: string | null;
    text: string | null;
    headers: Record<string, string> | null;
    sesMessageId: string | null;
    mimeSize: number | null;
    mimeUrl: string;
    previewUrl: string;
    events: { type: string; recipient: string | null; occurredAt: string }[];
};

type BounceMetric = {
    label: string;
    value: string;
    meta: string;
    tone: 'neutral' | 'success' | 'danger';
};

type BounceQueueRow = {
    id: string;
    type: 'Hard' | 'Soft';
    recipient: string | null;
    reason: string;
    smtp: string;
    mx: string;
    template: string | null;
    attempts: number;
    when: string;
};

type WebhookEndpointRow = {
    id: string;
    url: string;
    events: string[];
    status: string;
    configured_status: string;
    secret_prefix: string;
    success_rate: string;
    last_delivered_at: string;
    created_at: string;
};

type WebhookDeliveryRow = {
    id: string;
    status: string;
    event: string;
    endpoint: string | null;
    http: number | null;
    latency: number | null;
    when: string;
};

type SuppressionRow = {
    id: number;
    email: string;
    reason: string;
    source: string;
    added: string;
    expires: string;
};

type ProjectOption = {
    name: string;
    slug: string;
    environment: string;
    provider_label: string;
    region: string | null;
    emails_count: number;
    domains_count: number;
    is_current: boolean;
    href: string;
    can_delete: boolean;
    delete_block_reason: string | null;
};

type SourceProvider = 'ses' | 'cloudflare';

type ArchivedProjectOption = Omit<
    ProjectOption,
    | 'is_current'
    | 'href'
    | 'can_delete'
    | 'delete_block_reason'
    | 'provider_label'
> & {
    archived_at: string | null;
};

type HealthTone = 'success' | 'warning' | 'neutral';

type NewWebhookEndpoint = {
    id: string;
    url: string;
    secret: string;
    events: string[];
};

type WorkspaceMemberRole =
    | 'owner'
    | 'member'
    | 'sender'
    | 'api_keys'
    | 'domains'
    | 'read_only';

type ApiKeyScope = 'send' | 'read:activity';

type ConfirmationState = {
    title: string;
    body: string;
    actionLabel: string;
    tone: 'danger' | 'warning';
    onConfirm: () => void;
} | null;

const props = defineProps<{
    project: {
        name: string;
        slug: string;
        environment: string;
        provider: SourceProvider;
        provider_label: string;
        region: string | null;
        path: string;
        exportPath: string;
    };
    workspace: {
        name: string;
        slug: string;
        can_manage_members: boolean;
        can_manage_api_keys: boolean;
        can_manage_domains: boolean;
        can_send: boolean;
    };
    workspaceMembers: {
        id: number;
        name: string;
        email: string;
        role: WorkspaceMemberRole;
        is_owner: boolean;
    }[];
    projects: ProjectOption[];
    archivedProjects: ArchivedProjectOption[];
    section: string;
    filters: { q: string; range: string };
    metrics: Metric[];
    bounceMetrics: BounceMetric[];
    bounceQueue: BounceQueueRow[];
    emails: EmailRow[];
    selectedEmail: EmailDetail | null;
    sidebarCounts: Record<string, number>;
    inboxUnread?: number;
    quota: {
        sent: number;
        limit: number | null;
        rate: number | null;
        sentLast24Hours: number | null;
        checkedAt: string | null;
    };
    system: {
        worker_alive: boolean;
        worker_last_seen: string | null;
        scheduler_alive: boolean;
        scheduler_last_seen: string | null;
        stuck_queued: number;
    };
    source: {
        name: string;
        environment: string;
        provider: SourceProvider;
        provider_label: string;
        ses_region: string | null;
        ses_configuration_set: string | null;
        cloudflare_account_id: string | null;
        default_from_name: string | null;
        default_from_email: string | null;
        retention_days: number;
        has_aws_credentials: boolean;
        has_aws_session_token: boolean;
        has_cloudflare_credentials: boolean;
        uses_instance_role: boolean;
        can_send: boolean;
        capabilities: {
            identity_creation: boolean;
            inbound_event_webhooks: boolean;
            open_click_tracking: boolean;
            suppression_sync: boolean;
        };
    } | null;
    domains: {
        id: number;
        domain: string;
        status: string;
        dns_records:
            | { type: string; name: string; value: string; status?: string }[]
            | null;
        verified_at: string | null;
        inbound_enabled_at: string | null;
    }[];
    inboundEmails: {
        public_id: string;
        from_email: string;
        from_name: string | null;
        to_email: string;
        subject: string | null;
        text: string | null;
        html: string | null;
        attachments:
            | {
                  filename: string | null;
                  content_type: string | null;
                  size: number;
              }[]
            | null;
        received_at: string;
    }[];
    templates: {
        slug: string;
        name: string;
        subject: string;
        html: string | null;
        text: string | null;
        variables: string[] | null;
        updated_at: string;
    }[];
    webhooks: WebhookEndpointRow[];
    webhookStats: BounceMetric[];
    webhookDeliveries: WebhookDeliveryRow[];
    suppressions: SuppressionRow[];
    newWebhookEndpoint: NewWebhookEndpoint | null;
    sesWebhookUrl: string | null;
    apiKeys: {
        id: number;
        name: string;
        prefix: string;
        scopes: ApiKeyScope[] | null;
        last_used_at: string | null;
        last_used_ip: string | null;
        last_used_user_agent: string | null;
        expires_at: string | null;
        created_at: string;
    }[];
    newApiKey: string | null;
    inboundError: string | null;
    setup: {
        webhook_url: string | null;
        next_step: {
            key: string;
            label: string;
            description: string;
            complete: boolean;
            href: string;
            status?: string;
        } | null;
        steps: {
            key: string;
            label: string;
            description: string;
            complete: boolean;
            href: string;
            status?: string;
        }[];
    };
}>();

const page = usePage();
const buildLabel = computed(() => {
    const build = page.props.build as
        | { version?: string | null; sha?: string | null }
        | undefined;
    const version = build?.version || 'dev';
    const sha = build?.sha ? build.sha.slice(0, 7) : null;

    return sha ? `v${version} · ${sha}` : `v${version}`;
});
const selected = ref<Partial<EmailDetail> | null>(props.selectedEmail);
const activeTab = ref<'timeline' | 'preview' | 'headers' | 'metrics'>(
    'preview',
);
const statusFilters = [
    'All',
    'Delivered',
    'Opened',
    'Clicked',
    'Queued',
    'Sending',
    'Bounced',
    'Complained',
    'Failed',
];
const selectedFilter = ref('All');
const searchQuery = ref(props.filters.q);
const searchInput = ref<HTMLInputElement | null>(null);
const selectedRange = ref(props.filters.range || '14d');
const showProjectForm = ref(false);
const selectedIdentityDomain = ref(props.domains[0]?.domain ?? '');
const showNewIdentity = ref(false);
const showSourceSettings = ref(false);
const revealedApiKey = ref(props.newApiKey);
const apiKeyCopied = ref(false);
const showWebhookForm = ref(false);
const showWebhookDeliveries = ref(true);
const editingWebhookId = ref<string | null>(null);
const revealedWebhookEndpoint = ref(props.newWebhookEndpoint);
const webhookSecretCopied = ref(false);
const checkingDomainId = ref<number | null>(null);
const deletingDomainId = ref<number | null>(null);
const copiedDnsKey = ref<string | null>(null);
const inspectorWidth = ref(600);
const isResizingInspector = ref(false);
const editingProjectSlug = ref<string | null>(null);
const archivingProjectSlug = ref<string | null>(null);
const deletingProjectSlug = ref<string | null>(null);
const syncingQuota = ref(false);
const attemptedAutoQuotaSync = ref(false);
const showingArchivedProjects = ref(false);
const restoringProjectSlug = ref<string | null>(null);
const confirmation = ref<ConfirmationState>(null);
let copiedDnsTimer: ReturnType<typeof window.setTimeout> | null = null;
let searchTimer: ReturnType<typeof window.setTimeout> | null = null;
let resizeStartX = 0;
let resizeStartWidth = 0;
const sourceForm = reactive({
    name: props.source?.name ?? 'Production',
    environment: props.source?.environment ?? props.project.environment,
    provider: (props.source?.provider ?? 'ses') as SourceProvider,
    ses_region: props.source?.ses_region ?? props.project.region ?? 'us-east-1',
    ses_configuration_set: props.source?.ses_configuration_set ?? '',
    cloudflare_account_id: props.source?.cloudflare_account_id ?? '',
    cloudflare_api_token: '',
    default_from_name: props.source?.default_from_name ?? 'Larasend',
    default_from_email: props.source?.default_from_email ?? '',
    aws_access_key_id: '',
    aws_secret_access_key: '',
    aws_session_token: '',
    retention_days: props.source?.retention_days ?? 90,
});
const domainForm = useForm({ domain: '' });
const templateForm = reactive({
    slug: '',
    name: '',
    subject: '',
    html: '<div><h1>Hello {{name}}</h1><p>Your email is ready.</p></div>',
    text: 'Hello {{name}}, your email is ready.',
    variables: 'name',
});
const apiKeyForm = reactive({
    name: 'Production key',
    scopes: ['send', 'read:activity'] as ApiKeyScope[],
    expires_at: '',
});
const webhookEventOptions = [
    'delivery',
    'bounce',
    'complaint',
    'open',
    'click',
    'suppress',
    'inbound.received',
];
const webhookForm = reactive({
    url: '',
    events: ['delivery', 'bounce', 'complaint', 'open', 'click'],
    status: 'active',
});
const sendForm = reactive({
    from: props.source?.default_from_email ?? '',
    to: '',
    cc: '',
    bcc: '',
    subject: '',
    html: '<div><h1>Hello</h1><p>This is a Larasend test email.</p></div>',
    text: 'This is a Larasend test email.',
    template_id: '',
});

watch(
    () => props.newApiKey,
    (newApiKey) => {
        if (newApiKey) {
            revealedApiKey.value = newApiKey;
            apiKeyCopied.value = false;
        }
    },
);
const projectForm = reactive({
    name: '',
    slug: '',
});
const projectEditForm = useForm({
    name: '',
    slug: '',
});
const workspaceMemberForm = useForm({
    email: '',
    role: 'member' as WorkspaceMemberRole,
});

const workspaceRoleOptions: {
    value: WorkspaceMemberRole;
    label: string;
    description: string;
}[] = [
    {
        value: 'member',
        label: 'Member',
        description: 'Send, manage API keys, and manage domains.',
    },
    {
        value: 'sender',
        label: 'Can send',
        description: 'Send and resend email only.',
    },
    {
        value: 'api_keys',
        label: 'API keys',
        description: 'Create, rotate, and delete API keys.',
    },
    {
        value: 'domains',
        label: 'Domains',
        description: 'Manage sending sources, domains, and quota sync.',
    },
    {
        value: 'read_only',
        label: 'Read only',
        description: 'View activity and configuration.',
    },
    {
        value: 'owner',
        label: 'Owner',
        description: 'Full workspace administration.',
    },
];

const apiKeyScopeOptions: { value: ApiKeyScope; label: string }[] = [
    { value: 'send', label: 'Send email' },
    { value: 'read:activity', label: 'Read activity' },
];

const filteredEmails = computed(() => {
    if (selectedFilter.value === 'All') {
        return props.emails;
    }

    return props.emails.filter(
        (email) => email.status === selectedFilter.value.toLowerCase(),
    );
});

const statusFilterCounts = computed(() => {
    const counts: Record<string, number> = { All: props.emails.length };

    for (const filter of statusFilters.slice(1)) {
        counts[filter] = props.emails.filter(
            (email) => email.status === filter.toLowerCase(),
        ).length;
    }

    return counts;
});

const groupedEmails = computed(() => {
    const groups: Record<string, EmailRow[]> = {};

    for (const email of filteredEmails.value) {
        const label = dateGroupLabel(new Date(email.createdAt));

        groups[label] = [...(groups[label] ?? []), email];
    }

    return Object.entries(groups).map(([label, rows]) => ({ label, rows }));
});

const complaintRows = computed(() =>
    props.emails.filter((email) => email.status === 'complained'),
);

const suppressionRows = computed(() => props.suppressions);
const bounceSuppressionCount = computed(
    () =>
        suppressionRows.value.filter((row) => row.reason === 'hard_bounce')
            .length,
);
const complaintSuppressionCount = computed(
    () =>
        suppressionRows.value.filter((row) => row.reason === 'complaint')
            .length,
);
const lastComplaintTime = computed(
    () => complaintRows.value[0]?.time ?? 'Never',
);

const canSendEmail = computed(
    () => Boolean(props.source?.can_send) && props.workspace.can_send,
);

const apiKeyStats = computed(() => {
    const total = props.apiKeys.length;
    const used = props.apiKeys.filter((key) => key.last_used_at).length;

    return [
        { label: 'Total keys', value: total.toLocaleString(), meta: 'issued' },
        {
            label: 'Used keys',
            value: used.toLocaleString(),
            meta: 'have activity',
        },
        {
            label: 'Project sends (30d)',
            value: props.quota.sent.toLocaleString(),
            meta: 'stored',
        },
    ];
});

const templateStats = computed(() => [
    {
        label: 'Templates',
        value: props.templates.length.toLocaleString(),
        meta: 'saved',
    },
    {
        label: 'Template sends',
        value: props.emails
            .filter((email) => email.template)
            .length.toLocaleString(),
        meta: '30d',
    },
    {
        label: 'Open coverage',
        value: `${Math.round((props.emails.filter((email) => email.opens > 0).length / Math.max(props.emails.length, 1)) * 100)}%`,
        meta: 'observed',
    },
    {
        label: 'Click coverage',
        value: `${Math.round((props.emails.filter((email) => email.clicks > 0).length / Math.max(props.emails.length, 1)) * 100)}%`,
        meta: 'observed',
    },
]);

const isCloudflare = computed(() => props.source?.provider === 'cloudflare');
const providerLabel = computed(
    () => props.source?.provider_label ?? 'Amazon SES',
);
const cloudflareTokenUrl = computed(() => {
    // Email Sending Edit to send, Zone Read + DNS Edit so Larasend can
    // onboard sending domains and publish their records automatically.
    const permissions = encodeURIComponent(
        JSON.stringify([
            { key: 'email_sending', type: 'edit' },
            { key: 'zone', type: 'read' },
            { key: 'dns', type: 'edit' },
        ]),
    );

    return `https://dash.cloudflare.com/?to=/:account/api-tokens&permissionGroupKeys=${permissions}&name=Larasend%20Email%20Sending`;
});
const quotaStatus = computed(() => {
    if (!props.source) {
        return 'source missing';
    }

    if (props.quota.checkedAt) {
        return `last synced ${relativeTime(props.quota.checkedAt)}`;
    }

    return 'sync required';
});
const quotaDetail = computed(() => {
    if (!props.quota.limit) {
        return 'Provider quota not synced yet.';
    }

    const used = props.quota.sentLast24Hours ?? 0;
    const rate = props.quota.rate ? `${props.quota.rate}/s` : 'rate unknown';

    return `${used.toLocaleString()} used in the last 24h · ${rate}`;
});
const quotaIsStale = computed(() => {
    if (!props.source) {
        return false;
    }

    if (!props.quota.checkedAt) {
        return true;
    }

    const checkedAt = new Date(props.quota.checkedAt).getTime();

    return Number.isNaN(checkedAt)
        ? true
        : Date.now() - checkedAt > 6 * 60 * 60 * 1000;
});
const verifiedDomain = computed(() =>
    props.domains.find((domain) =>
        ['verified', 'local'].includes(domain.status),
    ),
);
const activeWebhookCount = computed(
    () =>
        props.webhooks.filter(
            (webhook) => webhook.configured_status !== 'paused',
        ).length,
);
const credentialMode = computed(() => {
    if (!props.source) {
        return 'Not configured';
    }

    if (props.source.provider === 'cloudflare') {
        return props.source.has_cloudflare_credentials
            ? 'Cloudflare API token'
            : 'Missing API token';
    }

    if (props.source.uses_instance_role) {
        return 'EC2 instance role';
    }

    if (props.source.has_aws_credentials) {
        return props.source.has_aws_session_token
            ? 'STS credentials'
            : 'Stored IAM keys';
    }

    return 'Missing credentials';
});
const hasCredentials = computed(() =>
    isCloudflare.value
        ? Boolean(props.source?.has_cloudflare_credentials)
        : Boolean(
              props.source?.has_aws_credentials ||
              props.source?.uses_instance_role,
          ),
);
const setupWebhookStep = computed(() =>
    props.setup.steps.find((step) => step.key === 'webhook'),
);
const setupHealthCards = computed<
    { label: string; value: string; meta: string; tone: HealthTone }[]
>(() => [
    {
        label: 'Credentials',
        value: credentialMode.value,
        meta: props.source?.default_from_email
            ? props.source.default_from_email
            : 'Default sender missing',
        tone: hasCredentials.value ? 'success' : 'warning',
    },
    {
        label: 'Domain',
        value: verifiedDomain.value?.domain ?? 'Not verified',
        meta: verifiedDomain.value
            ? `${props.project.region ?? providerLabel.value} verified`
            : `${props.domains.length.toLocaleString()} configured`,
        tone: verifiedDomain.value ? 'success' : 'warning',
    },
    {
        label: 'Quota',
        value: props.quota.limit
            ? `${props.quota.limit.toLocaleString()} / ${isCloudflare.value ? 'day' : '24h'}`
            : syncingQuota.value
              ? 'Syncing'
              : 'Needs sync',
        meta: props.quota.rate
            ? `${props.quota.rate}/s send rate`
            : quotaStatus.value,
        tone: props.quota.limit ? 'success' : 'warning',
    },
    isCloudflare.value
        ? {
              label: 'Events',
              value: 'Suppression sync',
              meta: 'Cloudflare has no event webhooks; suppressions sync hourly',
              tone: 'neutral' as HealthTone,
          }
        : {
              label: 'Events',
              value: setupWebhookStep.value?.complete
                  ? 'Events received'
                  : props.sesWebhookUrl
                    ? 'Webhook URL ready'
                    : 'Source missing',
              meta: activeWebhookCount.value
                  ? `${activeWebhookCount.value.toLocaleString()} outbound active`
                  : (setupWebhookStep.value?.status ?? 'Waiting for SES event'),
              tone: setupWebhookStep.value?.complete
                  ? 'success'
                  : props.sesWebhookUrl
                    ? 'warning'
                    : 'neutral',
          },
    {
        label: 'API keys',
        value: props.apiKeys.length
            ? props.apiKeys.length.toLocaleString()
            : 'None',
        meta: props.apiKeys.some((key) => key.last_used_at)
            ? 'Has recent usage'
            : 'No usage yet',
        tone: props.apiKeys.length ? 'success' : 'neutral',
    },
    {
        label: 'Queue worker',
        value: props.system.worker_alive ? 'Running' : 'Not detected',
        meta: props.system.worker_alive
            ? `last seen ${props.system.worker_last_seen}`
            : 'Emails will not send. Run: php artisan queue:work',
        tone: props.system.worker_alive ? 'success' : 'warning',
    },
    {
        label: 'Scheduler',
        value: props.system.scheduler_alive ? 'Running' : 'Not detected',
        meta: props.system.scheduler_alive
            ? `last ran ${props.system.scheduler_last_seen}`
            : 'DNS checks, quota, and suppressions will not refresh',
        tone: props.system.scheduler_alive ? 'success' : 'warning',
    },
]);
const showWorkerBanner = computed(
    () =>
        !props.system.worker_alive &&
        props.system.stuck_queued > 0 &&
        (isMailSection.value || props.section === 'send'),
);
const complaintRate = computed(
    () =>
        `${((complaintRows.value.length / Math.max(props.emails.length, 1)) * 100).toFixed(3)}%`,
);
const projectBasePath = computed(() => `/projects/${props.project.slug}`);
const sectionPath = computed(() => sectionHref(props.section));
const exportHref = computed(() => {
    const params = new URLSearchParams({
        section: props.section,
        range: selectedRange.value,
    });

    if (searchQuery.value) {
        params.set('q', searchQuery.value);
    }

    return `${props.project.exportPath}?${params.toString()}`;
});

function sectionHref(section: string): string {
    return `${projectBasePath.value}/${section}`;
}

function projectAction(path: string): string {
    return `${projectBasePath.value}${path}`;
}

function relativeTime(value: string | null): string {
    if (!value) {
        return 'never';
    }

    const timestamp = new Date(value).getTime();

    if (Number.isNaN(timestamp)) {
        return value;
    }

    const seconds = Math.max(0, Math.round((Date.now() - timestamp) / 1000));

    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    const minutes = Math.round(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `${hours}h ago`;
    }

    return `${Math.round(hours / 24)}d ago`;
}

const isMailSection = computed(() =>
    ['activity', 'sent', 'bounces', 'complaints'].includes(props.section),
);

usePoll(
    5000,
    {
        only: [
            'emails',
            'selectedEmail',
            'metrics',
            'bounceMetrics',
            'bounceQueue',
            'sidebarCounts',
            'quota',
        ],
        showProgress: false,
    },
    { autoStart: isMailSection.value },
);

const hasPendingDomainCheck = computed(
    () =>
        props.section === 'identities' &&
        props.domains.some((domain) => domain.status === 'pending'),
);

// A background job re-checks pending domains every 10 minutes regardless of
// whether anyone has this page open; this just reflects that result without
// requiring a manual "Re-check DNS" click.
usePoll(
    10 * 60 * 1000,
    { only: ['domains', 'setup'], showProgress: false },
    { autoStart: hasPendingDomainCheck.value },
);

watch(
    () => props.emails,
    (emails) => {
        if (!selected.value?.id) {
            selected.value = props.selectedEmail;

            return;
        }

        const refreshed = emails.find(
            (email) => email.id === selected.value?.id,
        );

        if (refreshed) {
            selected.value = {
                ...selected.value,
                ...refreshed,
            };
        }
    },
);

watch(searchQuery, () => {
    if (!isMailSection.value) {
        return;
    }

    if (searchTimer) {
        window.clearTimeout(searchTimer);
    }

    searchTimer = window.setTimeout(() => applySearch(), 350);
});

onMounted(() => {
    const savedWidth = Number(
        window.localStorage.getItem('larasend:inspectorWidth'),
    );

    if (Number.isFinite(savedWidth)) {
        inspectorWidth.value = clampInspectorWidth(savedWidth);
    }

    window.addEventListener('keydown', handleGlobalShortcut);
    syncQuotaIfStale();
});

onBeforeUnmount(() => {
    stopInspectorResize();
    window.removeEventListener('keydown', handleGlobalShortcut);
});

watch(
    () => props.section,
    () => syncQuotaIfStale(),
);
const showMetrics = computed(() =>
    ['activity', 'sent'].includes(props.section),
);
const softBounceCount = computed(
    () => props.bounceQueue.filter((bounce) => bounce.type === 'Soft').length,
);
const hardBounceCount = computed(
    () => props.bounceQueue.filter((bounce) => bounce.type === 'Hard').length,
);
const selectedIdentity = computed(
    () =>
        props.domains.find(
            (domain) => domain.domain === selectedIdentityDomain.value,
        ) ??
        props.domains[0] ??
        null,
);
const selectedIdentityRecords = computed(
    () => selectedIdentity.value?.dns_records ?? [],
);
const identityStats = computed(() => {
    const sent = props.emails.length;
    const delivered = props.emails.filter((email) =>
        ['delivered', 'opened', 'clicked'].includes(email.status),
    ).length;
    const bounced = props.emails.filter(
        (email) => email.status === 'bounced',
    ).length;
    const complained = props.emails.filter(
        (email) => email.status === 'complained',
    ).length;
    const total = Math.max(sent, 1);

    return [
        { label: 'Sends (30d)', value: sent.toLocaleString() },
        {
            label: 'Delivery',
            value: `${((delivered / total) * 100).toFixed(2)}%`,
        },
        { label: 'Bounce', value: `${((bounced / total) * 100).toFixed(2)}%` },
        {
            label: 'Complaint',
            value: `${((complained / total) * 100).toFixed(2)}%`,
        },
    ];
});
const selectedInboundId = ref<string | null>(null);
const selectedInbound = computed(
    () =>
        props.inboundEmails.find(
            (email) => email.public_id === selectedInboundId.value,
        ) ??
        props.inboundEmails[0] ??
        null,
);
const enablingInboundDomainId = ref<number | null>(null);

function enableInbound(domainId: number): void {
    enablingInboundDomainId.value = domainId;
    router.post(
        projectAction(`/domains/${domainId}/inbound`),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                enablingInboundDomainId.value = null;
            },
        },
    );
}

const pageTitle = computed(() => {
    return (
        {
            activity: 'Overview',
            sent: 'Sent',
            inbound: 'Inbound',
            bounces: 'Bounces',
            complaints: 'Complaints',
            suppressions: 'Suppressions',
            setup: 'Sending source',
            identities: 'Domains',
            templates: 'Templates',
            webhooks: 'Webhooks',
            'api-keys': 'API keys',
            send: 'Send email',
            projects: 'Workspace',
        }[props.section] ?? props.section.replace('-', ' ')
    );
});
function saveSource(): void {
    router.put(projectAction('/source'), sourceForm, { preserveScroll: true });
}

function syncQuota(silent = false): void {
    syncingQuota.value = true;

    router.post(
        projectAction('/source/quota'),
        { silent },
        {
            preserveScroll: true,
            showProgress: !silent,
            onFinish: () => {
                syncingQuota.value = false;
            },
        },
    );
}

function syncQuotaIfStale(): void {
    if (
        props.section !== 'setup' ||
        attemptedAutoQuotaSync.value ||
        syncingQuota.value ||
        !quotaIsStale.value
    ) {
        return;
    }

    attemptedAutoQuotaSync.value = true;
    syncQuota(true);
}

function normalizeIdentityDomain(value: string): string {
    const identity = value.trim();
    const domain = identity.includes('@')
        ? identity.slice(identity.lastIndexOf('@') + 1)
        : identity;

    return domain.replace(/^[<\s]+|[>\s.,;]+$/g, '').toLowerCase();
}

function addDomain(): void {
    domainForm.domain = normalizeIdentityDomain(domainForm.domain);

    domainForm.post(projectAction('/domains'), {
        preserveScroll: true,
        onSuccess: () => {
            selectedIdentityDomain.value = domainForm.domain;
            domainForm.reset();
            showNewIdentity.value = false;
        },
    });
}

function checkDomain(): void {
    if (!selectedIdentity.value) {
        return;
    }

    checkingDomainId.value = selectedIdentity.value.id;
    router.post(
        projectAction(`/domains/${selectedIdentity.value.id}/check-dns`),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                checkingDomainId.value = null;
            },
        },
    );
}

function deleteDomain(): void {
    if (!selectedIdentity.value) {
        return;
    }

    const domain = selectedIdentity.value;

    openConfirmation({
        title: `Delete ${domain.domain}?`,
        body: `This removes the identity from Larasend only. ${providerLabel.value} identities and DNS records are not removed from the provider or your DNS host.`,
        actionLabel: 'Delete identity',
        tone: 'danger',
        onConfirm: () => {
            deletingDomainId.value = domain.id;
            router.delete(projectAction(`/domains/${domain.id}`), {
                preserveScroll: true,
                preserveState: false,
                onFinish: () => {
                    deletingDomainId.value = null;
                },
            });
        },
    });
}

async function copyText(value: string): Promise<boolean> {
    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(value);

            return true;
        } catch {
            // Fall back below for browsers that block clipboard writes.
        }
    }

    const textArea = document.createElement('textarea');
    textArea.value = value;
    textArea.setAttribute('readonly', '');
    textArea.style.position = 'fixed';
    textArea.style.left = '-9999px';
    textArea.style.top = '0';
    document.body.appendChild(textArea);
    textArea.select();
    textArea.setSelectionRange(0, textArea.value.length);

    const copied = document.execCommand('copy');
    document.body.removeChild(textArea);

    return copied;
}

async function copyRevealedApiKey(): Promise<void> {
    if (!revealedApiKey.value) {
        return;
    }

    apiKeyCopied.value = await copyText(revealedApiKey.value);
}

function closeApiKeyModal(): void {
    revealedApiKey.value = null;
    apiKeyCopied.value = false;
}

function selectTextArea(event: Event): void {
    if (event.target instanceof HTMLTextAreaElement) {
        event.target.select();
    }
}

async function copyWebhookSecret(): Promise<void> {
    if (!revealedWebhookEndpoint.value) {
        return;
    }

    webhookSecretCopied.value = await copyText(
        revealedWebhookEndpoint.value.secret,
    );
}

function closeWebhookSecretModal(): void {
    revealedWebhookEndpoint.value = null;
    webhookSecretCopied.value = false;
}

function copyAllDns(): void {
    const text = selectedIdentityRecords.value
        .map((record) => `${record.type}\t${record.name}\t${record.value}`)
        .join('\n');
    void copyText(text);
    markDnsCopied('all');
}

function copyDnsValue(key: string, value: string): void {
    void copyText(value);
    markDnsCopied(key);
}

function markDnsCopied(key: string): void {
    copiedDnsKey.value = key;

    if (copiedDnsTimer) {
        window.clearTimeout(copiedDnsTimer);
    }

    copiedDnsTimer = window.setTimeout(() => {
        copiedDnsKey.value = null;
        copiedDnsTimer = null;
    }, 1400);
}

function saveTemplate(): void {
    router.post(projectAction('/templates'), templateForm, {
        preserveScroll: true,
    });
}

function issueApiKey(): void {
    router.post(projectAction('/api-keys'), apiKeyForm, {
        preserveScroll: true,
    });
}

function toggleApiKeyScope(scope: ApiKeyScope): void {
    if (apiKeyForm.scopes.includes(scope)) {
        apiKeyForm.scopes = apiKeyForm.scopes.filter(
            (value) => value !== scope,
        );

        return;
    }

    apiKeyForm.scopes = [...apiKeyForm.scopes, scope];
}

function apiKeyScopes(apiKey: { scopes: ApiKeyScope[] | null }): ApiKeyScope[] {
    return apiKey.scopes?.length ? apiKey.scopes : ['send', 'read:activity'];
}

function rotateApiKey(apiKey: { id: number; name: string }): void {
    openConfirmation({
        title: `Rotate ${apiKey.name}?`,
        body: 'The current key will stop working immediately. Larasend will reveal the replacement key once after rotation.',
        actionLabel: 'Rotate key',
        tone: 'warning',
        onConfirm: () => {
            router.post(
                projectAction(`/api-keys/${apiKey.id}/rotate`),
                {},
                { preserveScroll: true },
            );
        },
    });
}

function deleteApiKey(apiKey: { id: number; name: string }): void {
    openConfirmation({
        title: `Delete ${apiKey.name}?`,
        body: 'This API key will stop authenticating immediately. Existing applications using it must switch to another key first.',
        actionLabel: 'Delete key',
        tone: 'danger',
        onConfirm: () => {
            router.delete(projectAction(`/api-keys/${apiKey.id}`), {
                preserveScroll: true,
            });
        },
    });
}

function resetWebhookForm(): void {
    editingWebhookId.value = null;
    webhookForm.url = '';
    webhookForm.events = ['delivery', 'bounce', 'complaint', 'open', 'click'];
    webhookForm.status = 'active';
    showWebhookForm.value = true;
}

function editWebhook(webhook: WebhookEndpointRow): void {
    editingWebhookId.value = webhook.id;
    webhookForm.url = webhook.url;
    webhookForm.events = [...webhook.events];
    webhookForm.status =
        webhook.configured_status === 'paused' ? 'paused' : 'active';
    showWebhookForm.value = true;
}

function toggleWebhookEvent(event: string): void {
    if (webhookForm.events.includes(event)) {
        webhookForm.events = webhookForm.events.filter(
            (value) => value !== event,
        );

        return;
    }

    webhookForm.events = [...webhookForm.events, event];
}

function saveWebhookEndpoint(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showWebhookForm.value = false;
            editingWebhookId.value = null;
        },
    };

    if (editingWebhookId.value) {
        router.put(
            projectAction(`/webhooks/${editingWebhookId.value}`),
            webhookForm,
            options,
        );

        return;
    }

    router.post(projectAction('/webhooks'), webhookForm, options);
}

function retrySoftBounces(): void {
    router.post(
        projectAction('/bounces/retry-soft'),
        {},
        { preserveScroll: true },
    );
}

function recipientList(value: string): string[] {
    return value
        .split(/[\n,]+/)
        .map((address) => address.trim())
        .filter(Boolean);
}

function sendEmail(): void {
    router.post(
        projectAction('/send'),
        {
            ...sendForm,
            to: recipientList(sendForm.to),
            cc: recipientList(sendForm.cc),
            bcc: recipientList(sendForm.bcc),
        },
        { preserveScroll: true },
    );
}

function resendEmail(): void {
    if (!selected.value?.id) {
        return;
    }

    router.post(
        projectAction(`/emails/${selected.value.id}/resend`),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                router.reload({
                    only: [
                        'emails',
                        'selectedEmail',
                        'metrics',
                        'sidebarCounts',
                        'quota',
                    ],
                    showProgress: false,
                });
            },
        },
    );
}

function createProject(): void {
    router.post('/projects', projectForm, {
        preserveScroll: true,
        onSuccess: () => {
            projectForm.name = '';
            projectForm.slug = '';
            showProjectForm.value = false;
        },
    });
}

function startProjectEdit(project: ProjectOption): void {
    editingProjectSlug.value = project.slug;
    projectEditForm.name = project.name;
    projectEditForm.slug = project.slug;
    projectEditForm.clearErrors();
}

function cancelProjectEdit(): void {
    editingProjectSlug.value = null;
    projectEditForm.reset();
    projectEditForm.clearErrors();
}

function updateProject(project: ProjectOption): void {
    projectEditForm.put(`/projects/${project.slug}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingProjectSlug.value = null;
        },
    });
}

function archiveProject(project: ProjectOption): void {
    openConfirmation({
        title: `Archive ${project.name}?`,
        body: 'Email history, domains, API keys, and webhooks stay stored. The project moves out of active navigation and can be restored later.',
        actionLabel: 'Archive project',
        tone: 'warning',
        onConfirm: () => {
            archivingProjectSlug.value = project.slug;
            router.post(
                `/projects/${project.slug}/archive`,
                {},
                {
                    preserveScroll: true,
                    onFinish: () => {
                        archivingProjectSlug.value = null;
                    },
                },
            );
        },
    });
}

function restoreProject(project: ArchivedProjectOption): void {
    restoringProjectSlug.value = project.slug;
    router.post(
        `/projects/${project.slug}/restore`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                restoringProjectSlug.value = null;
            },
        },
    );
}

function deleteProject(project: ProjectOption): void {
    if (!project.can_delete) {
        openConfirmation({
            title: 'Archive this project instead',
            body: `${project.name} ${project.delete_block_reason ?? 'is not empty'}. Deleting is only available for projects with no sends and no domains.`,
            actionLabel: 'Archive project',
            tone: 'warning',
            onConfirm: () => archiveProject(project),
        });

        return;
    }

    openConfirmation({
        title: `Delete ${project.name}?`,
        body: 'This project has no sends or domains. Deleting it permanently removes its empty configuration shell.',
        actionLabel: 'Delete project',
        tone: 'danger',
        onConfirm: () => {
            deletingProjectSlug.value = project.slug;
            router.delete(`/projects/${project.slug}`, {
                preserveScroll: true,
                onFinish: () => {
                    deletingProjectSlug.value = null;
                },
            });
        },
    });
}

function addWorkspaceMember(): void {
    workspaceMemberForm.post('/workspace/members', {
        preserveScroll: true,
        onSuccess: () => {
            workspaceMemberForm.reset();
        },
    });
}

function updateWorkspaceMemberRole(
    memberId: number,
    role: WorkspaceMemberRole,
): void {
    router.put(
        `/workspace/members/${memberId}`,
        { role },
        { preserveScroll: true },
    );
}

function handleWorkspaceMemberRoleChange(memberId: number, event: Event): void {
    const target = event.target;

    if (target instanceof HTMLSelectElement) {
        updateWorkspaceMemberRole(
            memberId,
            target.value as WorkspaceMemberRole,
        );
    }
}

function removeWorkspaceMember(memberId: number): void {
    openConfirmation({
        title: 'Remove workspace member?',
        body: 'This user will lose access to every project in this workspace.',
        actionLabel: 'Remove member',
        tone: 'danger',
        onConfirm: () => {
            router.delete(`/workspace/members/${memberId}`, {
                preserveScroll: true,
            });
        },
    });
}

function openConfirmation(state: Exclude<ConfirmationState, null>): void {
    confirmation.value = state;
}

function closeConfirmation(): void {
    confirmation.value = null;
}

function confirmAction(): void {
    const state = confirmation.value;

    if (!state) {
        return;
    }

    confirmation.value = null;
    state.onConfirm();
}

function roleLabel(role: WorkspaceMemberRole): string {
    return (
        workspaceRoleOptions.find((option) => option.value === role)?.label ??
        role.replace('_', ' ')
    );
}

function selectEmail(email: EmailRow): void {
    selected.value = email as EmailDetail;
    activeTab.value = 'preview';
}

function closeInspector(): void {
    selected.value = null;
}

function applySearch(): void {
    router.get(
        sectionPath.value,
        { q: searchQuery.value, range: selectedRange.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function setRange(range: string): void {
    selectedRange.value = range;
    applySearch();
}

function startOfDay(date: Date): Date {
    const value = new Date(date);
    value.setHours(0, 0, 0, 0);

    return value;
}

function startOfWeek(date: Date): Date {
    const value = startOfDay(date);
    const day = value.getDay();
    const daysSinceMonday = day === 0 ? 6 : day - 1;
    value.setDate(value.getDate() - daysSinceMonday);

    return value;
}

function startOfMonth(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), 1);
}

function dateGroupLabel(date: Date): string {
    const today = startOfDay(new Date());
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);

    const thisWeek = startOfWeek(today);
    const lastWeek = new Date(thisWeek);
    lastWeek.setDate(thisWeek.getDate() - 7);

    const thisMonth = startOfMonth(today);
    const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    const thisYear = new Date(today.getFullYear(), 0, 1);
    const emailDay = startOfDay(date);

    if (emailDay >= today) {
        return 'Today';
    }

    if (emailDay >= yesterday) {
        return 'Yesterday';
    }

    if (emailDay >= thisWeek) {
        return 'This Week';
    }

    if (emailDay >= lastWeek) {
        return 'Last Week';
    }

    if (emailDay >= thisMonth) {
        return 'This Month';
    }

    if (emailDay >= lastMonth) {
        return 'Last Month';
    }

    if (emailDay >= thisYear) {
        return 'This Year';
    }

    return 'Older';
}

function handleGlobalShortcut(event: KeyboardEvent): void {
    if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 'k') {
        return;
    }

    if (!isMailSection.value) {
        return;
    }

    event.preventDefault();
    searchInput.value?.focus();
    searchInput.value?.select();
}

function statusClass(status: string): string {
    return (
        {
            queued: 'bg-amber-500/12 text-amber-400',
            sending: 'bg-sky-500/12 text-sky-400',
            sent: 'bg-emerald-500/12 text-emerald-400',
            delivered: 'bg-emerald-500/12 text-emerald-400',
            opened: 'bg-blue-500/12 text-blue-400',
            clicked: 'bg-teal-500/12 text-teal-300',
            bounced: 'bg-red-500/12 text-red-400',
            complained: 'bg-violet-500/12 text-violet-400',
            failed: 'bg-red-500/12 text-red-400',
        }[status] ?? 'bg-zinc-500/12 text-zinc-400'
    );
}

function dotClass(status: string): string {
    return (
        {
            queued: 'bg-amber-400',
            sending: 'bg-sky-400',
            sent: 'bg-emerald-400',
            delivered: 'bg-emerald-400',
            opened: 'bg-blue-400',
            clicked: 'bg-teal-300',
            bounced: 'bg-red-400',
            complained: 'bg-violet-400',
            failed: 'bg-red-400',
        }[status] ?? 'bg-zinc-400'
    );
}

function healthToneClass(tone: HealthTone): string {
    return (
        {
            success: 'bg-emerald-400',
            warning: 'bg-amber-400',
            neutral: 'bg-zinc-400',
        }[tone] ?? 'bg-zinc-400'
    );
}

function eventToneClass(type: string): string {
    return (
        {
            queued: 'bg-amber-400',
            sending: 'bg-sky-400',
            send: 'bg-emerald-400',
            sent: 'bg-emerald-400',
            delivery: 'bg-emerald-400',
            delivered: 'bg-emerald-400',
            open: 'bg-blue-400',
            opened: 'bg-blue-400',
            click: 'bg-teal-300',
            clicked: 'bg-teal-300',
            bounce: 'bg-red-400',
            bounced: 'bg-red-400',
            complaint: 'bg-violet-400',
            complained: 'bg-violet-400',
            failed: 'bg-red-400',
        }[type] ?? 'bg-zinc-400'
    );
}

function clampInspectorWidth(width: number): number {
    if (typeof window === 'undefined') {
        return width;
    }

    const maxWidth = Math.max(460, Math.min(960, window.innerWidth - 360));

    return Math.min(Math.max(width, 420), maxWidth);
}

function startInspectorResize(event: PointerEvent): void {
    event.preventDefault();
    resizeStartX = event.clientX;
    resizeStartWidth = inspectorWidth.value;
    isResizingInspector.value = true;
    document.body.style.cursor = 'col-resize';
    document.body.style.userSelect = 'none';
    window.addEventListener('pointermove', resizeInspector);
    window.addEventListener('pointerup', stopInspectorResize);
}

function resizeInspector(event: PointerEvent): void {
    if (!isResizingInspector.value) {
        return;
    }

    const nextWidth = resizeStartWidth - (event.clientX - resizeStartX);
    inspectorWidth.value = clampInspectorWidth(nextWidth);
}

function stopInspectorResize(): void {
    if (!isResizingInspector.value) {
        return;
    }

    isResizingInspector.value = false;
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
    window.localStorage.setItem(
        'larasend:inspectorWidth',
        String(inspectorWidth.value),
    );
    window.removeEventListener('pointermove', resizeInspector);
    window.removeEventListener('pointerup', stopInspectorResize);
}

function formatHeaders(email: Partial<EmailDetail>): string {
    const standardHeaders: Record<string, string> = {
        From: email.from || 'Stored sender',
        To: email.to || email.recipientEmail || 'Stored recipient',
        Subject: email.subject || 'Stored message',
        'Message-ID': `<${email.id || 'stored-message'}@larasend>`,
    };

    if (email.cc) {
        standardHeaders.Cc = email.cc;
    }

    if (email.bcc) {
        standardHeaders.Bcc = email.bcc;
    }

    if (email.sesMessageId) {
        standardHeaders[
            isCloudflare.value ? 'X-Provider-Message-ID' : 'X-SES-Message-ID'
        ] = email.sesMessageId;
    }

    const headers = {
        ...standardHeaders,
        ...(email.headers ?? {}),
    };

    return Object.entries(headers)
        .map(([key, value]) => `${key}: ${value}`)
        .join('\n');
}

function recipientLine(email: EmailRow): string {
    return email.recipient;
}

function recipientTitle(email: EmailRow): string | undefined {
    const fullList = email.recipientEmails || email.recipientEmail;

    if (!fullList || fullList === email.recipient) {
        return undefined;
    }

    return fullList;
}
</script>

<template>
    <Head :title="pageTitle" />

    <div
        class="h-screen overflow-hidden bg-[#fbfaf7] pb-16 font-sans text-sm text-zinc-900 antialiased lg:pb-0 dark:bg-[#0b0c0d] dark:text-[#e9eaec]"
    >
        <div
            class="grid h-full min-h-0 grid-cols-1 grid-rows-[60px_minmax(0,1fr)] lg:grid-cols-[248px_minmax(0,1fr)] lg:grid-rows-[64px_minmax(0,1fr)]"
        >
            <GlobalRail
                class="col-start-1 row-span-2 row-start-1"
                :project-path="projectBasePath"
                :project-name="project.name"
                :project-slug="project.slug"
                :section="section"
                :projects="projects"
                :counts="sidebarCounts"
                :inbox-unread="inboxUnread"
                :build-label="buildLabel"
            />
            <header
                class="col-start-1 row-start-1 flex min-w-0 items-center gap-2 border-b border-zinc-200 bg-[#fbfaf7] px-3 sm:gap-3 sm:px-4 lg:col-start-2 dark:border-[#1d2125] dark:bg-[#0b0c0d]"
            >
                <div class="flex min-w-0 items-center gap-2.5 lg:hidden">
                    <Link
                        href="/dashboard"
                        class="grid size-8 shrink-0 place-items-center rounded-lg bg-teal-300 font-mono text-xs font-bold text-[#07221c]"
                    >
                        L
                    </Link>
                    <span class="min-w-0 truncate text-sm font-semibold">
                        {{ project.name }}
                    </span>
                </div>

                <form
                    v-if="isMailSection"
                    class="ml-auto hidden h-9 w-[min(420px,34vw)] items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 text-[13px] text-zinc-500 sm:flex dark:border-[#1d2125] dark:bg-[#111315] dark:text-[#9aa0a6]"
                    @submit.prevent="applySearch"
                >
                    <Search class="size-3.5" />
                    <input
                        ref="searchInput"
                        v-model="searchQuery"
                        class="min-w-0 flex-1 bg-transparent outline-none placeholder:text-zinc-400 dark:placeholder:text-[#6c7177]"
                        placeholder="Search messages, recipients, message IDs..."
                    />
                    <kbd
                        class="rounded border border-zinc-200 px-1.5 py-0.5 font-mono text-[10.5px] text-zinc-500 dark:border-[#1d2125] dark:text-[#6c7177]"
                        >⌘K</kbd
                    >
                </form>
                <div class="ml-auto flex items-center gap-1.5" v-else />
                <Link
                    v-if="isMailSection"
                    :href="`${sectionPath}?q=${encodeURIComponent(searchQuery)}&range=${encodeURIComponent(selectedRange)}`"
                    class="grid size-8 place-items-center rounded-md text-zinc-500 hover:bg-zinc-100 hover:text-zinc-950 dark:text-[#9aa0a6] dark:hover:bg-[#16191c] dark:hover:text-zinc-100"
                    title="Refresh"
                >
                    <RefreshCw class="size-3.5" />
                </Link>
                <Link
                    v-else
                    :href="sectionHref('setup')"
                    class="hidden h-9 items-center rounded-lg border border-zinc-200 bg-white px-3 font-sans text-[13px] font-medium text-zinc-700 hover:bg-zinc-100 sm:inline-flex dark:border-[#1d2125] dark:bg-[#111315] dark:text-zinc-200 dark:hover:bg-[#16191c]"
                >
                    Setup guide
                </Link>
                <Link
                    :href="sectionHref('send')"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-teal-300 px-3 font-sans text-[13px] font-semibold text-[#07221c] hover:brightness-105"
                >
                    <Send class="size-3.5" /> Send
                </Link>
            </header>

            <main
                class="col-start-1 row-start-2 flex min-h-0 min-w-0 flex-col overflow-hidden lg:col-start-2"
            >
                <div
                    v-if="showWorkerBanner"
                    class="flex shrink-0 flex-wrap items-center gap-2 border-b border-amber-300 bg-amber-50 px-3.5 py-2 font-sans text-[12.5px] text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
                >
                    <AlertTriangle class="size-4 shrink-0" />
                    <span class="font-semibold">
                        {{ system.stuck_queued }}
                        {{
                            system.stuck_queued === 1
                                ? 'email is'
                                : 'emails are'
                        }}
                        stuck in the queue and no queue worker is running.
                    </span>
                    <span>
                        Start one with
                        <code
                            class="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-[11.5px] dark:bg-amber-500/20"
                            >php artisan queue:work</code
                        >
                        (or
                        <code
                            class="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-[11.5px] dark:bg-amber-500/20"
                            >composer run dev</code
                        >
                        locally).
                    </span>
                </div>
                <section
                    class="flex min-h-14 shrink-0 items-center gap-2.5 border-b border-zinc-200 px-4 dark:border-[#1d2125]"
                >
                    <div class="flex items-center gap-3">
                        <h1
                            class="m-0 font-sans text-lg font-semibold tracking-tight"
                        >
                            {{ pageTitle }}
                        </h1>
                    </div>
                    <span
                        class="hidden items-center gap-1.5 rounded-full border border-zinc-200 px-2 py-1 font-mono text-[11px] text-zinc-500 sm:inline-flex dark:border-[#1d2125] dark:text-[#9aa0a6]"
                    >
                        <span
                            class="inline-block size-1.5 rounded-full bg-emerald-400 shadow-[0_0_0_4px_rgba(92,212,148,0.14)]"
                        />live
                    </span>
                    <div
                        v-if="isMailSection"
                        class="ml-auto hidden rounded-lg border border-zinc-200 bg-white p-0.5 text-xs md:flex dark:border-[#1d2125] dark:bg-[#111315]"
                    >
                        <button
                            v-for="range in ['1h', '24h', '7d', '14d', '30d']"
                            :key="range"
                            class="h-6 rounded-md px-2 font-medium text-zinc-500 dark:text-[#9aa0a6]"
                            :class="{
                                'bg-zinc-100 text-zinc-950 dark:bg-[#1a1e22] dark:text-zinc-100':
                                    range === selectedRange,
                            }"
                            @click="setRange(range)"
                        >
                            {{ range }}
                        </button>
                    </div>
                    <a
                        v-if="isMailSection"
                        :href="exportHref"
                        class="inline-flex h-7 items-center gap-1.5 rounded-md px-2 font-sans text-[12px] text-zinc-500 hover:bg-zinc-100 hover:text-zinc-950 dark:text-[#9aa0a6] dark:hover:bg-[#16191c] dark:hover:text-zinc-100"
                        >Export</a
                    >
                </section>

                <section
                    v-if="showMetrics"
                    class="grid shrink-0 grid-cols-2 border-b border-zinc-200 sm:grid-cols-3 xl:grid-cols-6 dark:border-[#1d2125]"
                >
                    <div
                        v-for="metric in metrics"
                        :key="metric.label"
                        class="min-h-[68px] min-w-0 border-r border-b border-zinc-200 px-4 py-3 last:border-r-0 hover:bg-zinc-100 xl:border-b-0 dark:border-[#1d2125] dark:hover:bg-[#111315]"
                    >
                        <div
                            class="truncate font-mono text-[10px] font-medium tracking-widest text-zinc-500 uppercase dark:text-[#6c7177]"
                        >
                            {{ metric.label }}
                        </div>
                        <div
                            class="mt-1.5 font-sans text-xl leading-none font-semibold tracking-tight text-zinc-950 dark:text-[#e9eaec]"
                        >
                            {{ metric.value }}
                        </div>
                        <div
                            v-if="metric.delta"
                            class="mt-1 inline-flex max-w-full items-center gap-1 truncate font-mono text-[10px]"
                            :class="{
                                'text-emerald-400': metric.tone === 'good',
                                'text-red-400': metric.tone === 'bad',
                                'text-zinc-500 dark:text-[#6c7177]':
                                    metric.tone === 'neutral',
                            }"
                        >
                            <span>{{
                                metric.trend === 'up'
                                    ? '▲'
                                    : metric.trend === 'down'
                                      ? '▼'
                                      : '•'
                            }}</span>
                            {{ metric.delta }}
                        </div>
                    </div>
                </section>

                <div
                    v-if="section === 'bounces'"
                    class="min-h-0 flex-1 overflow-auto px-4 py-3"
                >
                    <section
                        class="grid grid-cols-5 overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-[#101111]"
                    >
                        <div
                            v-for="metric in bounceMetrics"
                            :key="metric.label"
                            class="border-r border-zinc-200 px-3 py-2.5 last:border-r-0 dark:border-zinc-800"
                        >
                            <div
                                class="font-sans text-xs font-medium tracking-widest text-zinc-500 uppercase"
                            >
                                {{ metric.label }}
                            </div>
                            <div
                                class="mt-3 font-sans text-xl font-semibold tracking-tight"
                            >
                                {{ metric.value }}
                            </div>
                            <div
                                class="mt-1 text-sm"
                                :class="
                                    metric.tone === 'danger'
                                        ? 'text-red-400'
                                        : metric.tone === 'success'
                                          ? 'text-emerald-400'
                                          : 'text-zinc-500'
                                "
                            >
                                {{ metric.meta }}
                            </div>
                        </div>
                    </section>

                    <section class="mt-4">
                        <div class="flex items-end gap-4">
                            <div>
                                <h2 class="font-sans text-base font-semibold">
                                    Bounce queue
                                </h2>
                                <p class="mt-1 font-sans text-sm text-zinc-500">
                                    {{ hardBounceCount }} hard ·
                                    {{ softBounceCount }} soft ·
                                    {{ selectedRange }}
                                </p>
                            </div>
                            <div class="ml-auto flex gap-2">
                                <a
                                    :href="exportHref"
                                    class="inline-flex items-center gap-2 rounded-md border border-zinc-200 px-3 py-1.5 font-sans text-sm font-semibold text-zinc-600 hover:text-zinc-950 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100"
                                >
                                    <ArrowUpRight class="size-4" /> Export
                                </a>
                                <button
                                    class="inline-flex items-center gap-2 rounded-md border border-zinc-200 px-3 py-1.5 font-sans text-sm font-semibold text-zinc-600 hover:text-zinc-950 disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100"
                                    :disabled="softBounceCount === 0"
                                    @click="retrySoftBounces"
                                >
                                    <RefreshCw class="size-4" /> Retry soft
                                    bounces
                                </button>
                            </div>
                        </div>

                        <div
                            class="mt-4 overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-[#090a0a]"
                        >
                            <div
                                class="grid min-w-[1080px] grid-cols-[96px_minmax(220px,1fr)_minmax(260px,1.25fr)_130px_minmax(180px,.8fr)_minmax(190px,.9fr)_100px_100px_42px] border-b border-zinc-200 bg-zinc-50 px-3 py-2.5 font-mono text-xs tracking-widest text-zinc-500 uppercase dark:border-zinc-800 dark:bg-[#101111]"
                            >
                                <div>Type</div>
                                <div>Recipient</div>
                                <div>Reason</div>
                                <div>SMTP</div>
                                <div>MX</div>
                                <div>Template</div>
                                <div class="text-right">Attempts</div>
                                <div class="text-right">When</div>
                                <div></div>
                            </div>
                            <div class="max-h-[58vh] overflow-auto">
                                <Link
                                    v-for="bounce in bounceQueue"
                                    :key="bounce.id"
                                    :href="`${sectionHref('activity')}?q=${encodeURIComponent(bounce.id)}`"
                                    class="grid min-w-[1080px] grid-cols-[96px_minmax(220px,1fr)_minmax(260px,1.25fr)_130px_minmax(180px,.8fr)_minmax(190px,.9fr)_100px_100px_42px] items-center border-b border-zinc-200 px-3 py-2.5 font-sans text-sm last:border-b-0 hover:bg-zinc-50 dark:border-zinc-900 dark:hover:bg-zinc-950"
                                >
                                    <span>
                                        <span
                                            class="rounded-md px-2 py-1 font-mono text-xs"
                                            :class="
                                                bounce.type === 'Hard'
                                                    ? 'bg-red-500/12 text-red-400'
                                                    : 'bg-amber-500/12 text-amber-300'
                                            "
                                            >{{ bounce.type }}</span
                                        >
                                    </span>
                                    <span class="truncate font-medium">{{
                                        bounce.recipient
                                    }}</span>
                                    <span
                                        class="truncate text-zinc-600 dark:text-zinc-300"
                                        >{{ bounce.reason }}</span
                                    >
                                    <span
                                        class="truncate font-mono text-zinc-500"
                                        >{{ bounce.smtp }}</span
                                    >
                                    <span
                                        class="truncate font-mono text-zinc-500"
                                        >{{ bounce.mx }}</span
                                    >
                                    <span
                                        class="truncate font-mono text-zinc-500"
                                        >{{ bounce.template || 'custom' }}</span
                                    >
                                    <span class="text-right font-mono">{{
                                        bounce.attempts
                                    }}</span>
                                    <span class="text-right text-zinc-500">{{
                                        bounce.when
                                    }}</span>
                                    <span class="text-right text-zinc-500"
                                        >›</span
                                    >
                                </Link>
                                <div
                                    v-if="bounceQueue.length === 0"
                                    class="px-4 py-10 text-center font-sans text-sm text-zinc-500"
                                >
                                    No bounces in this range.
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <div
                    v-else-if="section === 'complaints'"
                    class="min-h-0 flex-1 overflow-auto px-[22px] py-[18px]"
                >
                    <section
                        class="mb-[18px] grid grid-cols-4 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-[#1d2125] dark:bg-[#111315]"
                    >
                        <div
                            class="border-r border-zinc-200 px-4 py-3 dark:border-[#1d2125]"
                        >
                            <div
                                class="font-mono text-[10.5px] tracking-widest text-zinc-500 uppercase dark:text-[#6c7177]"
                            >
                                Complaint rate
                            </div>
                            <div class="mt-1 font-sans text-xl font-semibold">
                                {{ complaintRate }}
                            </div>
                            <div
                                class="mt-0.5 font-mono text-[11.5px] text-zinc-500 dark:text-[#9aa0a6]"
                            >
                                {{ emails.length }} messages in range
                            </div>
                        </div>
                        <div
                            class="border-r border-zinc-200 px-4 py-3 dark:border-[#1d2125]"
                        >
                            <div
                                class="font-mono text-[10.5px] tracking-widest text-zinc-500 uppercase dark:text-[#6c7177]"
                            >
                                Total complaints
                            </div>
                            <div class="mt-1 font-sans text-xl font-semibold">
                                {{ complaintRows.length }}
                            </div>
                            <div
                                class="mt-0.5 font-mono text-[11.5px] text-zinc-500 dark:text-[#9aa0a6]"
                            >
                                selected range
                            </div>
                        </div>
                        <div
                            class="border-r border-zinc-200 px-4 py-3 dark:border-[#1d2125]"
                        >
                            <div
                                class="font-mono text-[10.5px] tracking-widest text-zinc-500 uppercase dark:text-[#6c7177]"
                            >
                                Suppressed
                            </div>
                            <div class="mt-1 font-sans text-xl font-semibold">
                                {{ complaintSuppressionCount }}
                            </div>
                            <div
                                class="mt-0.5 font-mono text-[11.5px] text-zinc-500 dark:text-[#9aa0a6]"
                            >
                                complaint recipients
                            </div>
                        </div>
                        <div class="px-4 py-3">
                            <div
                                class="font-mono text-[10.5px] tracking-widest text-zinc-500 uppercase dark:text-[#6c7177]"
                            >
                                Last complaint
                            </div>
                            <div class="mt-1 font-sans text-xl font-semibold">
                                {{ lastComplaintTime }}
                            </div>
                            <div
                                class="mt-0.5 font-mono text-[11.5px] text-zinc-500 dark:text-[#9aa0a6]"
                            >
                                observed event
                            </div>
                        </div>
                    </section>

                    <div
                        class="mb-4 flex items-center gap-3 rounded-lg border border-amber-400/30 bg-amber-400/10 px-3.5 py-2.5 text-[12.5px]"
                    >
                        <AlertTriangle class="size-3.5 text-amber-400" />
                        <div class="min-w-0 flex-1">
                            <div
                                class="font-medium text-zinc-950 dark:text-zinc-100"
                            >
                                Monitor complaint feedback loops before sender
                                reputation is affected.
                            </div>
                            <div class="text-zinc-500 dark:text-[#9aa0a6]">
                                {{
                                    isCloudflare
                                        ? 'Suppressions sync hourly from the Cloudflare account-level list.'
                                        : 'Complaint events create suppressions as SES webhook events arrive.'
                                }}
                            </div>
                        </div>
                        <Link
                            :href="sectionHref('suppressions')"
                            class="rounded-md border border-zinc-200 px-2 py-1 text-[11.5px] font-medium dark:border-[#1d2125]"
                            >Review suppressions</Link
                        >
                    </div>

                    <div class="mb-2 flex items-baseline gap-3">
                        <h2 class="font-sans text-[13px] font-semibold">
                            Feedback loop reports
                        </h2>
                        <span
                            class="font-sans text-[11.5px] text-zinc-500 dark:text-[#9aa0a6]"
                            >{{ complaintRows.length }} in selected range</span
                        >
                    </div>
                    <div
                        class="overflow-hidden rounded-lg border border-zinc-200 dark:border-[#1d2125]"
                    >
                        <div
                            class="grid grid-cols-[minmax(260px,1fr)_120px_minmax(220px,1fr)_120px] gap-3 border-b border-zinc-200 bg-white px-3.5 py-2 font-mono text-[10.5px] tracking-widest text-zinc-500 uppercase dark:border-[#1d2125] dark:bg-[#111315] dark:text-[#6c7177]"
                        >
                            <div>Recipient</div>
                            <div>Type</div>
                            <div>Subject</div>
                            <div class="text-right">When</div>
                        </div>
                        <div
                            v-for="email in complaintRows"
                            :key="email.id"
                            class="grid min-h-11 grid-cols-[minmax(260px,1fr)_120px_minmax(220px,1fr)_120px] items-center gap-3 border-b border-zinc-100 px-3.5 py-2 text-[12.5px] last:border-b-0 dark:border-[#16191c]"
                        >
                            <div class="truncate font-medium">
                                {{
                                    email.recipientEmails ||
                                    email.recipientEmail
                                }}
                            </div>
                            <div>
                                <span
                                    class="rounded bg-red-400/10 px-1.5 py-0.5 font-mono text-[10.5px] text-red-400"
                                    >abuse</span
                                >
                            </div>
                            <div
                                class="truncate text-zinc-500 dark:text-[#9aa0a6]"
                            >
                                {{ email.subject }}
                            </div>
                            <div
                                class="text-right font-mono text-zinc-500 dark:text-[#6c7177]"
                            >
                                {{ email.time }}
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-else-if="isMailSection"
                    class="grid min-h-0 flex-1 overflow-hidden"
                    :style="{
                        gridTemplateColumns: selected
                            ? `minmax(0, 1fr) ${inspectorWidth}px`
                            : 'minmax(0, 1fr)',
                    }"
                >
                    <section
                        class="flex min-h-0 min-w-0 flex-col overflow-hidden border-r border-zinc-200 dark:border-[#1d2125]"
                    >
                        <div
                            class="flex shrink-0 items-center gap-2 overflow-x-auto border-b border-zinc-200 px-3.5 py-2.5 dark:border-[#1d2125]"
                        >
                            <button
                                v-for="filter in statusFilters"
                                :key="filter"
                                class="inline-flex h-[26px] items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-2.5 font-sans text-[11.5px] text-zinc-500 hover:text-zinc-950 dark:border-[#1d2125] dark:bg-[#111315] dark:text-[#9aa0a6] dark:hover:text-zinc-100"
                                :class="{
                                    'border-zinc-300 bg-zinc-100 text-zinc-950 dark:border-[#262b30] dark:bg-[#1a1e22] dark:text-zinc-100':
                                        selectedFilter === filter,
                                }"
                                @click="selectedFilter = filter"
                            >
                                {{ filter }}
                                <span
                                    class="border-l border-zinc-200 pl-1.5 font-mono text-[10.5px] text-zinc-500 dark:border-[#262b30] dark:text-[#6c7177]"
                                    >{{ statusFilterCounts[filter] ?? 0 }}</span
                                >
                            </button>
                            <button
                                class="ml-auto inline-flex h-[26px] items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-2.5 font-sans text-[11.5px] text-zinc-500 hover:text-zinc-950 dark:border-[#1d2125] dark:bg-[#111315] dark:text-[#9aa0a6] dark:hover:text-zinc-100"
                                @click="
                                    selectedFilter = 'All';
                                    searchQuery = '';
                                    applySearch();
                                "
                            >
                                <SlidersHorizontal class="size-4" /> Clear
                            </button>
                        </div>

                        <div
                            class="grid shrink-0 grid-cols-[22px_minmax(320px,1fr)_90px_110px_70px] gap-3 border-b border-zinc-200 px-3.5 py-1.5 font-mono text-[10.5px] tracking-widest text-zinc-500 uppercase dark:border-[#1d2125] dark:text-[#6c7177]"
                        >
                            <div></div>
                            <div>Subject · Recipient</div>
                            <div>Engagement</div>
                            <div>Status</div>
                            <div class="text-right">Time</div>
                        </div>

                        <div
                            class="min-h-0 flex-1 overflow-auto bg-[#fbfaf7] dark:bg-[#0b0c0d]"
                        >
                            <template
                                v-for="group in groupedEmails"
                                :key="group.label"
                            >
                                <div
                                    class="border-t border-zinc-100 px-3.5 pt-3.5 pb-1.5 font-mono text-[10.5px] tracking-widest text-zinc-500 uppercase first:border-t-0 dark:border-[#16191c] dark:text-[#6c7177]"
                                >
                                    {{ group.label }} · {{ group.rows.length }}
                                </div>
                                <button
                                    v-for="email in group.rows"
                                    :key="email.id"
                                    class="relative grid h-11 w-full min-w-[700px] grid-cols-[22px_minmax(320px,1fr)_90px_110px_70px] items-center gap-3 border-b border-zinc-100 px-3.5 text-left hover:bg-white dark:border-[#16191c] dark:hover:bg-[#111315]"
                                    :class="{
                                        'bg-zinc-100 before:absolute before:top-0 before:bottom-0 before:left-0 before:w-0.5 before:bg-teal-300 dark:bg-[#1a1e22]':
                                            selected?.id === email.id,
                                    }"
                                    @click="selectEmail(email)"
                                >
                                    <span
                                        class="size-2 rounded-full"
                                        :class="dotClass(email.status)"
                                    />
                                    <span
                                        class="min-w-0 font-sans leading-tight"
                                    >
                                        <span
                                            class="flex min-w-0 items-baseline gap-2 truncate text-[12.5px] font-medium text-zinc-950 dark:text-zinc-100"
                                        >
                                            <span
                                                class="min-w-0 truncate text-zinc-950 dark:text-zinc-100"
                                                >{{ email.subject }}</span
                                            >
                                        </span>
                                        <span
                                            class="mt-0.5 block truncate font-mono text-[11px] text-zinc-500 dark:text-[#6c7177]"
                                            :title="recipientTitle(email)"
                                            >{{ recipientLine(email) }}</span
                                        >
                                    </span>
                                    <span
                                        class="inline-flex gap-2 font-mono text-[11px] text-zinc-500 dark:text-[#6c7177]"
                                    >
                                        <span
                                            :class="{
                                                'text-zinc-900 dark:text-zinc-100':
                                                    email.opens,
                                            }"
                                            >◎ {{ email.opens }}</span
                                        >
                                        <span
                                            :class="{
                                                'text-zinc-900 dark:text-zinc-100':
                                                    email.clicks,
                                            }"
                                            >↗ {{ email.clicks }}</span
                                        >
                                    </span>
                                    <span>
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded px-1.5 py-0.5 font-mono text-[10.5px]"
                                            :class="statusClass(email.status)"
                                            ><span
                                                class="size-1.5 rounded-full bg-current"
                                            />{{ email.status }}</span
                                        >
                                    </span>
                                    <span
                                        class="text-right font-mono text-[11px] text-zinc-500 dark:text-[#6c7177]"
                                        >{{ email.time }}</span
                                    >
                                </button>
                            </template>
                        </div>
                    </section>

                    <aside
                        v-if="selected"
                        class="relative flex min-h-0 min-w-0 flex-col overflow-hidden border-l border-zinc-200 bg-[#fbfaf7] dark:border-[#1d2125] dark:bg-[#0b0c0d]"
                    >
                        <button
                            type="button"
                            class="absolute top-0 bottom-0 left-0 z-10 w-2 cursor-col-resize border-l border-transparent hover:border-teal-300 focus-visible:border-teal-300 focus-visible:outline-none"
                            :class="{
                                'border-teal-300 bg-teal-300/10':
                                    isResizingInspector,
                            }"
                            title="Resize details panel"
                            @pointerdown="startInspectorResize"
                        />
                        <div
                            class="border-b border-zinc-200 px-3.5 py-3 dark:border-[#1d2125]"
                        >
                            <div class="flex items-center gap-2 text-[12px]">
                                <div
                                    class="flex min-w-0 flex-1 items-center gap-2"
                                >
                                    <span
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded px-2 py-0.5 font-mono"
                                        :class="
                                            statusClass(
                                                selected.status || 'sent',
                                            )
                                        "
                                        ><span
                                            class="size-1.5 rounded-full bg-current"
                                        />{{ selected.status }}</span
                                    >
                                    <span
                                        class="min-w-0 truncate font-mono text-zinc-500"
                                        >{{ selected.id }}</span
                                    >
                                </div>

                                <div
                                    class="ml-auto flex shrink-0 items-center gap-1"
                                >
                                    <button
                                        class="inline-flex size-7 items-center justify-center rounded-md text-zinc-500 hover:bg-zinc-100 hover:text-zinc-950 dark:hover:bg-[#111315] dark:hover:text-zinc-100"
                                        title="Copy message ID"
                                        @click="copyText(selected.id || '')"
                                    >
                                        <Copy class="size-4" />
                                    </button>
                                    <a
                                        v-if="selected.previewUrl"
                                        :href="selected.previewUrl"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex size-7 items-center justify-center rounded-md text-zinc-500 hover:bg-zinc-100 hover:text-zinc-950 dark:hover:bg-[#111315] dark:hover:text-zinc-100"
                                        title="Open full email"
                                    >
                                        <ArrowUpRight class="size-4" />
                                    </a>
                                    <button
                                        class="inline-flex h-7 items-center rounded-md px-2 text-[12px] font-semibold text-zinc-500 hover:bg-zinc-100 hover:text-zinc-950 dark:hover:bg-[#111315] dark:hover:text-zinc-100"
                                        @click="resendEmail"
                                    >
                                        Resend
                                    </button>
                                    <button
                                        class="inline-flex size-7 items-center justify-center rounded-md text-zinc-500 hover:bg-zinc-100 hover:text-zinc-950 dark:hover:bg-[#111315] dark:hover:text-zinc-100"
                                        title="Close details"
                                        @click="closeInspector"
                                    >
                                        <X class="size-4" />
                                    </button>
                                </div>
                            </div>
                            <h2
                                class="mt-3 text-[15px] leading-6 font-semibold"
                            >
                                {{ selected.subject }}
                            </h2>
                            <dl
                                class="mt-3 grid grid-cols-[64px_1fr] gap-x-4 gap-y-2 text-[12px]"
                            >
                                <dt
                                    class="font-mono tracking-widest text-zinc-500 uppercase"
                                >
                                    From
                                </dt>
                                <dd class="truncate">
                                    {{ selected.from || 'Stored sender' }}
                                </dd>
                                <dt
                                    class="font-mono tracking-widest text-zinc-500 uppercase"
                                >
                                    To
                                </dt>
                                <dd class="truncate">
                                    {{ selected.to || selected.recipientEmail }}
                                </dd>
                                <template v-if="selected.cc">
                                    <dt
                                        class="font-mono tracking-widest text-zinc-500 uppercase"
                                    >
                                        Cc
                                    </dt>
                                    <dd class="truncate">
                                        {{ selected.cc }}
                                    </dd>
                                </template>
                                <template v-if="selected.bcc">
                                    <dt
                                        class="font-mono tracking-widest text-zinc-500 uppercase"
                                    >
                                        Bcc
                                    </dt>
                                    <dd class="truncate">
                                        {{ selected.bcc }}
                                    </dd>
                                </template>
                                <dt
                                    class="font-mono tracking-widest text-zinc-500 uppercase"
                                >
                                    Sent
                                </dt>
                                <dd>{{ selected.time }} ago</dd>
                                <dt
                                    v-if="selected.template"
                                    class="font-mono tracking-widest text-zinc-500 uppercase"
                                >
                                    Template
                                </dt>
                                <dd v-if="selected.template" class="truncate">
                                    {{ selected.template }}
                                </dd>
                            </dl>
                        </div>

                        <div
                            class="flex gap-5 border-b border-zinc-200 px-3.5 dark:border-[#1d2125]"
                        >
                            <button
                                v-for="tab in [
                                    'preview',
                                    'timeline',
                                    'headers',
                                    'metrics',
                                ]"
                                :key="tab"
                                class="py-2.5 text-[12px] font-semibold text-zinc-500 capitalize"
                                :class="{
                                    'border-b-2 border-teal-400 text-zinc-950 dark:text-zinc-100':
                                        activeTab === tab,
                                }"
                                @click="
                                    activeTab = tab as
                                        | 'timeline'
                                        | 'preview'
                                        | 'headers'
                                        | 'metrics'
                                "
                            >
                                {{ tab }}
                                <span
                                    v-if="tab === 'timeline'"
                                    class="ml-1 rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] text-zinc-500 dark:bg-[#1a1e22]"
                                    >{{ selected.events?.length || 0 }}</span
                                >
                            </button>
                        </div>

                        <div class="min-h-0 flex-1 overflow-auto p-3.5">
                            <div
                                v-if="activeTab === 'preview'"
                                class="overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100 dark:border-[#1d2125] dark:bg-[#111315]"
                            >
                                <div
                                    class="flex items-center border-b border-zinc-200 px-3 py-2 text-[11px] text-zinc-500 dark:border-[#1d2125]"
                                >
                                    <span
                                        class="mr-1 size-2.5 rounded-full bg-zinc-300"
                                    />
                                    <span
                                        class="mr-1 size-2.5 rounded-full bg-zinc-300"
                                    />
                                    <span
                                        class="size-2.5 rounded-full bg-zinc-300"
                                    />
                                    <span class="ml-auto"
                                        >HTML ·
                                        {{
                                            (
                                                (selected.mimeSize || 0) / 1000
                                            ).toFixed(1)
                                        }}
                                        KB</span
                                    >
                                </div>
                                <iframe
                                    title="Email preview"
                                    class="h-[520px] w-full bg-white"
                                    sandbox=""
                                    :srcdoc="
                                        selected.html || selected.text || ''
                                    "
                                />
                            </div>
                            <div
                                v-else-if="activeTab === 'timeline'"
                                class="divide-y divide-zinc-200 overflow-hidden rounded-lg border border-zinc-200 dark:divide-[#16191c] dark:border-[#1d2125]"
                            >
                                <div
                                    v-for="event in selected.events"
                                    :key="`${event.type}-${event.occurredAt}`"
                                    class="grid grid-cols-[18px_74px_minmax(0,1fr)] gap-3 px-3 py-3 text-[12px]"
                                >
                                    <span
                                        class="mt-1.5 size-2 rounded-full"
                                        :class="eventToneClass(event.type)"
                                    />
                                    <span
                                        class="font-mono text-[11px] text-zinc-500"
                                        >{{ event.occurredAt }}</span
                                    >
                                    <div class="min-w-0">
                                        <div
                                            class="font-semibold text-zinc-900 capitalize dark:text-zinc-100"
                                        >
                                            {{ event.type }}
                                        </div>
                                        <div
                                            class="mt-0.5 truncate font-mono text-[11px] text-zinc-500"
                                        >
                                            {{ event.recipient }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <pre
                                v-else-if="activeTab === 'headers'"
                                class="max-h-[520px] overflow-auto rounded-lg border border-zinc-200 bg-white p-3 font-mono text-[11px] leading-5 text-zinc-600 dark:border-[#1d2125] dark:bg-[#090a0a] dark:text-zinc-400"
                                >{{ formatHeaders(selected) }}</pre
                            >
                            <div
                                v-else
                                class="grid grid-cols-2 overflow-hidden rounded-lg border border-zinc-200 dark:border-[#1d2125]"
                            >
                                <div
                                    class="border-r border-zinc-200 p-3 dark:border-[#1d2125]"
                                >
                                    <span
                                        class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase"
                                        >Opens</span
                                    >
                                    <strong class="mt-2 block text-xl">{{
                                        selected.opens
                                    }}</strong>
                                </div>
                                <div class="p-3">
                                    <span
                                        class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase"
                                        >Clicks</span
                                    >
                                    <strong class="mt-2 block text-xl">{{
                                        selected.clicks
                                    }}</strong>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>

                <section
                    v-else
                    class="min-h-0 flex-1 overflow-auto bg-[#fbfaf7] p-4 dark:bg-[#0b0c0d]"
                >
                    <div v-if="section === 'projects'" class="grid gap-4">
                        <div
                            class="flex max-w-6xl items-start justify-between gap-4"
                        >
                            <div>
                                <h2 class="text-lg font-semibold">Workspace</h2>
                                <p class="mt-1 max-w-2xl text-sm text-zinc-500">
                                    Manage project boundaries and workspace
                                    access. Projects isolate domains, sources,
                                    API keys, templates, webhooks, and activity.
                                </p>
                            </div>
                            <button
                                type="button"
                                class="rounded-lg bg-teal-300 px-3 py-2 text-sm font-bold text-zinc-950"
                                @click="showProjectForm = true"
                            >
                                + New project
                            </button>
                        </div>

                        <div class="grid max-w-6xl gap-3">
                            <div
                                class="grid overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-[#1d2125] dark:bg-[#101111]"
                            >
                                <div
                                    class="grid grid-cols-[minmax(280px,1fr)_120px_120px_120px_130px_220px] border-b border-zinc-200 bg-zinc-50 px-3 py-2 font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:border-[#1d2125] dark:bg-[#111315]"
                                >
                                    <div>Project</div>
                                    <div>Environment</div>
                                    <div>Region</div>
                                    <div class="text-right">Sends</div>
                                    <div class="text-right">Domains</div>
                                    <div class="text-right">Actions</div>
                                </div>
                                <div
                                    v-for="item in projects"
                                    :key="item.slug"
                                    class="grid min-h-14 grid-cols-[minmax(280px,1fr)_120px_120px_120px_130px_220px] items-center gap-2 border-b border-zinc-200 px-3 py-2 text-sm last:border-b-0 dark:border-[#16191c]"
                                    :class="{
                                        'bg-teal-500/5 dark:bg-teal-500/10':
                                            item.is_current,
                                    }"
                                >
                                    <form
                                        v-if="editingProjectSlug === item.slug"
                                        class="col-span-6 grid grid-cols-[minmax(220px,1fr)_minmax(180px,260px)_auto] items-start gap-2"
                                        @submit.prevent="updateProject(item)"
                                    >
                                        <div>
                                            <input
                                                v-model="projectEditForm.name"
                                                class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-[#101111]"
                                                required
                                            />
                                            <p
                                                v-if="
                                                    projectEditForm.errors.name
                                                "
                                                class="mt-1 text-xs text-red-500"
                                            >
                                                {{
                                                    projectEditForm.errors.name
                                                }}
                                            </p>
                                        </div>
                                        <div>
                                            <input
                                                v-model="projectEditForm.slug"
                                                class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 font-mono text-sm dark:border-zinc-800 dark:bg-[#101111]"
                                                required
                                            />
                                            <p
                                                v-if="
                                                    projectEditForm.errors.slug
                                                "
                                                class="mt-1 text-xs text-red-500"
                                            >
                                                {{
                                                    projectEditForm.errors.slug
                                                }}
                                            </p>
                                        </div>
                                        <div class="flex justify-end gap-2">
                                            <button
                                                type="button"
                                                class="h-9 rounded-lg border border-zinc-200 px-3 text-sm font-semibold text-zinc-600 hover:text-zinc-950 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100"
                                                @click="cancelProjectEdit"
                                            >
                                                Cancel
                                            </button>
                                            <button
                                                type="submit"
                                                class="h-9 rounded-lg bg-teal-300 px-3 text-sm font-bold text-zinc-950 disabled:cursor-wait disabled:opacity-60"
                                                :disabled="
                                                    projectEditForm.processing
                                                "
                                            >
                                                Save
                                            </button>
                                        </div>
                                    </form>

                                    <template v-else>
                                        <div class="min-w-0">
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <Link
                                                    :href="item.href"
                                                    class="truncate font-semibold text-zinc-950 hover:text-teal-600 dark:text-zinc-100 dark:hover:text-teal-300"
                                                >
                                                    {{ item.name }}
                                                </Link>
                                                <span
                                                    v-if="item.is_current"
                                                    class="rounded bg-teal-500/12 px-1.5 py-0.5 font-mono text-[10px] text-teal-600 dark:text-teal-300"
                                                >
                                                    current
                                                </span>
                                            </div>
                                            <div
                                                class="mt-0.5 font-mono text-[11px] text-zinc-500"
                                            >
                                                {{ item.slug }}
                                            </div>
                                        </div>
                                        <div class="font-mono text-zinc-500">
                                            {{ item.environment }}
                                        </div>
                                        <div class="font-mono text-zinc-500">
                                            {{
                                                item.region ??
                                                item.provider_label
                                            }}
                                        </div>
                                        <div class="text-right font-mono">
                                            {{
                                                item.emails_count.toLocaleString()
                                            }}
                                        </div>
                                        <div class="text-right font-mono">
                                            {{
                                                item.domains_count.toLocaleString()
                                            }}
                                        </div>
                                        <div
                                            class="flex items-center justify-end gap-1"
                                        >
                                            <Link
                                                :href="item.href"
                                                class="inline-flex size-8 items-center justify-center rounded-md text-zinc-500 hover:bg-zinc-100 hover:text-zinc-950 dark:hover:bg-[#16191c] dark:hover:text-zinc-100"
                                                title="Open project"
                                            >
                                                <ArrowUpRight class="size-4" />
                                            </Link>
                                            <button
                                                v-if="
                                                    workspace.can_manage_members
                                                "
                                                type="button"
                                                class="inline-flex size-8 items-center justify-center rounded-md text-zinc-500 hover:bg-zinc-100 hover:text-zinc-950 dark:hover:bg-[#16191c] dark:hover:text-zinc-100"
                                                title="Rename project"
                                                @click="startProjectEdit(item)"
                                            >
                                                <Pencil class="size-4" />
                                            </button>
                                            <button
                                                v-if="
                                                    workspace.can_manage_members
                                                "
                                                type="button"
                                                class="inline-flex size-8 items-center justify-center rounded-md text-zinc-500 hover:bg-amber-50 hover:text-amber-600 disabled:cursor-wait disabled:opacity-60 dark:hover:bg-amber-500/10 dark:hover:text-amber-300"
                                                title="Archive project"
                                                :disabled="
                                                    archivingProjectSlug ===
                                                    item.slug
                                                "
                                                @click="archiveProject(item)"
                                            >
                                                <Archive
                                                    class="size-4"
                                                    :class="{
                                                        'animate-pulse':
                                                            archivingProjectSlug ===
                                                            item.slug,
                                                    }"
                                                />
                                            </button>
                                            <button
                                                v-if="
                                                    workspace.can_manage_members
                                                "
                                                type="button"
                                                class="inline-flex size-8 items-center justify-center rounded-md text-zinc-500 hover:bg-red-50 hover:text-red-600 disabled:cursor-wait disabled:opacity-60 dark:hover:bg-red-500/10 dark:hover:text-red-300"
                                                :title="
                                                    item.can_delete
                                                        ? 'Delete empty project'
                                                        : item.delete_block_reason ||
                                                          'Archive instead'
                                                "
                                                :disabled="
                                                    deletingProjectSlug ===
                                                    item.slug
                                                "
                                                @click="deleteProject(item)"
                                            >
                                                <Trash2
                                                    class="size-4"
                                                    :class="{
                                                        'animate-pulse':
                                                            deletingProjectSlug ===
                                                            item.slug,
                                                    }"
                                                />
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div
                                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
                            >
                                Archive keeps email history, domains, API keys,
                                and webhooks for audit. Delete is only for empty
                                projects.
                            </div>

                            <div
                                class="rounded-lg border border-zinc-200 bg-white dark:border-[#1d2125] dark:bg-[#101111]"
                            >
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between px-3 py-2.5 text-left text-sm font-semibold"
                                    @click="
                                        showingArchivedProjects =
                                            !showingArchivedProjects
                                    "
                                >
                                    <span>
                                        Archived projects
                                        <span
                                            class="ml-1 font-mono text-xs text-zinc-500"
                                            >{{ archivedProjects.length }}</span
                                        >
                                    </span>
                                    <span
                                        class="font-mono text-xs text-zinc-500"
                                    >
                                        {{
                                            showingArchivedProjects
                                                ? 'hide'
                                                : 'show'
                                        }}
                                    </span>
                                </button>
                                <div
                                    v-if="showingArchivedProjects"
                                    class="border-t border-zinc-200 dark:border-[#1d2125]"
                                >
                                    <div
                                        v-for="item in archivedProjects"
                                        :key="item.slug"
                                        class="grid min-h-12 grid-cols-[minmax(280px,1fr)_120px_120px_120px_130px] items-center gap-2 border-b border-zinc-200 px-3 py-2 text-sm last:border-b-0 dark:border-[#16191c]"
                                    >
                                        <div class="min-w-0">
                                            <div class="truncate font-semibold">
                                                {{ item.name }}
                                            </div>
                                            <div
                                                class="mt-0.5 font-mono text-[11px] text-zinc-500"
                                            >
                                                {{ item.slug }} · archived
                                                {{
                                                    item.archived_at ||
                                                    'recently'
                                                }}
                                            </div>
                                        </div>
                                        <div class="font-mono text-zinc-500">
                                            {{ item.environment }}
                                        </div>
                                        <div class="font-mono text-zinc-500">
                                            {{ item.region ?? '—' }}
                                        </div>
                                        <div class="text-right font-mono">
                                            {{
                                                item.emails_count.toLocaleString()
                                            }}
                                        </div>
                                        <div class="text-right">
                                            <button
                                                v-if="
                                                    workspace.can_manage_members
                                                "
                                                type="button"
                                                class="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:text-zinc-950 disabled:cursor-wait disabled:opacity-60 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100"
                                                :disabled="
                                                    restoringProjectSlug ===
                                                    item.slug
                                                "
                                                @click="restoreProject(item)"
                                            >
                                                {{
                                                    restoringProjectSlug ===
                                                    item.slug
                                                        ? 'Restoring...'
                                                        : 'Restore'
                                                }}
                                            </button>
                                        </div>
                                    </div>
                                    <div
                                        v-if="archivedProjects.length === 0"
                                        class="px-4 py-8 text-center text-sm text-zinc-500"
                                    >
                                        No archived projects.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid max-w-6xl gap-3 pt-2">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h2 class="text-lg font-semibold">
                                        Workspace members
                                    </h2>
                                    <p
                                        class="mt-1 max-w-2xl text-sm text-zinc-500"
                                    >
                                        Members can access every project in
                                        {{ workspace.name }}.
                                    </p>
                                </div>
                            </div>

                            <form
                                v-if="workspace.can_manage_members"
                                class="grid grid-cols-[minmax(220px,1fr)_220px_auto] gap-2 rounded-lg border border-zinc-200 bg-white p-3 dark:border-[#1d2125] dark:bg-[#101111]"
                                @submit.prevent="addWorkspaceMember"
                            >
                                <div class="min-w-0">
                                    <input
                                        v-model="workspaceMemberForm.email"
                                        type="email"
                                        required
                                        class="w-full min-w-0 rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-[#101111]"
                                        :class="{
                                            'border-red-400 dark:border-red-500':
                                                workspaceMemberForm.errors
                                                    .email,
                                        }"
                                        placeholder="teammate@example.com"
                                    />
                                    <p
                                        v-if="workspaceMemberForm.errors.email"
                                        class="mt-1 text-xs text-red-500"
                                    >
                                        {{ workspaceMemberForm.errors.email }}
                                    </p>
                                </div>
                                <div>
                                    <select
                                        v-model="workspaceMemberForm.role"
                                        class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-[#101111]"
                                        :class="{
                                            'border-red-400 dark:border-red-500':
                                                workspaceMemberForm.errors.role,
                                        }"
                                    >
                                        <option
                                            v-for="role in workspaceRoleOptions"
                                            :key="role.value"
                                            :value="role.value"
                                        >
                                            {{ role.label }}
                                        </option>
                                    </select>
                                    <p
                                        v-if="workspaceMemberForm.errors.role"
                                        class="mt-1 text-xs text-red-500"
                                    >
                                        {{ workspaceMemberForm.errors.role }}
                                    </p>
                                </div>
                                <button
                                    type="submit"
                                    class="h-9 rounded-lg bg-teal-300 px-4 text-sm font-bold text-zinc-950 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="workspaceMemberForm.processing"
                                >
                                    {{
                                        workspaceMemberForm.processing
                                            ? 'Adding...'
                                            : 'Add member'
                                    }}
                                </button>
                            </form>

                            <div
                                class="grid overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-[#1d2125] dark:bg-[#101111]"
                            >
                                <div
                                    class="grid grid-cols-[minmax(220px,1fr)_minmax(220px,1fr)_220px_52px] border-b border-zinc-200 bg-zinc-50 px-3 py-2 font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:border-[#1d2125] dark:bg-[#111315]"
                                >
                                    <div>Name</div>
                                    <div>Email</div>
                                    <div>Role</div>
                                    <div></div>
                                </div>
                                <div
                                    v-for="member in workspaceMembers"
                                    :key="member.id"
                                    class="grid min-h-12 grid-cols-[minmax(220px,1fr)_minmax(220px,1fr)_220px_52px] items-center border-b border-zinc-200 px-3 text-sm last:border-b-0 dark:border-[#16191c]"
                                >
                                    <div class="min-w-0">
                                        <div
                                            class="truncate font-semibold text-zinc-950 dark:text-zinc-100"
                                        >
                                            {{ member.name }}
                                        </div>
                                        <div
                                            v-if="member.is_owner"
                                            class="mt-0.5 font-mono text-[11px] text-zinc-500"
                                        >
                                            workspace owner
                                        </div>
                                    </div>
                                    <div
                                        class="truncate font-mono text-[12px] text-zinc-500"
                                    >
                                        {{ member.email }}
                                    </div>
                                    <div>
                                        <select
                                            v-if="
                                                workspace.can_manage_members &&
                                                !member.is_owner
                                            "
                                            :value="member.role"
                                            class="w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-sm dark:border-zinc-800 dark:bg-[#101111]"
                                            @change="
                                                handleWorkspaceMemberRoleChange(
                                                    member.id,
                                                    $event,
                                                )
                                            "
                                        >
                                            <option
                                                v-for="role in workspaceRoleOptions"
                                                :key="`${member.id}-${role.value}`"
                                                :value="role.value"
                                            >
                                                {{ role.label }}
                                            </option>
                                        </select>
                                        <span
                                            v-else
                                            class="inline-flex rounded bg-zinc-100 px-2 py-1 font-mono text-[11px] text-zinc-600 capitalize dark:bg-[#1a1e22] dark:text-zinc-300"
                                        >
                                            {{ roleLabel(member.role) }}
                                        </span>
                                    </div>
                                    <div class="text-right">
                                        <button
                                            v-if="
                                                workspace.can_manage_members &&
                                                !member.is_owner
                                            "
                                            type="button"
                                            class="inline-flex size-8 items-center justify-center rounded-md text-zinc-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10 dark:hover:text-red-300"
                                            title="Remove member"
                                            @click="
                                                removeWorkspaceMember(member.id)
                                            "
                                        >
                                            <Trash2 class="size-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="section === 'setup'" class="grid gap-4">
                        <div
                            v-if="setup.next_step"
                            class="max-w-5xl rounded-lg border border-teal-200 bg-teal-50 p-4 font-sans text-teal-950 dark:border-teal-400/20 dark:bg-teal-400/10 dark:text-teal-100"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-3"
                            >
                                <div>
                                    <div
                                        class="font-mono text-[10px] tracking-widest text-teal-700 uppercase dark:text-teal-300"
                                    >
                                        Next action
                                    </div>
                                    <h2 class="mt-1 text-lg font-semibold">
                                        {{ setup.next_step.label }}
                                    </h2>
                                    <p
                                        class="mt-1 max-w-3xl text-sm opacity-80"
                                    >
                                        {{ setup.next_step.description }}
                                    </p>
                                </div>
                                <Link
                                    :href="setup.next_step.href"
                                    class="rounded-lg bg-teal-300 px-3 py-2 text-sm font-bold text-zinc-950 transition hover:brightness-105 active:translate-y-px"
                                >
                                    Continue
                                </Link>
                            </div>
                        </div>

                        <div
                            class="max-w-5xl rounded-lg border border-zinc-200 p-4 font-sans dark:border-zinc-800"
                        >
                            <h2 class="text-lg font-semibold">
                                Sending source
                            </h2>
                            <p class="mt-2 max-w-3xl text-sm text-zinc-500">
                                Verify the source, domain, event webhooks, API
                                key, and first send before routing production
                                traffic through Larasend.
                            </p>
                            <div
                                class="mt-5 grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-3"
                            >
                                <div
                                    v-for="card in setupHealthCards"
                                    :key="card.label"
                                    class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-800"
                                >
                                    <div
                                        class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase"
                                    >
                                        {{ card.label }}
                                    </div>
                                    <div
                                        class="mt-2 flex items-center gap-2 text-sm font-semibold"
                                    >
                                        <span
                                            class="size-2 rounded-full"
                                            :class="healthToneClass(card.tone)"
                                        />
                                        <span class="truncate">{{
                                            card.value
                                        }}</span>
                                    </div>
                                    <div
                                        class="mt-1 truncate text-xs text-zinc-500"
                                    >
                                        {{ card.meta }}
                                    </div>
                                </div>
                            </div>
                            <div class="mt-5 grid gap-3">
                                <div
                                    v-for="step in setup.steps"
                                    :key="step.key"
                                    class="grid grid-cols-[32px_1fr_140px] items-center gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
                                >
                                    <div
                                        class="flex size-7 items-center justify-center rounded-full text-sm font-bold"
                                        :class="
                                            step.complete
                                                ? 'bg-teal-400 text-zinc-950'
                                                : 'bg-zinc-200 text-zinc-500 dark:bg-zinc-900'
                                        "
                                    >
                                        {{ step.complete ? '✓' : '·' }}
                                    </div>
                                    <div>
                                        <div class="font-semibold">
                                            {{ step.label }}
                                        </div>
                                        <div class="mt-1 text-sm text-zinc-500">
                                            {{ step.description }}
                                        </div>
                                    </div>
                                    <Link
                                        :href="step.href"
                                        class="rounded-lg border border-zinc-200 px-3 py-2 text-center text-sm font-semibold text-zinc-600 hover:text-zinc-950 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100"
                                    >
                                        {{
                                            step.complete
                                                ? 'Review'
                                                : 'Configure'
                                        }}
                                    </Link>
                                </div>
                            </div>
                        </div>

                        <div
                            class="grid max-w-5xl gap-3 rounded-lg border border-zinc-200 p-4 font-sans text-sm dark:border-zinc-800"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h2 class="text-lg font-semibold">
                                        {{
                                            isCloudflare
                                                ? 'Cloudflare wiring'
                                                : 'AWS wiring'
                                        }}
                                    </h2>
                                    <p class="mt-1 text-sm text-zinc-500">
                                        {{ quotaStatus }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-semibold text-zinc-600 transition hover:text-zinc-950 disabled:cursor-wait disabled:opacity-60 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100"
                                    :disabled="syncingQuota"
                                    title="Sync provider sending quota"
                                    @click="syncQuota()"
                                >
                                    <RefreshCw
                                        class="size-4"
                                        :class="{
                                            'animate-spin': syncingQuota,
                                        }"
                                    />
                                    {{
                                        syncingQuota
                                            ? 'Syncing...'
                                            : 'Sync quota'
                                    }}
                                </button>
                            </div>
                            <div class="grid gap-2 text-zinc-500">
                                <div
                                    class="grid gap-3 rounded-lg border border-zinc-200 p-3 text-zinc-700 dark:border-zinc-800 dark:text-zinc-300"
                                >
                                    <div class="grid gap-3 md:grid-cols-5">
                                        <div>
                                            <div
                                                class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                                            >
                                                Source
                                            </div>
                                            <div class="mt-1 font-semibold">
                                                {{
                                                    source?.name || 'Production'
                                                }}
                                            </div>
                                        </div>
                                        <div>
                                            <div
                                                class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                                            >
                                                Credentials
                                            </div>
                                            <div class="mt-1 font-semibold">
                                                {{ credentialMode }}
                                            </div>
                                        </div>
                                        <div>
                                            <div
                                                class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                                            >
                                                Default sender
                                            </div>
                                            <div
                                                class="mt-1 truncate font-semibold"
                                            >
                                                {{
                                                    source?.default_from_email ||
                                                    'Missing'
                                                }}
                                            </div>
                                        </div>
                                        <div>
                                            <div
                                                class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                                            >
                                                {{
                                                    isCloudflare
                                                        ? 'Events'
                                                        : 'SES events'
                                                }}
                                            </div>
                                            <div class="mt-1 font-semibold">
                                                {{
                                                    isCloudflare
                                                        ? 'Suppression sync'
                                                        : setup.webhook_url
                                                          ? 'Webhook ready'
                                                          : 'Not configured'
                                                }}
                                            </div>
                                        </div>
                                        <div>
                                            <div
                                                class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                                            >
                                                Quota
                                            </div>
                                            <div class="mt-1 font-semibold">
                                                {{ quotaDetail }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <template v-if="isCloudflare">
                                    <div>
                                        1. Deploy the app with `APP_URL`,
                                        database, queue worker, scheduler, and
                                        HTTPS configured.
                                    </div>
                                    <div>
                                        2. Enable the Workers Paid plan on the
                                        Cloudflare account and make sure the
                                        sending domain uses Cloudflare DNS.
                                    </div>
                                    <div>
                                        3.
                                        <a
                                            :href="cloudflareTokenUrl"
                                            target="_blank"
                                            rel="noopener"
                                            class="font-semibold text-zinc-950 underline dark:text-zinc-100"
                                            >Create an API token</a
                                        >
                                        with Email Sending Edit, Zone Read, and
                                        DNS Edit, then save it with your account
                                        ID in the source settings. Add the
                                        sending domain in Larasend and it is
                                        onboarded in Cloudflare automatically.
                                    </div>
                                    <div>
                                        4. Delivery events are limited to SMTP
                                        accept/reject at send time; Cloudflare
                                        suppressions sync into Larasend hourly.
                                    </div>
                                    <div>
                                        5. Create an API key and set
                                        `MAIL_MAILER=larasend`,
                                        `LARASEND_API_KEY`, and
                                        `LARASEND_ENDPOINT` in the Laravel app
                                        that sends mail.
                                    </div>
                                </template>
                                <template v-else>
                                    <div>
                                        1. Deploy the app with `APP_URL`,
                                        database, queue worker, and HTTPS
                                        configured.
                                    </div>
                                    <div>
                                        2. Create an IAM user or role with SES
                                        permissions for `ses:SendRawEmail`,
                                        `ses:CreateEmailIdentity`,
                                        `ses:GetEmailIdentity`,
                                        `ses:GetSendQuota`, and
                                        `ses:GetAccount`.
                                    </div>
                                    <div>
                                        3. Save the SES source in Larasend, add
                                        your sending domain, then publish the
                                        DKIM records in Route 53 or your DNS
                                        provider.
                                    </div>
                                    <div>
                                        4. Configure SES event publishing
                                        through SNS to this webhook URL:
                                    </div>
                                    <div
                                        class="overflow-auto rounded-md bg-zinc-100 p-3 font-mono text-xs text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200"
                                    >
                                        {{
                                            setup.webhook_url ||
                                            'Save a source first'
                                        }}
                                    </div>
                                    <div>
                                        5. Create an API key and set
                                        `MAIL_MAILER=larasend`,
                                        `LARASEND_API_KEY`, and
                                        `LARASEND_ENDPOINT` in the Laravel app
                                        that sends mail.
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="section === 'send'" class="grid gap-4">
                        <div
                            v-if="!canSendEmail"
                            class="grid max-w-3xl gap-4 rounded-lg border border-amber-200 bg-amber-50 p-4 font-sans text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
                        >
                            <div class="flex items-start gap-3">
                                <AlertTriangle class="mt-0.5 size-5" />
                                <div>
                                    <h2 class="text-base font-semibold">
                                        {{ providerLabel }} setup required
                                    </h2>
                                    <p class="mt-1 text-sm">
                                        Add {{ providerLabel }} credentials and
                                        verify a sending domain before sending
                                        email. Larasend will not record local
                                        send events as successful deliveries.
                                    </p>
                                </div>
                            </div>
                            <Link
                                :href="projectAction('/identities')"
                                class="w-fit rounded-lg bg-amber-400 px-4 py-2 text-sm font-bold text-amber-950"
                            >
                                Open identities
                            </Link>
                        </div>
                        <form
                            v-else
                            class="grid max-w-5xl gap-4 rounded-lg border border-zinc-200 p-4 font-sans dark:border-zinc-800"
                            @submit.prevent="sendEmail"
                        >
                            <div>
                                <h2 class="text-lg font-semibold">
                                    Send email
                                </h2>
                                <p class="mt-1 text-sm text-zinc-500">
                                    Uses the configured project source and sends
                                    through {{ providerLabel }}.
                                </p>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <label class="grid gap-2 text-sm">
                                    <span class="text-zinc-500">From</span>
                                    <input
                                        v-model="sendForm.from"
                                        class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                        required
                                    />
                                </label>
                                <label class="grid gap-2 text-sm">
                                    <span class="text-zinc-500">To</span>
                                    <input
                                        v-model="sendForm.to"
                                        class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                        placeholder="maya@example.com, team@example.com"
                                        required
                                    />
                                </label>
                            </div>
                            <div class="grid grid-cols-3 gap-4">
                                <label class="grid gap-2 text-sm">
                                    <span class="text-zinc-500">CC</span>
                                    <input
                                        v-model="sendForm.cc"
                                        class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                    />
                                </label>
                                <label class="grid gap-2 text-sm">
                                    <span class="text-zinc-500">BCC</span>
                                    <input
                                        v-model="sendForm.bcc"
                                        class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                    />
                                </label>
                                <label class="grid gap-2 text-sm">
                                    <span class="text-zinc-500">Template</span>
                                    <select
                                        v-model="sendForm.template_id"
                                        class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                    >
                                        <option value="">Custom HTML</option>
                                        <option
                                            v-for="template in templates"
                                            :key="template.slug"
                                            :value="template.slug"
                                        >
                                            {{ template.slug }}
                                        </option>
                                    </select>
                                </label>
                            </div>
                            <label class="grid gap-2 text-sm">
                                <span class="text-zinc-500">Subject</span>
                                <input
                                    v-model="sendForm.subject"
                                    class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                    required
                                />
                            </label>
                            <label class="grid gap-2 text-sm">
                                <span class="text-zinc-500">HTML</span>
                                <textarea
                                    v-model="sendForm.html"
                                    class="min-h-40 rounded-md border border-zinc-200 bg-white px-3 py-2 font-mono text-sm dark:border-zinc-800 dark:bg-[#101111]"
                                />
                            </label>
                            <label class="grid gap-2 text-sm">
                                <span class="text-zinc-500">Plain text</span>
                                <textarea
                                    v-model="sendForm.text"
                                    class="min-h-24 rounded-md border border-zinc-200 bg-white px-3 py-2 font-mono text-sm dark:border-zinc-800 dark:bg-[#101111]"
                                />
                            </label>
                            <button
                                class="w-fit rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-zinc-950"
                            >
                                Send email
                            </button>
                        </form>
                    </div>

                    <div
                        v-else-if="section === 'identities'"
                        class="-m-5 grid min-h-full grid-cols-[340px_minmax(0,1fr)] border-t border-zinc-200 dark:border-zinc-800"
                    >
                        <aside
                            class="border-r border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-[#090a0a]"
                        >
                            <div
                                class="flex items-center justify-between border-b border-zinc-200 px-3 py-2.5 dark:border-zinc-800"
                            >
                                <div class="font-sans text-sm font-semibold">
                                    Identities
                                    <span class="ml-1 text-zinc-500">{{
                                        domains.length
                                    }}</span>
                                </div>
                                <button
                                    v-if="workspace.can_manage_domains"
                                    class="rounded-lg bg-teal-400 px-3 py-2 font-sans text-sm font-bold text-zinc-950"
                                    @click="showNewIdentity = !showNewIdentity"
                                >
                                    + New identity
                                </button>
                            </div>
                            <form
                                v-if="
                                    showNewIdentity &&
                                    workspace.can_manage_domains
                                "
                                class="grid gap-3 border-b border-zinc-200 p-4 font-sans dark:border-zinc-800"
                                @submit.prevent="addDomain"
                            >
                                <label class="grid gap-2 text-sm">
                                    <span class="text-zinc-500"
                                        >Email or domain</span
                                    >
                                    <input
                                        v-model="domainForm.domain"
                                        class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                        placeholder="founder@example.com"
                                        required
                                    />
                                    <span
                                        v-if="domainForm.errors.domain"
                                        class="text-xs font-medium text-red-600 dark:text-red-400"
                                    >
                                        {{ domainForm.errors.domain }}
                                    </span>
                                </label>
                                <button
                                    class="w-fit rounded-lg bg-teal-400 px-3 py-2 text-sm font-bold text-zinc-950"
                                    :disabled="domainForm.processing"
                                >
                                    {{
                                        domainForm.processing
                                            ? 'Creating...'
                                            : 'Create identity'
                                    }}
                                </button>
                            </form>
                            <button
                                v-for="domain in domains"
                                :key="domain.domain"
                                class="w-full border-b border-zinc-200 px-3 py-2.5 text-left font-sans hover:bg-white dark:border-zinc-900 dark:hover:bg-zinc-950"
                                :class="{
                                    'border-l-2 border-l-teal-400 bg-white dark:bg-zinc-950':
                                        selectedIdentity?.domain ===
                                        domain.domain,
                                }"
                                @click="selectedIdentityDomain = domain.domain"
                            >
                                <div class="flex items-center gap-3">
                                    <span
                                        class="truncate text-base font-semibold"
                                        >{{ domain.domain }}</span
                                    >
                                    <span
                                        class="rounded-md px-2 py-0.5 font-mono text-xs"
                                        :class="
                                            domain.status === 'verified' ||
                                            domain.status === 'local'
                                                ? 'bg-emerald-500/12 text-emerald-400'
                                                : 'bg-zinc-500/12 text-zinc-400'
                                        "
                                        >{{
                                            domain.status === 'local'
                                                ? 'verified'
                                                : domain.status
                                        }}</span
                                    >
                                </div>
                                <div class="mt-2 text-sm text-zinc-500">
                                    {{
                                        project.region ?? project.provider_label
                                    }}
                                    · {{ quota.sent.toLocaleString() }} sent ·
                                    30d
                                </div>
                                <div class="mt-3 flex gap-2">
                                    <span
                                        v-for="record in (
                                            domain.dns_records ?? []
                                        ).slice(0, 3)"
                                        :key="`${domain.domain}-${record.name}`"
                                        class="rounded-md px-2 py-1 font-mono text-xs"
                                        :class="
                                            record.status === 'ok'
                                                ? 'bg-emerald-500/12 text-emerald-400'
                                                : 'bg-zinc-500/12 text-zinc-400'
                                        "
                                    >
                                        {{ record.type }}
                                        {{
                                            record.status === 'ok'
                                                ? 'pass'
                                                : 'pending'
                                        }}
                                    </span>
                                </div>
                            </button>
                        </aside>

                        <section
                            v-if="selectedIdentity"
                            class="min-w-0 overflow-auto p-5 font-sans"
                        >
                            <div class="flex items-start gap-4">
                                <div>
                                    <div class="flex items-center gap-3">
                                        <h2
                                            class="text-xl font-semibold tracking-tight"
                                        >
                                            {{ selectedIdentity.domain }}
                                        </h2>
                                        <span
                                            class="rounded-md bg-emerald-500/12 px-2.5 py-1 font-mono text-xs text-emerald-400"
                                            >{{
                                                selectedIdentity.status ===
                                                'local'
                                                    ? 'verified'
                                                    : selectedIdentity.status
                                            }}</span
                                        >
                                    </div>
                                    <div
                                        class="mt-2 font-mono text-sm text-zinc-500"
                                    >
                                        {{
                                            project.region
                                                ? `${project.region} · ${project.provider_label}`
                                                : project.provider_label
                                        }}
                                        · verified
                                        {{
                                            selectedIdentity.verified_at ||
                                            'pending DNS'
                                        }}
                                    </div>
                                </div>
                                <div
                                    class="ml-auto flex flex-wrap justify-end gap-2"
                                >
                                    <button
                                        v-if="workspace.can_manage_domains"
                                        class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-semibold text-zinc-600 transition hover:text-zinc-950 active:scale-[0.98] disabled:cursor-wait disabled:opacity-60 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100"
                                        :disabled="
                                            checkingDomainId ===
                                            selectedIdentity.id
                                        "
                                        title="Resolve the DNS records and update their status"
                                        @click="checkDomain"
                                    >
                                        <RefreshCw
                                            class="size-4"
                                            :class="{
                                                'animate-spin':
                                                    checkingDomainId ===
                                                    selectedIdentity.id,
                                            }"
                                        />
                                        {{
                                            checkingDomainId ===
                                            selectedIdentity.id
                                                ? 'Checking...'
                                                : 'Re-check DNS'
                                        }}
                                    </button>
                                    <button
                                        v-if="workspace.can_manage_domains"
                                        class="rounded-lg border border-zinc-200 px-3 py-2 text-sm font-semibold text-zinc-600 transition hover:text-zinc-950 active:scale-[0.98] dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100"
                                        @click="
                                            showSourceSettings =
                                                !showSourceSettings
                                        "
                                    >
                                        {{
                                            showSourceSettings
                                                ? 'Hide source settings'
                                                : 'Source settings'
                                        }}
                                    </button>
                                    <button
                                        v-if="workspace.can_manage_domains"
                                        class="inline-flex items-center gap-2 rounded-lg border border-red-200 px-3 py-2 text-sm font-semibold text-red-500 transition hover:border-red-300 hover:bg-red-50 active:scale-[0.98] disabled:cursor-wait disabled:opacity-60 dark:border-red-500/20 dark:hover:bg-red-500/10"
                                        :disabled="
                                            deletingDomainId ===
                                            selectedIdentity.id
                                        "
                                        title="Delete this sending identity from Larasend"
                                        @click="deleteDomain"
                                    >
                                        <Trash2
                                            class="size-4"
                                            :class="{
                                                'animate-pulse':
                                                    deletingDomainId ===
                                                    selectedIdentity.id,
                                            }"
                                        />
                                        {{
                                            deletingDomainId ===
                                            selectedIdentity.id
                                                ? 'Deleting...'
                                                : 'Delete'
                                        }}
                                    </button>
                                </div>
                            </div>

                            <div
                                class="mt-4 grid grid-cols-4 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800"
                            >
                                <div
                                    v-for="stat in identityStats"
                                    :key="stat.label"
                                    class="border-r border-zinc-200 p-4 last:border-r-0 dark:border-zinc-800"
                                >
                                    <div
                                        class="text-xs tracking-widest text-zinc-500 uppercase"
                                    >
                                        {{ stat.label }}
                                    </div>
                                    <div class="mt-2 text-xl font-semibold">
                                        {{ stat.value }}
                                    </div>
                                </div>
                            </div>

                            <div class="mt-5">
                                <h3 class="font-semibold">Authentication</h3>
                                <p class="mt-1 text-sm text-zinc-500">
                                    DKIM, SPF, and DMARC alignment
                                </p>
                                <div class="mt-4 grid grid-cols-3 gap-3">
                                    <div
                                        v-for="label in [
                                            'DKIM',
                                            'SPF',
                                            'DMARC',
                                        ]"
                                        :key="label"
                                        class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
                                    >
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <span class="font-semibold">{{
                                                label
                                            }}</span>
                                            <span
                                                class="rounded-md bg-emerald-500/12 px-2 py-0.5 font-mono text-xs text-emerald-400"
                                                >{{
                                                    selectedIdentity.status ===
                                                    'pending'
                                                        ? 'pending'
                                                        : 'pass'
                                                }}</span
                                            >
                                        </div>
                                        <p
                                            class="mt-3 text-sm leading-6 text-zinc-500"
                                        >
                                            {{
                                                label === 'DKIM'
                                                    ? `${providerLabel} DKIM selectors are present and aligned.`
                                                    : label === 'SPF'
                                                      ? `TXT record authorizes ${providerLabel} as a sending source.`
                                                      : 'Policy record is present for domain alignment.'
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="isCloudflare"
                                class="mt-5 flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
                            >
                                <Inbox class="size-4 text-teal-500" />
                                <div class="min-w-0 flex-1">
                                    <h3 class="font-semibold">Receive email</h3>
                                    <p class="mt-0.5 text-sm text-zinc-500">
                                        {{
                                            selectedIdentity.inbound_enabled_at
                                                ? 'Inbound is on: mail to any address on this zone lands in the Inbound section and fires inbound.received webhooks.'
                                                : 'Larasend deploys a Cloudflare Worker and routing rule so mail to this zone lands in your Inbound section.'
                                        }}
                                    </p>
                                </div>
                                <span
                                    v-if="selectedIdentity.inbound_enabled_at"
                                    class="rounded-md bg-emerald-500/12 px-2.5 py-1 font-mono text-xs text-emerald-400"
                                    >enabled</span
                                >
                                <div
                                    v-if="
                                        inboundError &&
                                        !selectedIdentity.inbound_enabled_at
                                    "
                                    class="w-full rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-950 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100"
                                >
                                    {{ inboundError }}
                                </div>
                                <button
                                    v-else-if="workspace.can_manage_domains"
                                    type="button"
                                    class="rounded-lg bg-teal-400 px-3 py-2 text-sm font-bold text-zinc-950 disabled:cursor-wait disabled:opacity-60"
                                    :disabled="
                                        enablingInboundDomainId ===
                                        selectedIdentity.id
                                    "
                                    @click="enableInbound(selectedIdentity.id)"
                                >
                                    {{
                                        enablingInboundDomainId ===
                                        selectedIdentity.id
                                            ? 'Enabling...'
                                            : 'Enable receiving'
                                    }}
                                </button>
                            </div>

                            <div class="mt-5">
                                <div class="flex items-center">
                                    <div>
                                        <h3 class="font-semibold">
                                            DNS records
                                        </h3>
                                        <p class="mt-1 text-sm text-zinc-500">
                                            These records must remain in place
                                            for sends to continue.
                                        </p>
                                    </div>
                                    <button
                                        class="ml-auto inline-flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-semibold text-zinc-600 transition hover:text-zinc-950 active:scale-[0.98] dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100"
                                        @click="copyAllDns"
                                    >
                                        <Check
                                            v-if="copiedDnsKey === 'all'"
                                            class="size-4 text-emerald-400"
                                        />
                                        <Copy v-else class="size-4" />
                                        {{
                                            copiedDnsKey === 'all'
                                                ? 'Copied'
                                                : 'Copy all'
                                        }}
                                    </button>
                                </div>
                                <div
                                    class="mt-4 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800"
                                >
                                    <div
                                        class="grid grid-cols-[90px_90px_minmax(220px,1fr)_40px_minmax(260px,1.2fr)_40px] border-b border-zinc-200 bg-zinc-50 px-3 py-2.5 font-mono text-xs tracking-widest text-zinc-500 uppercase dark:border-zinc-800 dark:bg-zinc-950"
                                    >
                                        <div>Status</div>
                                        <div>Type</div>
                                        <div>Host</div>
                                        <div></div>
                                        <div>Value</div>
                                        <div></div>
                                    </div>
                                    <div
                                        v-for="record in selectedIdentityRecords"
                                        :key="`${record.type}-${record.name}`"
                                        class="grid grid-cols-[90px_90px_minmax(220px,1fr)_40px_minmax(260px,1.2fr)_40px] items-center border-b border-zinc-200 px-3 py-3 font-mono text-sm last:border-b-0 dark:border-zinc-900"
                                    >
                                        <div>
                                            <span
                                                class="rounded-md px-2 py-1 text-xs"
                                                :class="
                                                    record.status === 'ok'
                                                        ? 'bg-emerald-500/12 text-emerald-400'
                                                        : 'bg-zinc-500/12 text-zinc-400'
                                                "
                                                >{{
                                                    record.status === 'ok'
                                                        ? 'ok'
                                                        : 'wait'
                                                }}</span
                                            >
                                        </div>
                                        <div>{{ record.type }}</div>
                                        <div class="truncate">
                                            {{ record.name }}
                                        </div>
                                        <button
                                            class="inline-flex size-8 items-center justify-center rounded-md text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-950 active:scale-95 dark:hover:bg-[#111315] dark:hover:text-zinc-100"
                                            :class="{
                                                'bg-emerald-500/10 text-emerald-500':
                                                    copiedDnsKey ===
                                                    `${record.type}-${record.name}-host`,
                                            }"
                                            :title="
                                                copiedDnsKey ===
                                                `${record.type}-${record.name}-host`
                                                    ? 'Copied host'
                                                    : 'Copy host'
                                            "
                                            @click="
                                                copyDnsValue(
                                                    `${record.type}-${record.name}-host`,
                                                    record.name,
                                                )
                                            "
                                        >
                                            <Check
                                                v-if="
                                                    copiedDnsKey ===
                                                    `${record.type}-${record.name}-host`
                                                "
                                                class="size-4"
                                            />
                                            <Copy v-else class="size-4" />
                                        </button>
                                        <div class="truncate text-zinc-500">
                                            {{ record.value }}
                                        </div>
                                        <button
                                            class="inline-flex size-8 items-center justify-center rounded-md text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-950 active:scale-95 dark:hover:bg-[#111315] dark:hover:text-zinc-100"
                                            :class="{
                                                'bg-emerald-500/10 text-emerald-500':
                                                    copiedDnsKey ===
                                                    `${record.type}-${record.name}-value`,
                                            }"
                                            :title="
                                                copiedDnsKey ===
                                                `${record.type}-${record.name}-value`
                                                    ? 'Copied value'
                                                    : 'Copy value'
                                            "
                                            @click="
                                                copyDnsValue(
                                                    `${record.type}-${record.name}-value`,
                                                    record.value,
                                                )
                                            "
                                        >
                                            <Check
                                                v-if="
                                                    copiedDnsKey ===
                                                    `${record.type}-${record.name}-value`
                                                "
                                                class="size-4"
                                            />
                                            <Copy v-else class="size-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <section
                                v-if="showSourceSettings"
                                class="mt-5 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
                            >
                                <div class="flex items-center justify-between">
                                    <h3 class="font-semibold">
                                        Source settings
                                    </h3>
                                    <button
                                        class="text-zinc-500 hover:text-zinc-950 dark:hover:text-zinc-100"
                                        title="Close source settings"
                                        @click="showSourceSettings = false"
                                    >
                                        <X class="size-4" />
                                    </button>
                                </div>
                                <form
                                    class="mt-5 grid gap-4"
                                    @submit.prevent="saveSource"
                                >
                                    <div
                                        v-if="source?.uses_instance_role"
                                        class="rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-950 dark:border-teal-500/30 dark:bg-teal-500/10 dark:text-teal-100"
                                    >
                                        Production is using the EC2 instance
                                        role. AWS key fields below are optional
                                        overrides for self-hosted or non-role
                                        deployments.
                                    </div>
                                    <div
                                        class="grid gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800"
                                    >
                                        <div class="font-semibold">Source</div>
                                        <div class="grid grid-cols-3 gap-4">
                                            <label class="grid gap-2 text-sm">
                                                <span class="text-zinc-500"
                                                    >Name</span
                                                >
                                                <input
                                                    v-model="sourceForm.name"
                                                    class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                                    required
                                                />
                                            </label>
                                            <label class="grid gap-2 text-sm">
                                                <span class="text-zinc-500"
                                                    >Environment</span
                                                >
                                                <input
                                                    v-model="
                                                        sourceForm.environment
                                                    "
                                                    class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                                    required
                                                />
                                            </label>
                                            <label class="grid gap-2 text-sm">
                                                <span class="text-zinc-500"
                                                    >Provider</span
                                                >
                                                <select
                                                    v-model="
                                                        sourceForm.provider
                                                    "
                                                    class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                                >
                                                    <option value="ses">
                                                        Amazon SES
                                                    </option>
                                                    <option value="cloudflare">
                                                        Cloudflare Email Service
                                                    </option>
                                                </select>
                                            </label>
                                            <label
                                                v-if="
                                                    sourceForm.provider ===
                                                    'ses'
                                                "
                                                class="grid gap-2 text-sm"
                                            >
                                                <span class="text-zinc-500"
                                                    >SES region</span
                                                >
                                                <input
                                                    v-model="
                                                        sourceForm.ses_region
                                                    "
                                                    class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                                    required
                                                />
                                            </label>
                                        </div>
                                    </div>
                                    <div
                                        class="grid gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800"
                                    >
                                        <div class="font-semibold">
                                            Default sender
                                        </div>
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <label class="grid gap-2 text-sm">
                                                <span class="text-zinc-500"
                                                    >Default from email</span
                                                >
                                                <input
                                                    v-model="
                                                        sourceForm.default_from_email
                                                    "
                                                    type="email"
                                                    class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                                    required
                                                />
                                            </label>
                                            <label
                                                v-if="
                                                    sourceForm.provider ===
                                                    'ses'
                                                "
                                                class="grid gap-2 text-sm"
                                            >
                                                <span class="text-zinc-500"
                                                    >SES configuration set</span
                                                >
                                                <input
                                                    v-model="
                                                        sourceForm.ses_configuration_set
                                                    "
                                                    class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                                    placeholder="Optional"
                                                />
                                            </label>
                                        </div>
                                    </div>
                                    <div
                                        class="grid gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800"
                                    >
                                        <div class="font-semibold">
                                            Credentials
                                        </div>
                                        <div
                                            v-if="
                                                sourceForm.provider ===
                                                'cloudflare'
                                            "
                                            class="grid gap-4"
                                        >
                                            <div
                                                class="grid gap-4 md:grid-cols-2"
                                            >
                                                <label
                                                    class="grid gap-2 text-sm"
                                                >
                                                    <span class="text-zinc-500"
                                                        >Cloudflare account
                                                        ID</span
                                                    >
                                                    <input
                                                        v-model="
                                                            sourceForm.cloudflare_account_id
                                                        "
                                                        autocomplete="off"
                                                        class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                                        placeholder="From the Cloudflare dashboard URL"
                                                    />
                                                </label>
                                                <label
                                                    class="grid gap-2 text-sm"
                                                >
                                                    <span class="text-zinc-500"
                                                        >Cloudflare API
                                                        token</span
                                                    >
                                                    <input
                                                        v-model="
                                                            sourceForm.cloudflare_api_token
                                                        "
                                                        type="password"
                                                        autocomplete="new-password"
                                                        class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                                        placeholder="Leave blank to keep saved value"
                                                    />
                                                </label>
                                            </div>
                                            <p class="text-sm text-zinc-500">
                                                <a
                                                    :href="cloudflareTokenUrl"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="font-semibold text-zinc-950 underline dark:text-zinc-100"
                                                    >Create a token in
                                                    Cloudflare</a
                                                >
                                                with Email Sending Edit, Zone
                                                Read, and DNS Edit pre-selected
                                                — Larasend uses these to onboard
                                                sending domains automatically.
                                                If a permission does not appear,
                                                search for it in the token form.
                                            </p>
                                        </div>
                                        <div
                                            v-else
                                            class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                                        >
                                            <label class="grid gap-2 text-sm">
                                                <span class="text-zinc-500"
                                                    >AWS access key ID
                                                    <span
                                                        v-if="
                                                            source?.uses_instance_role
                                                        "
                                                        class="font-normal"
                                                        >(optional
                                                        override)</span
                                                    ></span
                                                >
                                                <input
                                                    v-model="
                                                        sourceForm.aws_access_key_id
                                                    "
                                                    autocomplete="off"
                                                    class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                                    placeholder="Leave blank to keep saved value"
                                                />
                                            </label>
                                            <label class="grid gap-2 text-sm">
                                                <span class="text-zinc-500"
                                                    >AWS secret access key
                                                    <span
                                                        v-if="
                                                            source?.uses_instance_role
                                                        "
                                                        class="font-normal"
                                                        >(optional
                                                        override)</span
                                                    ></span
                                                >
                                                <input
                                                    v-model="
                                                        sourceForm.aws_secret_access_key
                                                    "
                                                    type="password"
                                                    autocomplete="new-password"
                                                    class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                                    placeholder="Leave blank to keep saved value"
                                                />
                                            </label>
                                            <label class="grid gap-2 text-sm">
                                                <span class="text-zinc-500"
                                                    >AWS session token</span
                                                >
                                                <input
                                                    v-model="
                                                        sourceForm.aws_session_token
                                                    "
                                                    autocomplete="off"
                                                    class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                                    placeholder="Optional STS token"
                                                />
                                            </label>
                                        </div>
                                    </div>
                                    <div
                                        v-if="sourceForm.provider === 'ses'"
                                        class="grid gap-2 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800"
                                    >
                                        <div class="font-semibold">
                                            SES events
                                        </div>
                                        <div
                                            class="font-mono text-xs text-zinc-500"
                                        >
                                            {{
                                                setup.webhook_url ||
                                                'Save a source first'
                                            }}
                                        </div>
                                    </div>
                                    <div
                                        v-else
                                        class="grid gap-2 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800"
                                    >
                                        <div class="font-semibold">Events</div>
                                        <div class="text-zinc-500">
                                            Cloudflare has no event webhooks.
                                            Delivery state is recorded at send
                                            time and suppressions sync hourly.
                                        </div>
                                    </div>
                                    <div
                                        class="grid gap-2 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800"
                                    >
                                        <div class="font-semibold">Quota</div>
                                        <div class="text-zinc-500">
                                            {{ quotaDetail }} ·
                                            {{ quotaStatus }}
                                        </div>
                                    </div>
                                    <button
                                        class="w-fit rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-zinc-950"
                                    >
                                        Save source
                                    </button>
                                </form>
                            </section>
                        </section>
                    </div>

                    <div
                        v-else-if="section === 'inbound'"
                        class="grid min-h-0 gap-0 overflow-hidden rounded-lg border border-zinc-200 bg-white lg:grid-cols-[360px_minmax(0,1fr)] dark:border-zinc-800 dark:bg-[#090a0a]"
                    >
                        <aside
                            class="min-h-0 overflow-auto border-r border-zinc-200 dark:border-zinc-800"
                        >
                            <div
                                class="border-b border-zinc-200 p-4 font-sans dark:border-zinc-800"
                            >
                                <h2 class="font-semibold">
                                    Inbound
                                    <span class="text-zinc-500">{{
                                        inboundEmails.length
                                    }}</span>
                                </h2>
                                <p class="mt-1 text-sm text-zinc-500">
                                    Email received for your domains. Enable
                                    receiving per domain under Domains.
                                </p>
                            </div>
                            <div
                                v-if="!inboundEmails.length"
                                class="p-4 font-sans text-sm text-zinc-500"
                            >
                                Nothing received yet. Enable receiving on a
                                domain, then send an email to any address on it.
                            </div>
                            <button
                                v-for="email in inboundEmails"
                                :key="email.public_id"
                                type="button"
                                class="grid w-full gap-1 border-b border-zinc-200 p-4 text-left font-sans transition hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-[#141618]"
                                :class="
                                    selectedInbound?.public_id ===
                                    email.public_id
                                        ? 'bg-teal-50 dark:bg-teal-400/10'
                                        : ''
                                "
                                @click="selectedInboundId = email.public_id"
                            >
                                <div
                                    class="flex items-baseline justify-between gap-2"
                                >
                                    <span
                                        class="truncate text-sm font-semibold"
                                    >
                                        {{
                                            email.from_name || email.from_email
                                        }}
                                    </span>
                                    <span
                                        class="shrink-0 font-mono text-[11px] text-zinc-500"
                                    >
                                        {{ relativeTime(email.received_at) }}
                                    </span>
                                </div>
                                <div class="truncate text-sm">
                                    {{ email.subject || '(no subject)' }}
                                </div>
                                <div
                                    class="truncate font-mono text-[11px] text-zinc-500"
                                >
                                    to {{ email.to_email }}
                                </div>
                            </button>
                        </aside>
                        <section
                            v-if="selectedInbound"
                            class="grid min-h-0 content-start gap-4 overflow-auto p-5 font-sans"
                        >
                            <div>
                                <h2 class="text-lg font-semibold">
                                    {{
                                        selectedInbound.subject ||
                                        '(no subject)'
                                    }}
                                </h2>
                                <div
                                    class="mt-1 grid gap-0.5 font-mono text-xs text-zinc-500"
                                >
                                    <span>
                                        from
                                        {{
                                            selectedInbound.from_name
                                                ? `${selectedInbound.from_name} <${selectedInbound.from_email}>`
                                                : selectedInbound.from_email
                                        }}
                                    </span>
                                    <span
                                        >to {{ selectedInbound.to_email }}</span
                                    >
                                    <span
                                        v-if="
                                            selectedInbound.attachments?.length
                                        "
                                    >
                                        {{ selectedInbound.attachments.length }}
                                        attachment{{
                                            selectedInbound.attachments
                                                .length === 1
                                                ? ''
                                                : 's'
                                        }}
                                        ·
                                        {{
                                            selectedInbound.attachments
                                                .map((a) => a.filename)
                                                .filter(Boolean)
                                                .join(', ')
                                        }}
                                    </span>
                                </div>
                            </div>
                            <iframe
                                v-if="selectedInbound.html"
                                :srcdoc="selectedInbound.html"
                                sandbox=""
                                class="h-[60vh] w-full rounded-lg border border-zinc-200 bg-white dark:border-zinc-800"
                                title="Inbound email preview"
                            />
                            <pre
                                v-else
                                class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 font-mono text-xs whitespace-pre-wrap text-zinc-800 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-200"
                                >{{
                                    selectedInbound.text || '(empty body)'
                                }}</pre
                            >
                        </section>
                        <section
                            v-else
                            class="grid place-items-center p-10 font-sans text-sm text-zinc-500"
                        >
                            Select an email to preview it.
                        </section>
                    </div>

                    <div v-else-if="section === 'templates'" class="grid gap-4">
                        <section
                            class="grid grid-cols-4 overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-[#1d2125] dark:bg-[#111315]"
                        >
                            <div
                                v-for="stat in templateStats"
                                :key="stat.label"
                                class="border-r border-zinc-200 px-3 py-2.5 last:border-r-0 dark:border-[#1d2125]"
                            >
                                <div
                                    class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase"
                                >
                                    {{ stat.label }}
                                </div>
                                <div class="mt-3 text-xl font-semibold">
                                    {{ stat.value }}
                                </div>
                                <div
                                    class="mt-1 font-mono text-[12px] text-zinc-500"
                                >
                                    {{ stat.meta }}
                                </div>
                            </div>
                        </section>

                        <form
                            class="grid gap-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-[#1d2125] dark:bg-[#111315]"
                            @submit.prevent="saveTemplate"
                        >
                            <div class="flex items-center gap-3">
                                <div>
                                    <h2 class="text-base font-semibold">
                                        Create or update template
                                    </h2>
                                    <p class="mt-0.5 text-[12px] text-zinc-500">
                                        Versioned HTML/text templates for API
                                        and Laravel mail sends.
                                    </p>
                                </div>
                                <button
                                    class="ml-auto rounded-lg bg-teal-400 px-3 py-2 text-[12px] font-bold text-zinc-950"
                                >
                                    Save template
                                </button>
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <label class="grid gap-1.5 text-[12px]">
                                    <span class="text-zinc-500">Slug</span>
                                    <input
                                        v-model="templateForm.slug"
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-3 dark:border-[#1d2125] dark:bg-[#0b0c0d]"
                                        placeholder="tx.receipt.v1"
                                        required
                                    />
                                </label>
                                <label class="grid gap-1.5 text-[12px]">
                                    <span class="text-zinc-500">Name</span>
                                    <input
                                        v-model="templateForm.name"
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-3 dark:border-[#1d2125] dark:bg-[#0b0c0d]"
                                        required
                                    />
                                </label>
                                <label class="grid gap-1.5 text-[12px]">
                                    <span class="text-zinc-500">Variables</span>
                                    <input
                                        v-model="templateForm.variables"
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-3 dark:border-[#1d2125] dark:bg-[#0b0c0d]"
                                        placeholder="name, invoice"
                                    />
                                </label>
                            </div>
                            <label class="grid gap-1.5 text-[12px]">
                                <span class="text-zinc-500">Subject</span>
                                <input
                                    v-model="templateForm.subject"
                                    class="h-9 rounded-md border border-zinc-200 bg-white px-3 dark:border-[#1d2125] dark:bg-[#0b0c0d]"
                                    required
                                />
                            </label>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="grid gap-1.5 text-[12px]">
                                    <span class="text-zinc-500">HTML</span>
                                    <textarea
                                        v-model="templateForm.html"
                                        class="min-h-28 rounded-md border border-zinc-200 bg-white px-3 py-2 font-mono text-[12px] dark:border-[#1d2125] dark:bg-[#0b0c0d]"
                                    />
                                </label>
                                <label class="grid gap-1.5 text-[12px]">
                                    <span class="text-zinc-500">Text</span>
                                    <textarea
                                        v-model="templateForm.text"
                                        class="min-h-28 rounded-md border border-zinc-200 bg-white px-3 py-2 font-mono text-[12px] dark:border-[#1d2125] dark:bg-[#0b0c0d]"
                                    />
                                </label>
                            </div>
                        </form>
                        <div
                            class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-[#1d2125] dark:bg-[#0b0c0d]"
                        >
                            <div
                                class="grid grid-cols-[260px_minmax(360px,1fr)_180px_90px] border-b border-zinc-200 bg-zinc-50 px-3 py-2 font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:border-[#1d2125] dark:bg-[#111315]"
                            >
                                <div>Template</div>
                                <div>Subject</div>
                                <div>Updated</div>
                                <div></div>
                            </div>
                            <button
                                v-for="template in templates"
                                :key="template.slug"
                                class="grid h-12 w-full grid-cols-[260px_minmax(360px,1fr)_180px_90px] items-center border-b border-zinc-200 px-3 text-left text-[13px] last:border-b-0 hover:bg-zinc-50 dark:border-[#16191c] dark:hover:bg-[#111315]"
                                @click="
                                    templateForm.slug = template.slug;
                                    templateForm.name = template.name;
                                    templateForm.subject = template.subject;
                                    templateForm.variables = Array.isArray(
                                        template.variables,
                                    )
                                        ? template.variables.join(', ')
                                        : '';
                                    templateForm.html = template.html || '';
                                    templateForm.text = template.text || '';
                                "
                            >
                                <div class="min-w-0">
                                    <div class="truncate font-semibold">
                                        {{ template.name }}
                                    </div>
                                    <div
                                        class="truncate font-mono text-[11px] text-zinc-500"
                                    >
                                        {{ template.slug }}
                                    </div>
                                </div>
                                <div
                                    class="truncate text-zinc-600 dark:text-zinc-300"
                                >
                                    {{ template.subject }}
                                </div>
                                <div
                                    class="font-mono text-[12px] text-zinc-500"
                                >
                                    {{ template.updated_at }}
                                </div>
                                <div class="text-right text-zinc-500">Edit</div>
                            </button>
                        </div>
                    </div>

                    <div v-else-if="section === 'webhooks'" class="grid gap-4">
                        <section
                            class="grid grid-cols-4 overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-[#101111]"
                        >
                            <div
                                v-for="stat in webhookStats"
                                :key="stat.label"
                                class="border-r border-zinc-200 px-3 py-2.5 last:border-r-0 dark:border-zinc-800"
                            >
                                <div
                                    class="font-sans text-xs font-medium tracking-widest text-zinc-500 uppercase"
                                >
                                    {{ stat.label }}
                                </div>
                                <div
                                    class="mt-3 font-sans text-xl font-semibold tracking-tight"
                                >
                                    {{ stat.value }}
                                </div>
                                <div
                                    class="mt-1 text-sm"
                                    :class="
                                        stat.tone === 'danger'
                                            ? 'text-red-400'
                                            : stat.tone === 'success'
                                              ? 'text-emerald-400'
                                              : 'text-zinc-500'
                                    "
                                >
                                    {{ stat.meta }}
                                </div>
                            </div>
                        </section>

                        <section
                            class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-[#090a0a]"
                        >
                            <div
                                class="flex items-center gap-3 border-b border-zinc-200 px-3 py-2.5 dark:border-zinc-800"
                            >
                                <div>
                                    <h2
                                        class="font-sans text-base font-semibold"
                                    >
                                        Webhook endpoints
                                    </h2>
                                    <p
                                        class="mt-1 font-sans text-sm text-zinc-500"
                                    >
                                        HTTP endpoints that receive signed
                                        Larasend event payloads.
                                    </p>
                                </div>
                                <button
                                    class="ml-auto inline-flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 font-sans text-sm font-semibold text-zinc-600 hover:text-zinc-950 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100"
                                    @click="
                                        showWebhookDeliveries =
                                            !showWebhookDeliveries
                                    "
                                >
                                    <Cloud class="size-4" />
                                    {{
                                        showWebhookDeliveries
                                            ? 'Hide deliveries'
                                            : 'View deliveries'
                                    }}
                                </button>
                                <button
                                    class="rounded-lg bg-teal-400 px-3 py-2 font-sans text-sm font-bold text-zinc-950"
                                    @click="resetWebhookForm"
                                >
                                    + Add endpoint
                                </button>
                            </div>

                            <form
                                v-if="showWebhookForm"
                                class="grid gap-4 border-b border-zinc-200 p-4 font-sans dark:border-zinc-800"
                                @submit.prevent="saveWebhookEndpoint"
                            >
                                <div
                                    class="grid grid-cols-[minmax(320px,1fr)_140px] gap-4"
                                >
                                    <label class="grid gap-2 text-sm">
                                        <span class="text-zinc-500"
                                            >Endpoint URL</span
                                        >
                                        <input
                                            v-model="webhookForm.url"
                                            type="url"
                                            class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                            placeholder="https://example.com/webhooks/larasend"
                                            required
                                        />
                                    </label>
                                    <label class="grid gap-2 text-sm">
                                        <span class="text-zinc-500"
                                            >Status</span
                                        >
                                        <select
                                            v-model="webhookForm.status"
                                            class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                                        >
                                            <option value="active">
                                                Active
                                            </option>
                                            <option value="paused">
                                                Paused
                                            </option>
                                        </select>
                                    </label>
                                </div>
                                <div>
                                    <div class="text-sm text-zinc-500">
                                        Events
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <button
                                            v-for="event in webhookEventOptions"
                                            :key="event"
                                            type="button"
                                            class="rounded-md border px-3 py-1.5 font-mono text-xs"
                                            :class="
                                                webhookForm.events.includes(
                                                    event,
                                                )
                                                    ? 'border-teal-400 bg-teal-400/10 text-teal-300'
                                                    : 'border-zinc-200 text-zinc-500 dark:border-zinc-800'
                                            "
                                            @click="toggleWebhookEvent(event)"
                                        >
                                            {{ event }}
                                        </button>
                                    </div>
                                </div>
                                <div class="flex justify-end gap-2">
                                    <button
                                        type="button"
                                        class="rounded-lg border border-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-600 dark:border-zinc-800 dark:text-zinc-400"
                                        @click="showWebhookForm = false"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        class="rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-zinc-950"
                                    >
                                        {{
                                            editingWebhookId
                                                ? 'Save endpoint'
                                                : 'Create endpoint'
                                        }}
                                    </button>
                                </div>
                            </form>

                            <div
                                class="grid min-w-[980px] grid-cols-[32px_minmax(300px,1.3fr)_minmax(280px,1fr)_120px_120px_120px_150px] border-b border-zinc-200 bg-zinc-50 px-3 py-2.5 font-mono text-xs tracking-widest text-zinc-500 uppercase dark:border-zinc-800 dark:bg-[#101111]"
                            >
                                <div></div>
                                <div>URL</div>
                                <div>Events</div>
                                <div>Status</div>
                                <div>Success</div>
                                <div>Last</div>
                                <div></div>
                            </div>
                            <div class="overflow-auto">
                                <div
                                    v-for="webhook in webhooks"
                                    :key="webhook.id"
                                    class="grid min-w-[980px] grid-cols-[32px_minmax(300px,1.3fr)_minmax(280px,1fr)_120px_120px_120px_150px] items-center border-b border-zinc-200 px-3 py-2.5 font-sans text-sm last:border-b-0 dark:border-zinc-900"
                                >
                                    <span
                                        class="size-2.5 rounded-full"
                                        :class="
                                            webhook.status === 'healthy'
                                                ? 'bg-emerald-400'
                                                : webhook.status === 'failing'
                                                  ? 'bg-red-400'
                                                  : 'bg-zinc-500'
                                        "
                                    />
                                    <div class="min-w-0">
                                        <div class="truncate font-mono">
                                            {{ webhook.url }}
                                        </div>
                                        <div
                                            class="mt-1 flex items-center gap-2 font-mono text-xs text-zinc-500"
                                        >
                                            <span>{{ webhook.id }}</span>
                                            <span
                                                >· secret
                                                {{
                                                    webhook.secret_prefix
                                                }}...</span
                                            >
                                            <button
                                                class="hover:text-zinc-950 dark:hover:text-zinc-100"
                                                title="Copy endpoint URL"
                                                @click="copyText(webhook.url)"
                                            >
                                                <Copy class="size-3.5" />
                                            </button>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="event in webhook.events"
                                            :key="`${webhook.id}-${event}`"
                                            class="rounded bg-zinc-100 px-2 py-1 font-mono text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                                            >{{ event }}</span
                                        >
                                    </div>
                                    <span
                                        ><span
                                            class="rounded-md px-2 py-1 font-mono text-xs"
                                            :class="
                                                webhook.status === 'healthy'
                                                    ? 'bg-emerald-500/12 text-emerald-400'
                                                    : webhook.status ===
                                                        'failing'
                                                      ? 'bg-red-500/12 text-red-400'
                                                      : 'bg-zinc-500/12 text-zinc-400'
                                            "
                                            >{{ webhook.status }}</span
                                        ></span
                                    >
                                    <span
                                        class="font-mono"
                                        :class="
                                            webhook.success_rate.startsWith('8')
                                                ? 'text-red-400'
                                                : 'text-zinc-600 dark:text-zinc-300'
                                        "
                                        >{{ webhook.success_rate }}</span
                                    >
                                    <span class="text-zinc-500">{{
                                        webhook.last_delivered_at
                                    }}</span>
                                    <div class="flex justify-end gap-3">
                                        <button
                                            class="text-zinc-500 hover:text-zinc-950 dark:hover:text-zinc-100"
                                            @click="editWebhook(webhook)"
                                        >
                                            Edit
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section
                            v-if="sesWebhookUrl"
                            class="rounded-lg border border-zinc-200 bg-white p-4 font-sans dark:border-zinc-800 dark:bg-[#090a0a]"
                        >
                            <div class="flex items-center gap-3">
                                <div>
                                    <h2 class="text-base font-semibold">
                                        SES inbound webhook
                                    </h2>
                                    <p class="mt-1 text-sm text-zinc-500">
                                        Use this URL in SNS/SES so Larasend can
                                        ingest deliveries, opens, clicks,
                                        bounces, and complaints.
                                    </p>
                                </div>
                                <button
                                    class="ml-auto inline-flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-semibold text-zinc-600 hover:text-zinc-950 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100"
                                    @click="copyText(sesWebhookUrl)"
                                >
                                    <Copy class="size-4" /> Copy URL
                                </button>
                            </div>
                            <div
                                class="mt-4 overflow-auto rounded-lg bg-zinc-50 p-3 font-mono text-xs text-zinc-700 dark:bg-zinc-950 dark:text-zinc-300"
                            >
                                {{ sesWebhookUrl }}
                            </div>
                        </section>

                        <section
                            v-if="showWebhookDeliveries"
                            class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-[#090a0a]"
                        >
                            <div
                                class="border-b border-zinc-200 px-3 py-2.5 dark:border-zinc-800"
                            >
                                <h2 class="font-sans text-base font-semibold">
                                    Recent deliveries
                                </h2>
                                <p class="mt-1 font-sans text-sm text-zinc-500">
                                    Latest webhook delivery attempts across all
                                    endpoints.
                                </p>
                            </div>
                            <div
                                class="grid min-w-[880px] grid-cols-[94px_160px_180px_100px_120px_1fr_120px] border-b border-zinc-200 bg-zinc-50 px-3 py-2.5 font-mono text-xs tracking-widest text-zinc-500 uppercase dark:border-zinc-800 dark:bg-[#101111]"
                            >
                                <div>Status</div>
                                <div>Event</div>
                                <div>Endpoint</div>
                                <div>HTTP</div>
                                <div>Latency</div>
                                <div>ID</div>
                                <div>When</div>
                            </div>
                            <div class="max-h-[44vh] overflow-auto">
                                <div
                                    v-for="delivery in webhookDeliveries"
                                    :key="delivery.id"
                                    class="grid min-w-[880px] grid-cols-[94px_160px_180px_100px_120px_1fr_120px] border-b border-zinc-200 px-3 py-2.5 font-mono text-sm last:border-b-0 dark:border-zinc-900"
                                >
                                    <span
                                        ><span
                                            class="rounded-md px-2 py-1 text-xs"
                                            :class="
                                                delivery.status === 'ok'
                                                    ? 'bg-emerald-500/12 text-emerald-400'
                                                    : 'bg-red-500/12 text-red-400'
                                            "
                                            >{{ delivery.status }}</span
                                        ></span
                                    >
                                    <span>{{ delivery.event }}</span>
                                    <span class="text-zinc-500">{{
                                        delivery.endpoint
                                    }}</span>
                                    <span
                                        :class="
                                            delivery.status === 'ok'
                                                ? ''
                                                : 'text-red-400'
                                        "
                                        >{{ delivery.http }}</span
                                    >
                                    <span
                                        :class="
                                            delivery.status === 'ok'
                                                ? ''
                                                : 'text-red-400'
                                        "
                                        >{{ delivery.latency }} ms</span
                                    >
                                    <span class="text-zinc-500">{{
                                        delivery.id
                                    }}</span>
                                    <span class="text-zinc-500">{{
                                        delivery.when
                                    }}</span>
                                </div>
                                <div
                                    v-if="webhookDeliveries.length === 0"
                                    class="px-4 py-10 text-center font-sans text-sm text-zinc-500"
                                >
                                    No webhook deliveries yet.
                                </div>
                            </div>
                        </section>
                    </div>

                    <div v-else-if="section === 'api-keys'" class="grid gap-4">
                        <section
                            class="grid grid-cols-3 overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-[#1d2125] dark:bg-[#111315]"
                        >
                            <div
                                v-for="stat in apiKeyStats"
                                :key="stat.label"
                                class="border-r border-zinc-200 px-3 py-2.5 last:border-r-0 dark:border-[#1d2125]"
                            >
                                <div
                                    class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase"
                                >
                                    {{ stat.label }}
                                </div>
                                <div class="mt-3 text-xl font-semibold">
                                    {{ stat.value }}
                                </div>
                                <div
                                    class="mt-1 font-mono text-[12px] text-zinc-500"
                                >
                                    {{ stat.meta }}
                                </div>
                            </div>
                        </section>

                        <form
                            v-if="workspace.can_manage_api_keys"
                            class="grid gap-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-[#1d2125] dark:bg-[#111315]"
                            @submit.prevent="issueApiKey"
                        >
                            <div class="flex items-center gap-3">
                                <KeyRound class="size-4 text-teal-400" />
                                <div class="min-w-0">
                                    <div class="font-semibold">
                                        Keys are only shown once at creation.
                                    </div>
                                    <div
                                        class="mt-0.5 text-[12px] text-zinc-500"
                                    >
                                        Pick scopes, optional expiration, then
                                        copy the full token from the reveal
                                        dialog.
                                    </div>
                                </div>
                                <button
                                    class="ml-auto rounded-lg bg-teal-400 px-3 py-2 text-[12px] font-bold text-zinc-950 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="apiKeyForm.scopes.length === 0"
                                >
                                    + Create key
                                </button>
                            </div>
                            <div
                                class="grid gap-3 md:grid-cols-[minmax(220px,1fr)_220px_minmax(260px,1fr)]"
                            >
                                <label>
                                    <span
                                        class="mb-1.5 block text-xs text-zinc-500"
                                        >Key name</span
                                    >
                                    <input
                                        v-model="apiKeyForm.name"
                                        class="h-9 w-full rounded-md border border-zinc-200 bg-white px-3 text-[12px] dark:border-[#1d2125] dark:bg-[#0b0c0d]"
                                        placeholder="Production · Harborlight"
                                        required
                                    />
                                </label>
                                <label>
                                    <span
                                        class="mb-1.5 block text-xs text-zinc-500"
                                        >Expires</span
                                    >
                                    <input
                                        v-model="apiKeyForm.expires_at"
                                        type="datetime-local"
                                        class="h-9 w-full rounded-md border border-zinc-200 bg-white px-3 text-[12px] dark:border-[#1d2125] dark:bg-[#0b0c0d]"
                                    />
                                </label>
                                <div>
                                    <span
                                        class="mb-1.5 block text-xs text-zinc-500"
                                        >Scopes</span
                                    >
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-for="scope in apiKeyScopeOptions"
                                            :key="scope.value"
                                            type="button"
                                            class="rounded-md border px-3 py-2 font-mono text-xs"
                                            :class="
                                                apiKeyForm.scopes.includes(
                                                    scope.value,
                                                )
                                                    ? 'border-teal-400 bg-teal-400/10 text-teal-600 dark:text-teal-300'
                                                    : 'border-zinc-200 text-zinc-500 dark:border-zinc-800'
                                            "
                                            @click="
                                                toggleApiKeyScope(scope.value)
                                            "
                                        >
                                            {{ scope.label }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                        <div
                            class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-[#1d2125] dark:bg-[#0b0c0d]"
                        >
                            <div
                                class="grid grid-cols-[240px_170px_minmax(190px,1fr)_150px_160px_120px_92px] border-b border-zinc-200 bg-zinc-50 px-3 py-2 font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:border-[#1d2125] dark:bg-[#111315]"
                            >
                                <div>Name</div>
                                <div>Key prefix</div>
                                <div>Scopes</div>
                                <div>Last used</div>
                                <div>Last app/IP</div>
                                <div>Expires</div>
                                <div>Created</div>
                                <div></div>
                            </div>
                            <div
                                v-for="(apiKey, index) in apiKeys"
                                :key="apiKey.id"
                                class="grid min-h-12 grid-cols-[240px_170px_minmax(190px,1fr)_150px_160px_120px_92px] items-center gap-2 border-b border-zinc-200 px-3 py-2 text-[13px] last:border-b-0 dark:border-[#16191c]"
                            >
                                <div class="min-w-0">
                                    <div class="truncate font-semibold">
                                        {{ apiKey.name }}
                                    </div>
                                    <div
                                        class="font-mono text-[11px] text-zinc-500"
                                    >
                                        k_{{
                                            index.toString().padStart(5, '0')
                                        }}
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="font-mono text-[12px] text-zinc-500"
                                        >{{ apiKey.prefix }}••••</span
                                    >
                                    <button
                                        class="rounded p-1 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-950 dark:hover:bg-[#111315] dark:hover:text-zinc-100"
                                        title="Copy key prefix"
                                        @click="copyText(apiKey.prefix)"
                                    >
                                        <Copy class="size-3.5" />
                                    </button>
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="scope in apiKeyScopes(apiKey)"
                                        :key="`${apiKey.id}-${scope}`"
                                        class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-500 dark:bg-[#1a1e22]"
                                        >{{ scope }}</span
                                    >
                                </div>
                                <div
                                    class="font-mono text-[12px] text-zinc-500"
                                >
                                    {{
                                        apiKey.last_used_at
                                            ? relativeTime(apiKey.last_used_at)
                                            : 'never'
                                    }}
                                </div>
                                <div
                                    class="min-w-0 font-mono text-[12px] text-zinc-500"
                                >
                                    <div class="truncate">
                                        {{ apiKey.last_used_ip || '—' }}
                                    </div>
                                    <div class="truncate text-[10px]">
                                        {{
                                            apiKey.last_used_user_agent ||
                                            'no app'
                                        }}
                                    </div>
                                </div>
                                <div
                                    class="font-mono text-[12px] text-zinc-500"
                                >
                                    {{
                                        apiKey.expires_at
                                            ? relativeTime(apiKey.expires_at)
                                            : 'never'
                                    }}
                                </div>
                                <div
                                    class="font-mono text-[12px] text-zinc-500"
                                >
                                    {{ apiKey.created_at }}
                                </div>
                                <div
                                    v-if="workspace.can_manage_api_keys"
                                    class="flex justify-end gap-1"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex size-7 items-center justify-center rounded-md text-zinc-400 hover:bg-amber-50 hover:text-amber-600 dark:hover:bg-amber-500/10 dark:hover:text-amber-300"
                                        title="Rotate API key"
                                        @click="rotateApiKey(apiKey)"
                                    >
                                        <RefreshCw class="size-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex size-7 items-center justify-center rounded-md text-zinc-400 hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-500/10 dark:hover:text-red-300"
                                        title="Delete API key"
                                        @click="deleteApiKey(apiKey)"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else class="grid gap-4">
                        <section
                            class="grid grid-cols-5 overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-[#1d2125] dark:bg-[#111315]"
                        >
                            <div
                                class="border-r border-zinc-200 px-3 py-2.5 dark:border-[#1d2125]"
                            >
                                <div
                                    class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase"
                                >
                                    Suppressed
                                </div>
                                <div class="mt-3 text-xl font-semibold">
                                    {{ suppressionRows.length }}
                                </div>
                                <div
                                    class="mt-1 font-mono text-[12px] text-zinc-500"
                                >
                                    total
                                </div>
                            </div>
                            <div
                                class="border-r border-zinc-200 px-3 py-2.5 dark:border-[#1d2125]"
                            >
                                <div
                                    class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase"
                                >
                                    Bounces
                                </div>
                                <div class="mt-3 text-xl font-semibold">
                                    {{ bounceSuppressionCount }}
                                </div>
                                <div
                                    class="mt-1 font-mono text-[12px] text-red-400"
                                >
                                    blocked
                                </div>
                            </div>
                            <div
                                class="border-r border-zinc-200 px-3 py-2.5 dark:border-[#1d2125]"
                            >
                                <div
                                    class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase"
                                >
                                    Complaints
                                </div>
                                <div class="mt-3 text-xl font-semibold">
                                    {{ complaintSuppressionCount }}
                                </div>
                                <div
                                    class="mt-1 font-mono text-[12px] text-violet-400"
                                >
                                    blocked
                                </div>
                            </div>
                            <div
                                class="border-r border-zinc-200 px-3 py-2.5 dark:border-[#1d2125]"
                            >
                                <div
                                    class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase"
                                >
                                    Manual
                                </div>
                                <div class="mt-3 text-xl font-semibold">0</div>
                                <div
                                    class="mt-1 font-mono text-[12px] text-zinc-500"
                                >
                                    list
                                </div>
                            </div>
                            <div class="px-3 py-2.5">
                                <div
                                    class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase"
                                >
                                    Policy
                                </div>
                                <div class="mt-3 text-xl font-semibold">
                                    Active
                                </div>
                                <div
                                    class="mt-1 font-mono text-[12px] text-emerald-400"
                                >
                                    enforced
                                </div>
                            </div>
                        </section>

                        <section>
                            <div class="mb-3 flex items-end gap-3">
                                <div>
                                    <h2 class="text-base font-semibold">
                                        Suppression list
                                    </h2>
                                    <p class="mt-0.5 text-[12px] text-zinc-500">
                                        Recipients automatically excluded from
                                        future sends.
                                    </p>
                                </div>
                                <a
                                    :href="exportHref"
                                    class="ml-auto rounded-lg border border-zinc-200 px-3 py-2 text-[12px] font-semibold text-zinc-600 hover:text-zinc-950 dark:border-[#1d2125] dark:text-zinc-400 dark:hover:text-zinc-100"
                                >
                                    Export
                                </a>
                            </div>
                            <div
                                class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-[#1d2125] dark:bg-[#0b0c0d]"
                            >
                                <div
                                    class="grid grid-cols-[minmax(260px,1fr)_150px_220px_140px_130px] border-b border-zinc-200 bg-zinc-50 px-3 py-2 font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:border-[#1d2125] dark:bg-[#111315]"
                                >
                                    <div>Recipient</div>
                                    <div>Reason</div>
                                    <div>Source</div>
                                    <div>Added</div>
                                    <div>Expires</div>
                                </div>
                                <div
                                    v-for="email in suppressionRows"
                                    :key="email.id"
                                    class="grid h-11 grid-cols-[minmax(260px,1fr)_150px_220px_140px_130px] items-center border-b border-zinc-200 px-3 text-[13px] last:border-b-0 dark:border-[#16191c]"
                                >
                                    <div class="truncate">
                                        {{ email.email }}
                                    </div>
                                    <div>
                                        <span
                                            class="rounded px-1.5 py-0.5 font-mono text-[11px]"
                                            :class="
                                                email.reason === 'complaint'
                                                    ? statusClass('complained')
                                                    : statusClass('bounced')
                                            "
                                            >{{ email.reason }}</span
                                        >
                                    </div>
                                    <div
                                        class="truncate font-mono text-[12px] text-zinc-500"
                                    >
                                        {{ email.source }}
                                    </div>
                                    <div
                                        class="font-mono text-[12px] text-zinc-500"
                                    >
                                        {{ email.added }}
                                    </div>
                                    <div
                                        class="font-mono text-[12px] text-zinc-500"
                                    >
                                        {{ email.expires }}
                                    </div>
                                </div>
                                <div
                                    v-if="suppressionRows.length === 0"
                                    class="px-4 py-10 text-center text-[13px] text-zinc-500"
                                >
                                    No suppressed recipients yet.
                                </div>
                            </div>
                        </section>
                    </div>
                </section>
            </main>
        </div>

        <div
            v-if="showProjectForm"
            class="fixed inset-0 z-50 grid place-items-center bg-zinc-950/75 px-4 backdrop-blur-sm"
        >
            <form
                class="w-full max-w-lg rounded-xl border border-zinc-200 bg-white p-5 shadow-2xl dark:border-[#1d2125] dark:bg-[#111315]"
                @submit.prevent="createProject"
            >
                <div class="flex items-start gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">New project</h2>
                        <p class="mt-1 text-sm leading-6 text-zinc-500">
                            Create an isolated project for a product, customer,
                            service, or environment group.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="ml-auto rounded-md p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-950 dark:hover:bg-zinc-900 dark:hover:text-zinc-100"
                        @click="showProjectForm = false"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div class="mt-5 grid gap-4">
                    <label class="grid gap-2 text-sm">
                        <span class="text-zinc-500">Project name</span>
                        <input
                            v-model="projectForm.name"
                            class="rounded-md border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-[#101111]"
                            placeholder="Northwind production"
                            required
                        />
                    </label>
                    <label class="grid gap-2 text-sm">
                        <span class="text-zinc-500">Slug</span>
                        <input
                            v-model="projectForm.slug"
                            class="rounded-md border border-zinc-200 bg-white px-3 py-2 font-mono dark:border-zinc-800 dark:bg-[#101111]"
                            placeholder="northwind-production"
                        />
                        <span class="text-xs text-zinc-500">
                            Leave blank to generate it from the project name.
                        </span>
                    </label>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-900"
                        @click="showProjectForm = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="rounded-lg bg-teal-300 px-4 py-2 text-sm font-bold text-zinc-950"
                    >
                        Create project
                    </button>
                </div>
            </form>
        </div>

        <div
            v-if="confirmation"
            class="fixed inset-0 z-50 grid place-items-center bg-zinc-950/75 px-4 backdrop-blur-sm"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="confirmation-title"
                class="w-full max-w-lg rounded-xl border border-zinc-200 bg-white p-5 shadow-2xl dark:border-[#1d2125] dark:bg-[#111315]"
            >
                <div class="flex items-start gap-4">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-lg"
                        :class="
                            confirmation.tone === 'danger'
                                ? 'bg-red-500/12 text-red-500'
                                : 'bg-amber-500/12 text-amber-500'
                        "
                    >
                        <AlertTriangle class="size-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2
                            id="confirmation-title"
                            class="text-lg font-semibold"
                        >
                            {{ confirmation.title }}
                        </h2>
                        <p class="mt-2 text-sm leading-6 text-zinc-500">
                            {{ confirmation.body }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-950 dark:hover:bg-zinc-900 dark:hover:text-zinc-100"
                        title="Close confirmation"
                        @click="closeConfirmation"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-900"
                        @click="closeConfirmation"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-bold"
                        :class="
                            confirmation.tone === 'danger'
                                ? 'bg-red-500 text-white'
                                : 'bg-amber-400 text-amber-950'
                        "
                        @click="confirmAction"
                    >
                        {{ confirmation.actionLabel }}
                    </button>
                </div>
            </section>
        </div>

        <div
            v-if="revealedApiKey"
            class="fixed inset-0 z-50 grid place-items-center bg-zinc-950/75 px-4 backdrop-blur-sm"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="api-key-title"
                class="w-full max-w-2xl rounded-lg border border-zinc-200 bg-white p-5 font-sans shadow-2xl dark:border-zinc-800 dark:bg-[#101111]"
            >
                <div class="flex items-start gap-4">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-teal-400 text-zinc-950"
                    >
                        <KeyRound class="size-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2
                            id="api-key-title"
                            class="text-xl font-semibold tracking-tight"
                        >
                            Copy this API key now
                        </h2>
                        <p class="mt-1 text-sm leading-6 text-zinc-500">
                            For security, Larasend only shows the full token
                            once. Store it in your application secrets before
                            closing this dialog.
                        </p>
                    </div>
                    <button
                        class="rounded-md p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-950 dark:hover:bg-zinc-900 dark:hover:text-zinc-100"
                        title="Close API key dialog"
                        @click="closeApiKeyModal"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div
                    class="mt-5 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950"
                >
                    <div
                        class="text-xs font-semibold tracking-widest text-zinc-500 uppercase"
                    >
                        New API key
                    </div>
                    <textarea
                        :value="revealedApiKey"
                        readonly
                        rows="3"
                        class="mt-3 w-full resize-none rounded-md border border-zinc-200 bg-white p-3 font-mono text-sm leading-6 break-all text-zinc-950 outline-none selection:bg-teal-200 focus:border-teal-400 dark:border-zinc-800 dark:bg-[#101111] dark:text-zinc-100"
                        aria-label="Full API key"
                        @focus="selectTextArea"
                        @click="selectTextArea"
                    />
                </div>

                <div
                    class="mt-5 flex flex-wrap items-center justify-between gap-3"
                >
                    <p class="text-sm text-zinc-500">
                        This token will not be available again after this page
                        state is dismissed.
                    </p>
                    <div class="flex gap-2">
                        <button
                            class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-700 hover:text-zinc-950 dark:border-zinc-800 dark:text-zinc-300 dark:hover:text-zinc-100"
                            @click="copyRevealedApiKey"
                        >
                            <Copy class="size-4" />
                            {{ apiKeyCopied ? 'Copied' : 'Copy key' }}
                        </button>
                        <button
                            class="rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-zinc-950"
                            @click="closeApiKeyModal"
                        >
                            Done
                        </button>
                    </div>
                </div>
            </section>
        </div>

        <div
            v-if="revealedWebhookEndpoint"
            class="fixed inset-0 z-50 grid place-items-center bg-zinc-950/75 px-4 backdrop-blur-sm"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="webhook-secret-title"
                class="w-full max-w-2xl rounded-lg border border-zinc-200 bg-white p-5 font-sans shadow-2xl dark:border-zinc-800 dark:bg-[#101111]"
            >
                <div class="flex items-start gap-4">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-teal-400 text-zinc-950"
                    >
                        <Cloud class="size-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2
                            id="webhook-secret-title"
                            class="text-xl font-semibold tracking-tight"
                        >
                            Copy this webhook secret now
                        </h2>
                        <p class="mt-1 text-sm leading-6 text-zinc-500">
                            Use this secret to verify Larasend webhook
                            signatures. The full secret is only shown once.
                        </p>
                    </div>
                    <button
                        class="rounded-md p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-950 dark:hover:bg-zinc-900 dark:hover:text-zinc-100"
                        title="Close webhook secret dialog"
                        @click="closeWebhookSecretModal"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div class="mt-5 grid gap-3">
                    <div
                        class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <div
                            class="text-xs font-semibold tracking-widest text-zinc-500 uppercase"
                        >
                            Endpoint URL
                        </div>
                        <div
                            class="mt-3 font-mono text-sm leading-6 break-all text-zinc-950 dark:text-zinc-100"
                        >
                            {{ revealedWebhookEndpoint.url }}
                        </div>
                    </div>
                    <div
                        class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950"
                    >
                        <div
                            class="text-xs font-semibold tracking-widest text-zinc-500 uppercase"
                        >
                            Signing secret
                        </div>
                        <div
                            class="mt-3 font-mono text-sm leading-6 break-all text-zinc-950 dark:text-zinc-100"
                        >
                            {{ revealedWebhookEndpoint.secret }}
                        </div>
                    </div>
                </div>

                <div
                    class="mt-5 flex flex-wrap items-center justify-between gap-3"
                >
                    <p class="text-sm text-zinc-500">
                        Store it in your webhook consumer secrets before closing
                        this dialog.
                    </p>
                    <div class="flex gap-2">
                        <button
                            class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-700 hover:text-zinc-950 dark:border-zinc-800 dark:text-zinc-300 dark:hover:text-zinc-100"
                            @click="copyText(revealedWebhookEndpoint.url)"
                        >
                            <Copy class="size-4" /> Copy URL
                        </button>
                        <button
                            class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-700 hover:text-zinc-950 dark:border-zinc-800 dark:text-zinc-300 dark:hover:text-zinc-100"
                            @click="copyWebhookSecret"
                        >
                            <Copy class="size-4" />
                            {{ webhookSecretCopied ? 'Copied' : 'Copy secret' }}
                        </button>
                        <button
                            class="rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-zinc-950"
                            @click="closeWebhookSecretModal"
                        >
                            Done
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </div>
    <Toaster />
</template>
