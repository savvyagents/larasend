<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Check, ExternalLink, RefreshCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import {
    syncSourceQuota,
    updateSource,
} from '@/actions/App/Http/Controllers/DashboardActionController';

type SourceProvider = 'ses' | 'cloudflare';

const props = defineProps<{
    projectSlug: string;
    projectPath: string;
    canManage: boolean;
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
    quota: {
        sent: number;
        limit: number | null;
        rate: number | null;
        sentLast24Hours: number | null;
        checkedAt: string | null;
    };
    verifiedDomain: string | null;
    domainCount: number;
    webhookUrl: string | null;
}>();

const syncingQuota = ref(false);
const showInstructions = ref(false);
const form = useForm({
    name: props.source?.name ?? 'Production',
    environment: props.source?.environment ?? 'prod',
    provider: (props.source?.provider ?? 'ses') as SourceProvider,
    ses_region: props.source?.ses_region ?? 'us-east-1',
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

const isCloudflare = computed(() => form.provider === 'cloudflare');
const credentialsConfigured = computed(() =>
    isCloudflare.value
        ? Boolean(props.source?.has_cloudflare_credentials)
        : Boolean(
              props.source?.has_aws_credentials ||
              props.source?.uses_instance_role,
          ),
);
const providerLabel = computed(() =>
    isCloudflare.value ? 'Cloudflare Email Sending' : 'Amazon SES',
);
const connectionReady = computed(
    () =>
        credentialsConfigured.value &&
        Boolean(props.source?.default_from_email),
);
const quotaSummary = computed(() => {
    if (!props.quota.limit) {
        return 'Not synchronized';
    }

    const used = props.quota.sentLast24Hours ?? 0;

    return `${used.toLocaleString()} of ${props.quota.limit.toLocaleString()} used`;
});
const quotaCheckedAt = computed(() => {
    if (!props.quota.checkedAt) {
        return 'sync required';
    }

    const checkedAt = new Date(props.quota.checkedAt);

    return Number.isNaN(checkedAt.getTime())
        ? 'previously synchronized'
        : `last checked ${checkedAt.toLocaleString([], {
              dateStyle: 'medium',
              timeStyle: 'short',
          })}`;
});
const cloudflareTokenUrl = computed(() => {
    const permissions = encodeURIComponent(
        JSON.stringify([
            { key: 'email_sending', type: 'edit' },
            { key: 'zone', type: 'read' },
            { key: 'dns', type: 'edit' },
        ]),
    );

    return `https://dash.cloudflare.com/?to=/:account/api-tokens&permissionGroupKeys=${permissions}&name=Larasend%20Email%20Sending`;
});

function save(): void {
    form.put(
        updateSource['/projects/{project}/source'].url(props.projectSlug),
        { preserveScroll: true },
    );
}

function syncQuota(): void {
    syncingQuota.value = true;
    router.post(
        syncSourceQuota.url(props.projectSlug),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                syncingQuota.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="grid max-w-6xl gap-4 xl:grid-cols-[minmax(0,1fr)_300px]">
        <form
            class="overflow-hidden rounded-xl border border-zinc-200 bg-white font-sans dark:border-[#25292d] dark:bg-[#111315]"
            @submit.prevent="save"
        >
            <div
                class="flex flex-wrap items-start justify-between gap-4 border-b border-zinc-200 p-5 dark:border-[#25292d]"
            >
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold">
                            {{ providerLabel }}
                        </h2>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 font-mono text-[10px] font-semibold uppercase"
                            :class="
                                connectionReady
                                    ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                    : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                            "
                        >
                            <Check v-if="connectionReady" class="size-3" />
                            <AlertTriangle v-else class="size-3" />
                            {{
                                connectionReady
                                    ? 'Connected'
                                    : 'Needs attention'
                            }}
                        </span>
                    </div>
                    <p class="mt-1 max-w-2xl text-sm text-zinc-500">
                        Manage the provider Larasend uses to send transactional
                        email.
                    </p>
                </div>
                <button
                    v-if="canManage"
                    type="submit"
                    class="rounded-lg bg-teal-300 px-4 py-2 text-sm font-semibold text-[#07221c] transition hover:brightness-105 disabled:cursor-wait disabled:opacity-60"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving...' : 'Save changes' }}
                </button>
            </div>

            <div class="grid gap-6 p-5">
                <section class="grid gap-4">
                    <div>
                        <h3 class="font-semibold">Connection</h3>
                        <p class="mt-1 text-sm text-zinc-500">
                            Choose the sending provider and identify this
                            connection.
                        </p>
                    </div>
                    <div class="grid gap-4 md:grid-cols-3">
                        <label class="grid gap-2 text-sm">
                            <span class="text-zinc-500">Connection name</span>
                            <input
                                v-model="form.name"
                                :disabled="!canManage"
                                class="rounded-lg border border-zinc-200 bg-white px-3 py-2.5 disabled:opacity-60 dark:border-zinc-700 dark:bg-[#0b0c0d]"
                                required
                            />
                            <span
                                v-if="form.errors.name"
                                class="text-xs text-red-500"
                                >{{ form.errors.name }}</span
                            >
                        </label>
                        <label class="grid gap-2 text-sm">
                            <span class="text-zinc-500">Environment</span>
                            <select
                                v-model="form.environment"
                                :disabled="!canManage"
                                class="rounded-lg border border-zinc-200 bg-white px-3 py-2.5 disabled:opacity-60 dark:border-zinc-700 dark:bg-[#0b0c0d]"
                            >
                                <option value="prod">Production</option>
                                <option value="sandbox">Sandbox</option>
                            </select>
                        </label>
                        <label class="grid gap-2 text-sm">
                            <span class="text-zinc-500">Provider</span>
                            <select
                                v-model="form.provider"
                                :disabled="!canManage"
                                class="rounded-lg border border-zinc-200 bg-white px-3 py-2.5 disabled:opacity-60 dark:border-zinc-700 dark:bg-[#0b0c0d]"
                            >
                                <option value="cloudflare">
                                    Cloudflare Email Sending
                                </option>
                                <option value="ses">Amazon SES</option>
                            </select>
                        </label>
                    </div>
                </section>

                <section
                    class="grid gap-4 border-t border-zinc-200 pt-6 dark:border-[#25292d]"
                >
                    <div>
                        <h3 class="font-semibold">Default sender</h3>
                        <p class="mt-1 text-sm text-zinc-500">
                            Used when an API request does not provide a From
                            address.
                        </p>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="grid gap-2 text-sm">
                            <span class="text-zinc-500">Sender name</span>
                            <input
                                v-model="form.default_from_name"
                                :disabled="!canManage"
                                class="rounded-lg border border-zinc-200 bg-white px-3 py-2.5 disabled:opacity-60 dark:border-zinc-700 dark:bg-[#0b0c0d]"
                            />
                        </label>
                        <label class="grid gap-2 text-sm">
                            <span class="text-zinc-500">Sender email</span>
                            <input
                                v-model="form.default_from_email"
                                type="email"
                                :disabled="!canManage"
                                class="rounded-lg border border-zinc-200 bg-white px-3 py-2.5 disabled:opacity-60 dark:border-zinc-700 dark:bg-[#0b0c0d]"
                                placeholder="mail@example.com"
                                required
                            />
                            <span
                                v-if="form.errors.default_from_email"
                                class="text-xs text-red-500"
                                >{{ form.errors.default_from_email }}</span
                            >
                        </label>
                    </div>
                </section>

                <section
                    class="grid gap-4 border-t border-zinc-200 pt-6 dark:border-[#25292d]"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h3 class="font-semibold">Credentials</h3>
                            <p class="mt-1 text-sm text-zinc-500">
                                Blank secret fields keep the currently saved
                                credentials.
                            </p>
                        </div>
                        <a
                            v-if="isCloudflare"
                            :href="cloudflareTokenUrl"
                            target="_blank"
                            rel="noreferrer"
                            class="inline-flex items-center gap-1.5 text-sm font-semibold text-teal-700 hover:underline dark:text-teal-300"
                        >
                            Create API token <ExternalLink class="size-3.5" />
                        </a>
                    </div>

                    <div v-if="isCloudflare" class="grid gap-4 md:grid-cols-2">
                        <label class="grid gap-2 text-sm">
                            <span class="text-zinc-500"
                                >Cloudflare account ID</span
                            >
                            <input
                                v-model="form.cloudflare_account_id"
                                :disabled="!canManage"
                                class="rounded-lg border border-zinc-200 bg-white px-3 py-2.5 disabled:opacity-60 dark:border-zinc-700 dark:bg-[#0b0c0d]"
                                placeholder="Detected from the API token when possible"
                            />
                        </label>
                        <label class="grid gap-2 text-sm">
                            <span class="text-zinc-500">API token</span>
                            <input
                                v-model="form.cloudflare_api_token"
                                type="password"
                                autocomplete="new-password"
                                :disabled="!canManage"
                                class="rounded-lg border border-zinc-200 bg-white px-3 py-2.5 disabled:opacity-60 dark:border-zinc-700 dark:bg-[#0b0c0d]"
                                :placeholder="
                                    source?.has_cloudflare_credentials
                                        ? 'Saved — enter a new token to replace it'
                                        : 'Cloudflare API token'
                                "
                            />
                            <span
                                v-if="form.errors.cloudflare_api_token"
                                class="text-xs text-red-500"
                                >{{ form.errors.cloudflare_api_token }}</span
                            >
                        </label>
                    </div>

                    <div v-else class="grid gap-4 md:grid-cols-2">
                        <label class="grid gap-2 text-sm">
                            <span class="text-zinc-500">AWS region</span>
                            <input
                                v-model="form.ses_region"
                                :disabled="!canManage"
                                class="rounded-lg border border-zinc-200 bg-white px-3 py-2.5 disabled:opacity-60 dark:border-zinc-700 dark:bg-[#0b0c0d]"
                                required
                            />
                        </label>
                        <label class="grid gap-2 text-sm">
                            <span class="text-zinc-500">Configuration set</span>
                            <input
                                v-model="form.ses_configuration_set"
                                :disabled="!canManage"
                                class="rounded-lg border border-zinc-200 bg-white px-3 py-2.5 disabled:opacity-60 dark:border-zinc-700 dark:bg-[#0b0c0d]"
                                placeholder="Optional"
                            />
                        </label>
                        <label class="grid gap-2 text-sm">
                            <span class="text-zinc-500">AWS access key ID</span>
                            <input
                                v-model="form.aws_access_key_id"
                                autocomplete="off"
                                :disabled="!canManage"
                                class="rounded-lg border border-zinc-200 bg-white px-3 py-2.5 disabled:opacity-60 dark:border-zinc-700 dark:bg-[#0b0c0d]"
                                :placeholder="
                                    source?.has_aws_credentials
                                        ? 'Saved — enter a new key to replace it'
                                        : source?.uses_instance_role
                                          ? 'Optional instance-role override'
                                          : 'AWS access key ID'
                                "
                            />
                            <span
                                v-if="form.errors.aws_access_key_id"
                                class="text-xs text-red-500"
                                >{{ form.errors.aws_access_key_id }}</span
                            >
                        </label>
                        <label class="grid gap-2 text-sm">
                            <span class="text-zinc-500"
                                >AWS secret access key</span
                            >
                            <input
                                v-model="form.aws_secret_access_key"
                                type="password"
                                autocomplete="new-password"
                                :disabled="!canManage"
                                class="rounded-lg border border-zinc-200 bg-white px-3 py-2.5 disabled:opacity-60 dark:border-zinc-700 dark:bg-[#0b0c0d]"
                                placeholder="Leave blank to keep saved value"
                            />
                        </label>
                        <label class="grid gap-2 text-sm md:col-span-2">
                            <span class="text-zinc-500">AWS session token</span>
                            <input
                                v-model="form.aws_session_token"
                                autocomplete="off"
                                :disabled="!canManage"
                                class="rounded-lg border border-zinc-200 bg-white px-3 py-2.5 disabled:opacity-60 dark:border-zinc-700 dark:bg-[#0b0c0d]"
                                placeholder="Optional STS token"
                            />
                        </label>
                    </div>
                </section>

                <div
                    v-if="canManage"
                    class="flex justify-end border-t border-zinc-200 pt-5 dark:border-[#25292d]"
                >
                    <button
                        type="submit"
                        class="rounded-lg bg-teal-300 px-4 py-2 text-sm font-semibold text-[#07221c] transition hover:brightness-105 disabled:cursor-wait disabled:opacity-60"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Saving...' : 'Save changes' }}
                    </button>
                </div>
            </div>
        </form>

        <aside class="grid content-start gap-4 font-sans">
            <section
                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-[#25292d] dark:bg-[#111315]"
            >
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-semibold">Sending quota</h2>
                    <button
                        v-if="canManage"
                        type="button"
                        class="grid size-8 place-items-center rounded-lg border border-zinc-200 text-zinc-500 transition hover:text-zinc-950 disabled:cursor-wait disabled:opacity-60 dark:border-zinc-700 dark:hover:text-zinc-100"
                        :disabled="syncingQuota"
                        title="Refresh quota"
                        @click="syncQuota"
                    >
                        <RefreshCw
                            class="size-4"
                            :class="{ 'animate-spin': syncingQuota }"
                        />
                    </button>
                </div>
                <p class="mt-3 text-xl font-semibold">{{ quotaSummary }}</p>
                <p class="mt-1 text-xs text-zinc-500">
                    <template v-if="quota.rate"
                        >{{ quota.rate }}/second ·
                    </template>
                    {{ quotaCheckedAt }}
                </p>
            </section>

            <section
                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-[#25292d] dark:bg-[#111315]"
            >
                <h2 class="font-semibold">Sending domain</h2>
                <p class="mt-2 text-sm text-zinc-500">
                    {{
                        verifiedDomain ??
                        `${domainCount} configured, none verified`
                    }}
                </p>
                <Link
                    :href="`${projectPath}/identities`"
                    class="mt-3 inline-flex text-sm font-semibold text-teal-700 hover:underline dark:text-teal-300"
                >
                    Manage domains
                </Link>
            </section>

            <section
                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-[#25292d] dark:bg-[#111315]"
            >
                <h2 class="font-semibold">Provider events</h2>
                <p class="mt-2 text-sm text-zinc-500">
                    <template v-if="isCloudflare">
                        Suppressions synchronize automatically; Cloudflare does
                        not provide delivery event webhooks.
                    </template>
                    <template v-else>
                        Connect SES and SNS events to keep delivery timelines
                        current.
                    </template>
                </p>
                <Link
                    v-if="!isCloudflare"
                    :href="`${projectPath}/webhooks`"
                    class="mt-3 inline-flex text-sm font-semibold text-teal-700 hover:underline dark:text-teal-300"
                >
                    Manage webhooks
                </Link>
            </section>

            <section
                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-[#25292d] dark:bg-[#111315]"
            >
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 text-left font-semibold"
                    @click="showInstructions = !showInstructions"
                >
                    Setup instructions
                    <span class="text-xs text-zinc-500">{{
                        showInstructions ? 'Hide' : 'Show'
                    }}</span>
                </button>
                <div
                    v-if="showInstructions"
                    class="mt-3 grid gap-2 text-sm leading-6 text-zinc-500"
                >
                    <template v-if="isCloudflare">
                        <p>
                            Create a token with Email Sending Edit, Zone Read,
                            and DNS Edit.
                        </p>
                        <p>
                            Save the connection, then add and verify a sending
                            domain.
                        </p>
                    </template>
                    <template v-else>
                        <p>
                            Use an IAM principal with SES sending, identity,
                            quota, and account permissions.
                        </p>
                        <p
                            v-if="webhookUrl"
                            class="font-mono text-xs break-all"
                        >
                            {{ webhookUrl }}
                        </p>
                    </template>
                </div>
            </section>
        </aside>
    </div>
</template>
