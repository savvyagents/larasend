<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    Copy,
    KeyRound,
    MailCheck,
    Rocket,
    Send,
    Server,
    ShieldCheck,
    Webhook,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Toaster } from '@/components/ui/sonner';

type CredentialMode =
    | 'instance_role'
    | 'aws_keys'
    | 'cloudflare_token'
    | 'configure_later';

type DnsRecord = {
    type: string;
    name: string;
    value: string;
    status: string;
};

type ProgressStep = {
    key: string;
    label: string;
    complete: boolean;
};

type WizardStep = {
    key: string;
    label: string;
    eyebrow: string;
    title: string;
    description: string;
};

const props = defineProps<{
    workspace: {
        name: string;
        onboarded_at: string | null;
        setup_started_at: string | null;
    };
    project: {
        name: string;
        slug: string;
        path: string;
        setup_path: string;
        send_path: string;
        is_complete: boolean;
        has_started_setup: boolean;
        resume_path: string;
        credential_mode: CredentialMode;
        next_step: ProgressStep | null;
    };
    source: {
        name: string;
        environment: string;
        provider: 'ses' | 'cloudflare';
        ses_region: string | null;
        ses_configuration_set: string | null;
        cloudflare_account_id: string | null;
        default_from_name: string | null;
        default_from_email: string | null;
        has_aws_credentials: boolean;
        has_aws_session_token: boolean;
        has_cloudflare_credentials: boolean;
        webhook_url: string | null;
    } | null;
    domain: {
        domain: string;
        status: string;
        dns_records: DnsRecord[];
    } | null;
    progress: ProgressStep[];
    install: { compose: string; migrate: string; worker: string };
}>();

const copied = ref('');
const currentStepIndex = ref(0);

const form = useForm({
    workspace_name: props.workspace.name,
    project_name: props.project.name,
    project_slug: props.project.slug,
    credential_mode: props.project.credential_mode,
    source_name: props.source?.name ?? 'Production',
    environment: props.source?.environment ?? 'prod',
    ses_region: props.source?.ses_region ?? 'us-east-1',
    ses_configuration_set: props.source?.ses_configuration_set ?? '',
    default_from_name: props.source?.default_from_name ?? 'Larasend',
    default_from_email: props.source?.default_from_email ?? '',
    aws_access_key_id: '',
    aws_secret_access_key: '',
    aws_session_token: '',
    cloudflare_account_id: props.source?.cloudflare_account_id ?? '',
    cloudflare_api_token: '',
    sending_domain: props.domain?.domain ?? '',
    create_api_key: true,
    api_key_name: 'Production key',
    webhook_url: '',
});

const wizardSteps: WizardStep[] = [
    {
        key: 'connect',
        label: 'Connect provider',
        eyebrow: 'Step 1',
        title: 'Connect your email provider',
        description:
            'Send through Amazon SES with IAM keys or an attached role, or through Cloudflare Email Service with an API token. Larasend verifies the credentials live before continuing.',
    },
    {
        key: 'domain',
        label: 'Sending domain',
        eyebrow: 'Step 2',
        title: 'Choose your sending domain',
        description:
            'Larasend onboards the domain with your provider and publishes its DNS records automatically, then verifies them in the background.',
    },
    {
        key: 'finish',
        label: 'Finish',
        eyebrow: 'Step 3',
        title: 'Save and start sending',
        description:
            'Saving creates your first API key (shown once), onboards the domain, and hands over to the setup board — which completes itself from here.',
    },
];

const currentStep = computed(() => wizardSteps[currentStepIndex.value]);
const isFirstStep = computed(() => currentStepIndex.value === 0);
const isLastStep = computed(
    () => currentStepIndex.value === wizardSteps.length - 1,
);
const completedCount = computed(
    () => props.progress.filter((step) => step.complete).length,
);
const completionPercent = computed(() =>
    Math.round(
        (completedCount.value / Math.max(props.progress.length, 1)) * 100,
    ),
);
const dnsRows = computed(() => props.domain?.dns_records ?? []);
const canShowAwsFields = computed(() => form.credential_mode === 'aws_keys');
const isCloudflareMode = computed(
    () => form.credential_mode === 'cloudflare_token',
);

type ValidationIssue = { code: string; message: string };
type ZoneOption = { id: string; name: string; account_id: string | null };
type CredentialCheck = {
    ok: boolean;
    blockers: ValidationIssue[];
    warnings: ValidationIssue[];
    meta: {
        zones?: ZoneOption[];
        account_id?: string | null;
        quota?: { max_24_hour_send: number | null; period?: string } | null;
    };
};

const credentialCheck = ref<CredentialCheck | null>(null);
const validationFailed = ref(false);
const validationHttp = useHttp({
    provider: 'ses',
    ses_region: '',
    aws_access_key_id: '',
    aws_secret_access_key: '',
    aws_session_token: '',
    cloudflare_api_token: '',
});

const availableZones = computed<ZoneOption[]>(
    () => credentialCheck.value?.meta.zones ?? [],
);
const selectedZoneName = ref('');
const subdomainLabel = ref('mail');
const fromLocalPart = ref('notifications');
const skipsValidation = computed(
    () => form.credential_mode === 'configure_later',
);

// Keep form.sending_domain and the from-address suggestion in sync with the
// zone picker, without clobbering values the user typed by hand.
watch([selectedZoneName, subdomainLabel], ([zone, label]) => {
    if (!zone) {
        return;
    }

    form.sending_domain = label ? `${label}.${zone}` : zone;
});

watch(
    () => form.sending_domain,
    (domain) => {
        if (domain) {
            form.default_from_email = `${fromLocalPart.value}@${domain}`;
        }
    },
);

function validateCredentials(): void {
    validationFailed.value = false;
    validationHttp.provider = isCloudflareMode.value ? 'cloudflare' : 'ses';
    validationHttp.ses_region = form.ses_region;
    validationHttp.aws_access_key_id = form.aws_access_key_id;
    validationHttp.aws_secret_access_key = form.aws_secret_access_key;
    validationHttp.aws_session_token = form.aws_session_token;
    validationHttp.cloudflare_api_token = form.cloudflare_api_token;

    validationHttp.post('/onboarding/validate', {
        onSuccess: (response: unknown) => {
            const result = (
                response && typeof response === 'object' && 'data' in response
                    ? (response as { data: CredentialCheck }).data
                    : response
            ) as CredentialCheck;

            credentialCheck.value = result;

            if (result.ok) {
                if (
                    availableZones.value.length &&
                    !selectedZoneName.value &&
                    !form.sending_domain
                ) {
                    selectedZoneName.value = availableZones.value[0].name;
                }

                currentStepIndex.value += 1;
            }
        },
        onError: () => {
            validationFailed.value = true;
        },
    });
}
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
const codeSnippet = computed(() =>
    [
        'MAIL_MAILER=larasend',
        'LARASEND_API_KEY=ls_your_key_from_larasend',
        `LARASEND_ENDPOINT=${window.location.origin}`,
    ].join('\n'),
);
const nextSetupLabel = computed(
    () => props.project.next_step?.label ?? 'Open setup guide',
);

watch(
    () => form.project_name,
    (name) => {
        if (
            !props.project.has_started_setup &&
            form.project_slug === props.project.slug
        ) {
            form.project_slug = slugify(name);
        }
    },
);

function slugify(value: string): string {
    return value
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .replace(/-{2,}/g, '-');
}

function copy(value: string, key: string): void {
    void navigator.clipboard?.writeText(value);
    copied.value = key;
    window.setTimeout(() => {
        if (copied.value === key) {
            copied.value = '';
        }
    }, 1300);
}

function goNext(): void {
    if (isLastStep.value) {
        return;
    }

    if (currentStep.value.key === 'connect' && !skipsValidation.value) {
        validateCredentials();

        return;
    }

    currentStepIndex.value += 1;
}

function goBack(): void {
    if (!isFirstStep.value) {
        currentStepIndex.value -= 1;
    }
}

function goToStep(index: number): void {
    currentStepIndex.value = index;
}

function submit(): void {
    form.post('/onboarding', {
        preserveScroll: true,
        onError: () => {
            if (
                form.errors.aws_access_key_id ||
                form.errors.aws_secret_access_key ||
                form.errors.cloudflare_api_token ||
                form.errors.credential_mode ||
                form.errors.ses_region
            ) {
                currentStepIndex.value = 0;

                return;
            }

            if (form.errors.default_from_email || form.errors.sending_domain) {
                currentStepIndex.value = 1;
            }
        },
    });
}
</script>

<template>
    <Head title="First-run setup" />

    <main
        class="min-h-screen bg-[#fbfaf7] text-[13px] text-zinc-950 dark:bg-[#090a0a] dark:text-zinc-100"
    >
        <header
            class="sticky top-0 z-20 border-b border-zinc-200 bg-[#fbfaf7]/95 backdrop-blur dark:border-[#1d2125] dark:bg-[#090a0a]/95"
        >
            <div
                class="mx-auto flex h-[56px] w-full max-w-[1240px] items-center justify-between px-4"
            >
                <div class="flex items-center gap-2.5">
                    <div
                        class="grid size-8 place-items-center rounded-md bg-teal-300 font-mono text-xs font-semibold text-zinc-950"
                    >
                        L
                    </div>
                    <div>
                        <div class="text-[13px] leading-4 font-semibold">
                            larasend
                        </div>
                        <div class="font-mono text-[10px] text-zinc-500">
                            first-run setup
                        </div>
                    </div>
                </div>

                <Link
                    :href="project.resume_path"
                    class="rounded-md border border-zinc-200 px-3 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-100 active:translate-y-px dark:border-[#1d2125] dark:text-zinc-300 dark:hover:bg-[#16191c]"
                >
                    Open setup guide
                </Link>
            </div>
        </header>

        <div
            class="mx-auto grid w-full max-w-[1240px] gap-4 px-4 py-4 lg:grid-cols-[320px_minmax(0,1fr)]"
        >
            <aside class="grid content-start gap-3 lg:sticky lg:top-[72px]">
                <section
                    class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-[#1d2125] dark:bg-[#111315]"
                >
                    <p
                        class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                    >
                        Bootstrap progress
                    </p>
                    <div class="mt-2 flex items-end justify-between gap-3">
                        <h1 class="text-3xl font-semibold">
                            {{ completionPercent }}%
                        </h1>
                        <span
                            class="rounded-full border border-zinc-200 px-2 py-1 font-mono text-[11px] text-zinc-500 dark:border-zinc-800"
                        >
                            {{ completedCount }}/{{ progress.length }}
                        </span>
                    </div>
                    <div
                        class="mt-3 h-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-900"
                    >
                        <div
                            class="h-full rounded-full bg-teal-300 transition-all duration-500"
                            :style="{ width: `${completionPercent}%` }"
                        />
                    </div>

                    <div class="mt-4 grid gap-1.5">
                        <button
                            v-for="(step, index) in wizardSteps"
                            :key="step.key"
                            type="button"
                            class="flex items-center gap-2.5 rounded-md px-2 py-2 text-left transition hover:bg-zinc-50 dark:hover:bg-[#171a1d]"
                            :class="
                                index === currentStepIndex
                                    ? 'bg-teal-50 dark:bg-teal-400/10'
                                    : ''
                            "
                            @click="goToStep(index)"
                        >
                            <span
                                class="grid size-5 shrink-0 place-items-center rounded-full border font-mono text-[10px]"
                                :class="
                                    index < currentStepIndex
                                        ? 'border-teal-300 bg-teal-300 text-zinc-950'
                                        : 'border-zinc-300 text-zinc-500 dark:border-zinc-700'
                                "
                            >
                                <Check
                                    v-if="index < currentStepIndex"
                                    class="size-3"
                                />
                                <span v-else>{{ index + 1 }}</span>
                            </span>
                            <span class="font-medium">{{ step.label }}</span>
                        </button>
                    </div>
                </section>

                <section
                    class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-[#1d2125] dark:bg-[#111315]"
                >
                    <div class="flex items-center gap-2">
                        <Rocket class="size-4 text-teal-500" />
                        <h2 class="font-semibold">Next after bootstrap</h2>
                    </div>
                    <p class="mt-2 text-[12.5px] leading-5 text-zinc-500">
                        {{ nextSetupLabel }} is the next operational checkpoint.
                        The setup guide remains available until DNS verifies and
                        the first real send is visible in Activity.
                    </p>
                    <Link
                        :href="project.resume_path"
                        class="mt-3 inline-flex items-center gap-2 rounded-md bg-zinc-950 px-3 py-2 text-xs font-semibold text-white transition hover:bg-zinc-800 active:translate-y-px dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white"
                    >
                        Open setup guide
                        <ArrowRight class="size-3.5" />
                    </Link>
                </section>
            </aside>

            <form class="grid min-w-0 gap-4" @submit.prevent="submit">
                <section
                    class="rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-[#1d2125] dark:bg-[#111315]"
                >
                    <div
                        class="border-b border-zinc-200 px-5 py-4 dark:border-[#1d2125]"
                    >
                        <div
                            class="font-mono text-[10px] tracking-widest text-teal-600 uppercase dark:text-teal-300"
                        >
                            {{ currentStep.eyebrow }}
                        </div>
                        <h2 class="mt-1 text-xl font-semibold">
                            {{ currentStep.title }}
                        </h2>
                        <p class="mt-1 max-w-2xl text-sm text-zinc-500">
                            {{ currentStep.description }}
                        </p>
                    </div>

                    <div class="grid gap-4 p-5">
                        <div
                            v-if="currentStep.key === 'connect'"
                            class="grid gap-4"
                        >
                            <div
                                class="grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                            >
                                <label
                                    class="grid cursor-pointer gap-2 rounded-lg border p-3 transition"
                                    :class="
                                        form.credential_mode === 'aws_keys'
                                            ? 'border-teal-300 bg-teal-50 dark:bg-teal-400/10'
                                            : 'border-zinc-200 dark:border-zinc-800'
                                    "
                                >
                                    <input
                                        v-model="form.credential_mode"
                                        class="sr-only"
                                        type="radio"
                                        value="aws_keys"
                                    />
                                    <ShieldCheck class="size-4 text-teal-500" />
                                    <span
                                        class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                                        >Amazon SES</span
                                    >
                                    <span class="font-semibold">IAM keys</span>
                                    <span
                                        class="text-xs leading-5 text-zinc-500"
                                    >
                                        Paste an AWS access key and secret for a
                                        self-hosted install.
                                    </span>
                                </label>
                                <label
                                    class="grid cursor-pointer gap-2 rounded-lg border p-3 transition"
                                    :class="
                                        form.credential_mode === 'instance_role'
                                            ? 'border-teal-300 bg-teal-50 dark:bg-teal-400/10'
                                            : 'border-zinc-200 dark:border-zinc-800'
                                    "
                                >
                                    <input
                                        v-model="form.credential_mode"
                                        class="sr-only"
                                        type="radio"
                                        value="instance_role"
                                    />
                                    <Server class="size-4 text-teal-500" />
                                    <span
                                        class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                                        >Amazon SES</span
                                    >
                                    <span class="font-semibold"
                                        >Instance role</span
                                    >
                                    <span
                                        class="text-xs leading-5 text-zinc-500"
                                    >
                                        Use the IAM role attached to the AWS
                                        host running Larasend.
                                    </span>
                                </label>
                                <label
                                    class="grid cursor-pointer gap-2 rounded-lg border p-3 transition"
                                    :class="
                                        form.credential_mode ===
                                        'cloudflare_token'
                                            ? 'border-teal-300 bg-teal-50 dark:bg-teal-400/10'
                                            : 'border-zinc-200 dark:border-zinc-800'
                                    "
                                >
                                    <input
                                        v-model="form.credential_mode"
                                        class="sr-only"
                                        type="radio"
                                        value="cloudflare_token"
                                    />
                                    <KeyRound class="size-4 text-teal-500" />
                                    <span
                                        class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                                        >Cloudflare</span
                                    >
                                    <span class="font-semibold">API token</span>
                                    <span
                                        class="text-xs leading-5 text-zinc-500"
                                    >
                                        Send through Cloudflare Email Service
                                        with a scoped API token.
                                    </span>
                                </label>
                                <label
                                    class="grid cursor-pointer gap-2 rounded-lg border p-3 transition"
                                    :class="
                                        form.credential_mode ===
                                        'configure_later'
                                            ? 'border-teal-300 bg-teal-50 dark:bg-teal-400/10'
                                            : 'border-zinc-200 dark:border-zinc-800'
                                    "
                                >
                                    <input
                                        v-model="form.credential_mode"
                                        class="sr-only"
                                        type="radio"
                                        value="configure_later"
                                    />
                                    <Rocket class="size-4 text-teal-500" />
                                    <span
                                        class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                                        >Any provider</span
                                    >
                                    <span class="font-semibold"
                                        >Configure later</span
                                    >
                                    <span
                                        class="text-xs leading-5 text-zinc-500"
                                    >
                                        Save the project now and finish provider
                                        wiring from the setup guide.
                                    </span>
                                </label>
                            </div>

                            <div class="grid gap-3 md:grid-cols-3">
                                <label class="grid gap-1.5">
                                    <span class="text-zinc-500"
                                        >Source name</span
                                    >
                                    <input
                                        v-model="form.source_name"
                                        required
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                    />
                                </label>
                                <label class="grid gap-1.5">
                                    <span class="text-zinc-500"
                                        >Environment</span
                                    >
                                    <input
                                        v-model="form.environment"
                                        required
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 font-mono text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                    />
                                </label>
                                <label
                                    v-if="!isCloudflareMode"
                                    class="grid gap-1.5"
                                >
                                    <span class="text-zinc-500"
                                        >SES region</span
                                    >
                                    <input
                                        v-model="form.ses_region"
                                        required
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 font-mono text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                    />
                                </label>
                            </div>

                            <div
                                v-if="canShowAwsFields"
                                class="grid gap-3 md:grid-cols-3"
                            >
                                <label class="grid gap-1.5">
                                    <span class="text-zinc-500"
                                        >AWS access key ID</span
                                    >
                                    <input
                                        v-model="form.aws_access_key_id"
                                        autocomplete="off"
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 font-mono text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                    />
                                    <span
                                        v-if="form.errors.aws_access_key_id"
                                        class="text-xs text-red-500"
                                        >{{
                                            form.errors.aws_access_key_id
                                        }}</span
                                    >
                                </label>
                                <label class="grid gap-1.5">
                                    <span class="text-zinc-500"
                                        >AWS secret access key</span
                                    >
                                    <input
                                        v-model="form.aws_secret_access_key"
                                        autocomplete="off"
                                        type="password"
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                    />
                                    <span
                                        v-if="form.errors.aws_secret_access_key"
                                        class="text-xs text-red-500"
                                        >{{
                                            form.errors.aws_secret_access_key
                                        }}</span
                                    >
                                </label>
                                <label class="grid gap-1.5">
                                    <span class="text-zinc-500"
                                        >AWS session token</span
                                    >
                                    <input
                                        v-model="form.aws_session_token"
                                        autocomplete="off"
                                        placeholder="Optional STS token"
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 font-mono text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                    />
                                </label>
                            </div>

                            <div
                                v-else-if="isCloudflareMode"
                                class="grid gap-3"
                            >
                                <label class="grid max-w-xl gap-1.5">
                                    <span class="text-zinc-500"
                                        >Cloudflare API token</span
                                    >
                                    <input
                                        v-model="form.cloudflare_api_token"
                                        autocomplete="new-password"
                                        type="password"
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                    />
                                    <span class="text-xs text-zinc-500"
                                        >That's the only credential needed — the
                                        account and zones are detected from
                                        it.</span
                                    >
                                    <span
                                        v-if="form.errors.cloudflare_api_token"
                                        class="text-xs text-red-500"
                                        >{{
                                            form.errors.cloudflare_api_token
                                        }}</span
                                    >
                                </label>
                                <div
                                    class="rounded-lg border border-teal-200 bg-teal-50 p-3 text-sm text-teal-950 dark:border-teal-400/20 dark:bg-teal-400/10 dark:text-teal-100"
                                >
                                    Requires the Workers Paid plan and a domain
                                    on Cloudflare DNS.
                                    <a
                                        :href="cloudflareTokenUrl"
                                        target="_blank"
                                        rel="noopener"
                                        class="font-semibold underline"
                                        >Create a token</a
                                    >
                                    with Email Sending Edit, Zone Read, and DNS
                                    Edit — Larasend then onboards your sending
                                    domain in Cloudflare automatically.
                                </div>
                            </div>

                            <div
                                v-else-if="
                                    form.credential_mode === 'instance_role'
                                "
                                class="rounded-lg border border-teal-200 bg-teal-50 p-3 text-sm text-teal-950 dark:border-teal-400/20 dark:bg-teal-400/10 dark:text-teal-100"
                            >
                                Attach an IAM role with SES permissions to the
                                compute host that runs Larasend. No AWS keys
                                will be stored in the database for this source.
                            </div>

                            <div
                                v-if="credentialCheck && !credentialCheck.ok"
                                class="grid gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-950 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100"
                            >
                                <div class="font-semibold">
                                    Larasend checked these credentials and found
                                    a blocker:
                                </div>
                                <div
                                    v-for="blocker in credentialCheck.blockers"
                                    :key="blocker.code"
                                >
                                    {{ blocker.message }}
                                </div>
                            </div>
                            <div
                                v-else-if="
                                    credentialCheck?.warnings.length &&
                                    credentialCheck.ok
                                "
                                class="grid gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
                            >
                                <div
                                    v-for="warning in credentialCheck.warnings"
                                    :key="warning.code"
                                >
                                    {{ warning.message }}
                                </div>
                            </div>
                            <div
                                v-if="validationFailed"
                                class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-950 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100"
                            >
                                Could not run the credential check. Try again,
                                or choose "Configure later" and finish from the
                                setup guide.
                            </div>

                            <details
                                class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-800"
                            >
                                <summary
                                    class="cursor-pointer text-sm font-semibold text-zinc-600 dark:text-zinc-400"
                                >
                                    Workspace & project names (optional)
                                </summary>
                                <div class="mt-3 grid gap-3 md:grid-cols-3">
                                    <label class="grid gap-1.5">
                                        <span class="text-zinc-500"
                                            >Workspace</span
                                        >
                                        <input
                                            v-model="form.workspace_name"
                                            class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                        />
                                    </label>
                                    <label class="grid gap-1.5">
                                        <span class="text-zinc-500"
                                            >Project</span
                                        >
                                        <input
                                            v-model="form.project_name"
                                            class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                        />
                                    </label>
                                    <label class="grid gap-1.5">
                                        <span class="text-zinc-500"
                                            >Project slug</span
                                        >
                                        <input
                                            v-model="form.project_slug"
                                            class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 font-mono text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                        />
                                        <span
                                            v-if="form.errors.project_slug"
                                            class="text-xs text-red-500"
                                            >{{
                                                form.errors.project_slug
                                            }}</span
                                        >
                                    </label>
                                </div>
                            </details>
                        </div>

                        <div
                            v-else-if="currentStep.key === 'domain'"
                            class="grid gap-4"
                        >
                            <div
                                v-if="isCloudflareMode && availableZones.length"
                                class="grid gap-3 md:grid-cols-[1fr_auto_1.4fr]"
                            >
                                <label class="grid gap-1.5">
                                    <span class="text-zinc-500"
                                        >Subdomain (optional)</span
                                    >
                                    <input
                                        v-model="subdomainLabel"
                                        placeholder="mail"
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 font-mono text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                    />
                                </label>
                                <div
                                    class="hidden items-end pb-2.5 font-mono text-zinc-400 md:flex"
                                >
                                    .
                                </div>
                                <label class="grid gap-1.5">
                                    <span class="text-zinc-500"
                                        >Zone on your Cloudflare account</span
                                    >
                                    <select
                                        v-model="selectedZoneName"
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 font-mono text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                    >
                                        <option
                                            v-for="zone in availableZones"
                                            :key="zone.id"
                                            :value="zone.name"
                                        >
                                            {{ zone.name }}
                                        </option>
                                    </select>
                                </label>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2">
                                <label class="grid gap-1.5">
                                    <span class="text-zinc-500"
                                        >Default from email</span
                                    >
                                    <input
                                        v-model="form.default_from_email"
                                        type="email"
                                        placeholder="notifications@mail.example.com"
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                    />
                                    <span
                                        v-if="form.errors.default_from_email"
                                        class="text-xs text-red-500"
                                        >{{
                                            form.errors.default_from_email
                                        }}</span
                                    >
                                </label>
                                <label class="grid gap-1.5">
                                    <span class="text-zinc-500"
                                        >Default from name</span
                                    >
                                    <input
                                        v-model="form.default_from_name"
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                    />
                                </label>
                            </div>

                            <div class="grid gap-3 md:grid-cols-[1fr_1fr]">
                                <label
                                    v-if="
                                        !(
                                            isCloudflareMode &&
                                            availableZones.length
                                        )
                                    "
                                    class="grid gap-1.5"
                                >
                                    <span class="text-zinc-500"
                                        >Sending domain</span
                                    >
                                    <input
                                        v-model="form.sending_domain"
                                        placeholder="mail.example.com"
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 font-mono text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                    />
                                    <span
                                        v-if="form.errors.sending_domain"
                                        class="text-xs text-red-500"
                                        >{{ form.errors.sending_domain }}</span
                                    >
                                </label>
                                <div v-else class="grid content-start gap-1.5">
                                    <span class="text-zinc-500"
                                        >Sending domain</span
                                    >
                                    <div
                                        class="flex h-9 items-center rounded-md border border-zinc-200 bg-zinc-50 px-2.5 font-mono text-[13px] text-zinc-700 dark:border-zinc-800 dark:bg-[#0b0c0d] dark:text-zinc-300"
                                    >
                                        {{ form.sending_domain || '—' }}
                                    </div>
                                    <span
                                        v-if="form.errors.sending_domain"
                                        class="text-xs text-red-500"
                                        >{{ form.errors.sending_domain }}</span
                                    >
                                </div>
                                <label
                                    v-if="!isCloudflareMode"
                                    class="grid gap-1.5"
                                >
                                    <span class="text-zinc-500"
                                        >SES configuration set</span
                                    >
                                    <input
                                        v-model="form.ses_configuration_set"
                                        placeholder="Optional"
                                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 font-mono text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                    />
                                </label>
                            </div>

                            <div
                                v-if="dnsRows.length"
                                class="overflow-x-auto rounded-md border border-zinc-200 dark:border-[#1d2125]"
                            >
                                <div
                                    class="grid min-w-[760px] grid-cols-[72px_minmax(240px,1fr)_38px_minmax(240px,1.15fr)_38px] bg-zinc-50 px-3 py-2 font-mono text-[10.5px] tracking-widest text-zinc-500 uppercase dark:bg-[#0b0c0d]"
                                >
                                    <div>Type</div>
                                    <div>Host</div>
                                    <div></div>
                                    <div>Value</div>
                                    <div></div>
                                </div>
                                <div
                                    v-for="(record, index) in dnsRows"
                                    :key="`${record.name}-${index}`"
                                    class="grid min-w-[760px] grid-cols-[72px_minmax(240px,1fr)_38px_minmax(240px,1.15fr)_38px] items-center border-t border-zinc-200 px-3 py-2 dark:border-[#1d2125]"
                                >
                                    <div class="font-mono">
                                        {{ record.type }}
                                    </div>
                                    <div class="truncate font-mono text-xs">
                                        {{ record.name }}
                                    </div>
                                    <button
                                        type="button"
                                        class="rounded p-1.5 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-950 active:translate-y-px dark:hover:bg-zinc-900 dark:hover:text-zinc-100"
                                        aria-label="Copy DNS host"
                                        @click="
                                            copy(
                                                record.name,
                                                `dns-name-${index}`,
                                            )
                                        "
                                    >
                                        <Check
                                            v-if="
                                                copied === `dns-name-${index}`
                                            "
                                            class="size-3.5"
                                        />
                                        <Copy v-else class="size-3.5" />
                                    </button>
                                    <div
                                        class="truncate font-mono text-xs text-zinc-500"
                                    >
                                        {{ record.value }}
                                    </div>
                                    <button
                                        type="button"
                                        class="rounded p-1.5 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-950 active:translate-y-px dark:hover:bg-zinc-900 dark:hover:text-zinc-100"
                                        aria-label="Copy DNS value"
                                        @click="
                                            copy(
                                                record.value,
                                                `dns-value-${index}`,
                                            )
                                        "
                                    >
                                        <Check
                                            v-if="
                                                copied === `dns-value-${index}`
                                            "
                                            class="size-3.5"
                                        />
                                        <Copy v-else class="size-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-else class="grid gap-4">
                            <div
                                class="grid gap-4 rounded-lg border border-zinc-200 p-4 md:grid-cols-[1fr_1.2fr] dark:border-zinc-800"
                            >
                                <div class="grid min-w-0 content-start gap-2">
                                    <div class="flex items-center gap-2.5">
                                        <KeyRound
                                            class="size-4 text-teal-500"
                                        />
                                        <h3 class="font-semibold">
                                            Your first API key
                                        </h3>
                                    </div>
                                    <p class="text-sm text-zinc-500">
                                        Saving creates a "Production key"
                                        automatically and shows it once on the
                                        next screen. Use it with this
                                        environment config in the Laravel app
                                        that sends mail:
                                    </p>
                                </div>
                                <div
                                    class="rounded-md border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-[#0b0c0d]"
                                >
                                    <div class="mb-2 flex items-center gap-2">
                                        <Send class="size-4 text-teal-500" />
                                        <span class="font-semibold"
                                            >Laravel env</span
                                        >
                                    </div>
                                    <div class="flex items-start gap-2">
                                        <code
                                            class="min-w-0 flex-1 font-mono text-[11.5px] leading-5 whitespace-pre-wrap"
                                            >{{ codeSnippet }}</code
                                        >
                                        <button
                                            type="button"
                                            class="rounded p-1.5 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-950 active:translate-y-px dark:hover:bg-zinc-900 dark:hover:text-zinc-100"
                                            aria-label="Copy Laravel environment snippet"
                                            @click="
                                                copy(codeSnippet, 'env-snippet')
                                            "
                                        >
                                            <Check
                                                v-if="copied === 'env-snippet'"
                                                class="size-3.5"
                                            />
                                            <Copy v-else class="size-3.5" />
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div
                                    class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
                                >
                                    <div class="flex items-center gap-2.5">
                                        <MailCheck
                                            class="size-4 text-teal-500"
                                        />
                                        <h3 class="font-semibold">
                                            What happens next
                                        </h3>
                                    </div>
                                    <div
                                        class="mt-3 grid gap-2 text-sm text-zinc-500"
                                    >
                                        <div>
                                            1. Publish DKIM records for the
                                            domain.
                                        </div>
                                        <div>
                                            2. Sync quota and confirm source
                                            health.
                                        </div>
                                        <div>
                                            3.
                                            {{
                                                isCloudflareMode
                                                    ? 'Suppressions sync hourly from Cloudflare.'
                                                    : 'Connect SES event publishing to Larasend.'
                                            }}
                                        </div>
                                        <div>4. Send one real test email.</div>
                                    </div>
                                </div>
                                <div
                                    v-if="!isCloudflareMode"
                                    class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
                                >
                                    <div class="flex items-center gap-2.5">
                                        <Webhook class="size-4 text-teal-500" />
                                        <h3 class="font-semibold">
                                            SES/SNS source URL
                                        </h3>
                                    </div>
                                    <div
                                        class="mt-3 flex min-w-0 items-center gap-2"
                                    >
                                        <code
                                            class="min-w-0 flex-1 truncate font-mono text-xs"
                                        >
                                            {{
                                                source?.webhook_url ||
                                                'Created after source save'
                                            }}
                                        </code>
                                        <button
                                            v-if="source?.webhook_url"
                                            type="button"
                                            class="rounded p-1.5 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-950 active:translate-y-px dark:hover:bg-zinc-900 dark:hover:text-zinc-100"
                                            aria-label="Copy SES webhook URL"
                                            @click="
                                                copy(
                                                    source.webhook_url,
                                                    'ses-webhook',
                                                )
                                            "
                                        >
                                            <Check
                                                v-if="copied === 'ses-webhook'"
                                                class="size-3.5"
                                            />
                                            <Copy v-else class="size-3.5" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <div
                    class="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-[#fbfaf7]/90 py-3 backdrop-blur dark:border-[#1d2125] dark:bg-[#090a0a]/90"
                >
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-md border border-zinc-200 px-3 py-2 text-[13px] font-semibold text-zinc-600 transition hover:bg-zinc-100 active:translate-y-px disabled:cursor-not-allowed disabled:opacity-50 dark:border-[#1d2125] dark:text-zinc-300 dark:hover:bg-[#16191c]"
                        :disabled="isFirstStep"
                        @click="goBack"
                    >
                        <ArrowLeft class="size-4" />
                        Back
                    </button>
                    <div class="flex items-center gap-2">
                        <button
                            v-if="!isLastStep"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-md bg-teal-300 px-3 py-2 text-[13px] font-bold text-zinc-950 transition hover:brightness-105 active:translate-y-px disabled:cursor-wait disabled:opacity-60"
                            :disabled="validationHttp.processing"
                            @click="goNext"
                        >
                            {{
                                validationHttp.processing
                                    ? 'Checking credentials...'
                                    : currentStep.key === 'connect' &&
                                        !skipsValidation
                                      ? 'Verify & continue'
                                      : 'Continue'
                            }}
                            <ArrowRight class="size-4" />
                        </button>
                        <button
                            v-else
                            type="submit"
                            class="inline-flex items-center gap-2 rounded-md bg-teal-300 px-3 py-2 text-[13px] font-bold text-zinc-950 transition hover:brightness-105 active:translate-y-px disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="form.processing"
                        >
                            <span>{{
                                form.processing
                                    ? 'Saving...'
                                    : 'Save and continue to setup'
                            }}</span>
                            <ArrowRight class="size-4" />
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>
    <Toaster />
</template>
