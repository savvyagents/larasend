<script setup lang="ts">
import { Head, router, useForm, usePoll } from '@inertiajs/vue3';
import {
    AlarmClock,
    Archive,
    ArchiveRestore,
    AtSign,
    Forward,
    Inbox as InboxIcon,
    Mail,
    MailOpen,
    Paperclip,
    PenSquare,
    Reply,
    Search,
    Send,
    StickyNote,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import GlobalRail from '@/components/GlobalRail.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import { Toaster } from '@/components/ui/sonner';

type ThreadRow = {
    public_id: string;
    subject: string | null;
    participants: string[];
    snippet: string | null;
    direction: 'inbound' | 'outbound';
    message_count: number;
    unread: boolean;
    archived: boolean;
    snoozed: boolean;
    snoozed_until_human: string | null;
    last_activity_at: string | null;
    last_activity_human: string | null;
};

type Message = {
    id: string;
    direction: 'inbound' | 'outbound' | 'note';
    from: string;
    from_email: string;
    to: string;
    subject: string | null;
    text: string | null;
    html: string | null;
    attachments: {
        filename: string | null;
        content_type: string | null;
        size: number;
    }[];
    status: string | null;
    at: string | null;
    at_human: string | null;
};

const props = defineProps<{
    project: {
        name: string;
        slug: string;
        path: string;
        dashboard_path: string;
    };
    canSend: boolean;
    mailbox: string;
    address: string | null;
    filters: { q: string };
    addresses: { address: string; count: number }[];
    counts: {
        inbox: number;
        unread: number;
        snoozed: number;
        archived: number;
    };
    threads: ThreadRow[];
    selectedThread:
        | (ThreadRow & { messages: Message[]; reply_from: string | null })
        | null;
}>();

const searchQuery = ref(props.filters.q);
const searchInput = ref<HTMLInputElement | null>(null);
let searchTimer: ReturnType<typeof window.setTimeout> | null = null;

const inboxPath = computed(() => `${props.project.path}/inbox`);

const mailboxes = computed(() => [
    {
        key: 'inbox',
        label: 'Inbox',
        icon: InboxIcon,
        count: props.counts.unread,
    },
    { key: 'unread', label: 'Unread', icon: Mail, count: null },
    {
        key: 'snoozed',
        label: 'Snoozed',
        icon: AlarmClock,
        count: props.counts.snoozed || null,
    },
    {
        key: 'archived',
        label: 'Archived',
        icon: Archive,
        count: null,
    },
]);

const pageTitle = computed(() =>
    props.counts.unread
        ? `(${props.counts.unread}) Inbox · ${props.project.name}`
        : `Inbox · ${props.project.name}`,
);

function visitInbox(params: Record<string, string | null>): void {
    const query: Record<string, string> = {};
    const merged = {
        mailbox: props.mailbox,
        address: props.address,
        q: searchQuery.value,
        thread: props.selectedThread?.public_id ?? null,
        ...params,
    };

    for (const [key, value] of Object.entries(merged)) {
        if (value) {
            query[key] = value;
        }
    }

    router.get(inboxPath.value, query, {
        preserveState: true,
        preserveScroll: true,
        showProgress: false,
    });
}

function openMailbox(key: string): void {
    visitInbox({ mailbox: key, thread: null });
}

function filterAddress(address: string): void {
    visitInbox({
        address: props.address === address ? null : address,
        thread: null,
    });
}

function openThread(publicId: string): void {
    visitInbox({ thread: publicId });
}

function onSearchInput(): void {
    if (searchTimer) {
        window.clearTimeout(searchTimer);
    }

    searchTimer = window.setTimeout(() => visitInbox({ thread: null }), 350);
}

function threadAction(action: string): void {
    if (!props.selectedThread) {
        return;
    }

    router.post(
        `${props.project.path}/threads/${props.selectedThread.public_id}/${action}`,
        {},
        { preserveState: false, preserveScroll: true, showProgress: false },
    );
}

const selectedIndex = computed(() =>
    props.threads.findIndex(
        (thread) => thread.public_id === props.selectedThread?.public_id,
    ),
);

function moveSelection(step: number): void {
    const next = props.threads[selectedIndex.value + step];

    if (next) {
        openThread(next.public_id);
    }
}

function onKeydown(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;

    if (
        target &&
        (target.tagName === 'INPUT' ||
            target.tagName === 'TEXTAREA' ||
            target.isContentEditable)
    ) {
        if (event.key === 'Escape') {
            (target as HTMLInputElement).blur();
        }

        return;
    }

    if (event.key === 'Escape' && showShortcuts.value) {
        showShortcuts.value = false;

        return;
    }

    switch (event.key) {
        case '?':
            event.preventDefault();
            showShortcuts.value = !showShortcuts.value;
            break;
        case 'j':
            moveSelection(1);
            break;
        case 'k':
            moveSelection(-1);
            break;
        case 'e':
            threadAction(
                props.selectedThread?.archived ? 'unarchive' : 'archive',
            );
            break;
        case 'u':
            threadAction(props.selectedThread?.unread ? 'read' : 'unread');
            break;
        case 'r':
            event.preventDefault();
            replyEditor.value?.focus();
            break;
        case 'c':
            event.preventDefault();
            showCompose.value = true;
            break;
        case 'f':
            if (props.canSend && props.selectedThread) {
                event.preventDefault();
                showForward.value = true;
            }

            break;
        case '/':
            event.preventDefault();
            searchInput.value?.focus();
            break;
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

usePoll(
    10000,
    { only: ['threads', 'counts'], showProgress: false },
    { autoStart: true },
);

const replyForm = useForm({ text: '', html: '', attachments: [] as File[] });
const replyEditor = ref<InstanceType<typeof RichTextEditor> | null>(null);

function sendReply(): void {
    if (!props.selectedThread || !replyForm.text.trim()) {
        return;
    }

    const thread = props.selectedThread.public_id;

    replyForm.post(`${props.project.path}/threads/${thread}/reply`, {
        preserveScroll: true,
        onSuccess: () => {
            replyForm.reset();
            localStorage.removeItem(draftKey(thread));
        },
    });
}

const showShortcuts = ref(false);
const shortcuts = [
    ['j / k', 'Next / previous conversation'],
    ['e', 'Archive or restore'],
    ['u', 'Toggle read state'],
    ['r', 'Focus the reply box'],
    ['f', 'Forward the conversation'],
    ['c', 'Compose a new conversation'],
    ['/', 'Search conversations'],
    ['⌘↵', 'Send while writing'],
    ['?', 'Show these shortcuts'],
];

const showCompose = ref(false);
const composeForm = useForm({
    from: '',
    to: '',
    subject: '',
    text: '',
    html: '',
    attachments: [] as File[],
});

function sendCompose(): void {
    composeForm.post(`${props.project.path}/inbox/compose`, {
        onSuccess: () => {
            composeForm.reset();
            showCompose.value = false;
            localStorage.removeItem(draftKey('compose'));
        },
    });
}

const showForward = ref(false);
const forwardForm = useForm({ to: '', text: '' });

function sendForward(): void {
    if (!props.selectedThread) {
        return;
    }

    forwardForm.post(
        `${props.project.path}/threads/${props.selectedThread.public_id}/forward`,
        {
            preserveScroll: true,
            onSuccess: () => {
                forwardForm.reset();
                showForward.value = false;
            },
        },
    );
}

const showSnoozeMenu = ref(false);
const snoozeOptions = [
    { key: 'later_today', label: 'Later today (3h)' },
    { key: 'tomorrow', label: 'Tomorrow 9am' },
    { key: 'next_week', label: 'Next Monday 9am' },
];

function snoozeThread(until: string): void {
    showSnoozeMenu.value = false;

    if (!props.selectedThread) {
        return;
    }

    router.post(
        `${props.project.path}/threads/${props.selectedThread.public_id}/snooze`,
        { until },
        { preserveState: false, preserveScroll: true, showProgress: false },
    );
}

// Composer switches between an emailed reply and an internal team note.
const composerMode = ref<'reply' | 'note'>('reply');
const noteForm = useForm({ body: '' });

function sendNote(): void {
    if (!props.selectedThread || !noteForm.body.trim()) {
        return;
    }

    noteForm.post(
        `${props.project.path}/threads/${props.selectedThread.public_id}/notes`,
        {
            preserveScroll: true,
            onSuccess: () => noteForm.reset(),
        },
    );
}

function onNoteKeydown(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
        event.preventDefault();
        sendNote();
    }
}

// --- Attachments -----------------------------------------------------------

type AttachableForm = { attachments: File[] };

function pickFiles(form: AttachableForm): void {
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.onchange = () => {
        form.attachments = [
            ...form.attachments,
            ...Array.from(input.files ?? []),
        ].slice(0, 10);
    };
    input.click();
}

function removeFile(form: AttachableForm, index: number): void {
    form.attachments = form.attachments.filter((_, i) => i !== index);
}

function fileSize(file: File): string {
    return file.size >= 1024 * 1024
        ? `${(file.size / (1024 * 1024)).toFixed(1)} MB`
        : `${Math.max(1, Math.round(file.size / 1024))} KB`;
}

// --- Drafts ----------------------------------------------------------------
// Unsent reply and compose text survives navigation and reloads via
// localStorage, keyed per thread.

function draftKey(scope: string): string {
    return `larasend:draft:${props.project.slug}:${scope}`;
}

function loadDraft(scope: string): { text: string; html: string } | null {
    try {
        const raw = localStorage.getItem(draftKey(scope));

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function saveDraft(scope: string, data: { text: string; html: string }): void {
    if (data.text.trim()) {
        localStorage.setItem(draftKey(scope), JSON.stringify(data));
    } else {
        localStorage.removeItem(draftKey(scope));
    }
}

watch(
    () => props.selectedThread?.public_id,
    (thread) => {
        const draft = thread ? loadDraft(thread) : null;
        replyForm.text = draft?.text ?? '';
        replyForm.html = draft?.html ?? '';
        replyForm.attachments = [];
    },
    { immediate: true },
);

watch(
    () => [replyForm.text, replyForm.html],
    () => {
        if (props.selectedThread && !replyForm.processing) {
            saveDraft(props.selectedThread.public_id, {
                text: replyForm.text,
                html: replyForm.html,
            });
        }
    },
);

watch(showCompose, (open) => {
    if (open && !composeForm.text.trim()) {
        const draft = loadDraft('compose');
        composeForm.text = draft?.text ?? '';
        composeForm.html = draft?.html ?? '';
    }
});

watch(
    () => [composeForm.text, composeForm.html],
    () => {
        if (showCompose.value && !composeForm.processing) {
            saveDraft('compose', {
                text: composeForm.text,
                html: composeForm.html,
            });
        }
    },
);

// Message HTML renders inside a sandboxed iframe (no scripts). The wrapper
// document gives foreign markup native typography; the load handler sizes
// the frame to its content so short messages stay short.
function messageDocument(html: string): string {
    return `<!doctype html><html><head><meta charset="utf-8"><style>
body{margin:0;padding:1px;font:13px/1.6 ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#27272a;word-break:break-word;overflow-wrap:anywhere}
img{max-width:100%;height:auto}
p{margin:0 0 .6em}p:last-child{margin-bottom:0}
ul,ol{margin:0 0 .6em;padding-left:1.4em}
blockquote{margin:0 0 .6em;padding-left:.8em;border-left:2px solid #99f6e4;color:#71717a}
pre{white-space:pre-wrap;font:12px/1.6 ui-monospace,monospace}
a{color:#0d9488}
table{max-width:100%}
</style></head><body>${html}</body></html>`;
}

function fitMessageFrame(event: Event): void {
    const frame = event.target as HTMLIFrameElement;
    const doc = frame.contentDocument;

    if (!doc) {
        return;
    }

    const height = Math.max(
        doc.documentElement?.scrollHeight ?? 0,
        doc.body?.scrollHeight ?? 0,
    );

    frame.style.height = `${Math.min(Math.max(height + 6, 24), 640)}px`;
}

function statusBadgeClass(status: string | null): string {
    if (
        status === 'failed' ||
        status === 'bounced' ||
        status === 'complained'
    ) {
        return 'bg-red-400/90 text-zinc-950';
    }

    if (status === 'sent' || status === 'delivered') {
        return 'bg-teal-300/80 text-zinc-950';
    }

    return 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200';
}

function attachmentUrl(messageId: string, index: number): string {
    return `${props.project.path}/inbound/${messageId}/attachments/${index}`;
}

function participantSummary(thread: ThreadRow): string {
    const external = thread.participants.filter(
        (participant) =>
            !props.addresses.some((a) => a.address === participant),
    );

    return (external.length ? external : thread.participants)
        .map((participant) => participant.split('@')[0])
        .slice(0, 3)
        .join(', ');
}
</script>

<template>
    <Head :title="pageTitle" />

    <div
        class="grid h-screen grid-cols-[56px_minmax(0,1fr)] grid-rows-[52px_minmax(0,1fr)] bg-[#fbfaf7] font-sans text-[13px] text-zinc-950 dark:bg-[#090a0a] dark:text-zinc-100"
    >
        <GlobalRail
            class="row-span-2"
            :project-path="project.path"
            area="inbox"
            :inbox-unread="counts.unread"
        />
        <header
            class="col-start-2 flex items-center gap-3 border-b border-zinc-200 px-4 dark:border-[#1d2125]"
        >
            <div class="flex items-baseline gap-2">
                <span class="font-semibold">{{ project.name }}</span>
                <span class="font-mono text-[11px] text-zinc-500">Inbox</span>
            </div>
            <button
                v-if="canSend"
                type="button"
                class="ml-auto inline-flex items-center gap-2 rounded-md bg-teal-300 px-3 py-1.5 text-xs font-bold text-zinc-950 transition hover:brightness-105"
                @click="showCompose = true"
            >
                <PenSquare class="size-3.5" />
                Compose
            </button>
            <button
                type="button"
                title="Keyboard shortcuts (?)"
                class="grid size-8 place-items-center rounded-md border border-zinc-200 font-mono text-xs font-semibold text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-950 dark:border-[#1d2125] dark:text-zinc-400 dark:hover:bg-[#16191c] dark:hover:text-zinc-100"
                :class="canSend ? '' : 'ml-auto'"
                @click="showShortcuts = true"
            >
                ?
            </button>
        </header>

        <div
            class="col-start-2 grid min-h-0 grid-cols-[200px_360px_minmax(0,1fr)]"
        >
            <aside
                class="grid min-h-0 content-start gap-1 overflow-auto border-r border-zinc-200 p-3 dark:border-[#1d2125]"
            >
                <p
                    class="px-2 pb-1 font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                >
                    Mailboxes
                </p>
                <button
                    v-for="box in mailboxes"
                    :key="box.key"
                    type="button"
                    class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-left font-medium transition hover:bg-zinc-100 dark:hover:bg-[#16191c]"
                    :class="
                        mailbox === box.key && !address
                            ? 'bg-teal-50 text-zinc-950 dark:bg-teal-400/10 dark:text-zinc-100'
                            : 'text-zinc-600 dark:text-zinc-400'
                    "
                    @click="openMailbox(box.key)"
                >
                    <component :is="box.icon" class="size-3.5 shrink-0" />
                    <span class="flex-1">{{ box.label }}</span>
                    <span
                        v-if="box.count"
                        class="rounded-full bg-teal-300 px-1.5 font-mono text-[10.5px] font-semibold text-zinc-950"
                    >
                        {{ box.count }}
                    </span>
                </button>

                <p
                    v-if="addresses.length"
                    class="px-2 pt-4 pb-1 font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                >
                    Addresses
                </p>
                <button
                    v-for="entry in addresses"
                    :key="entry.address"
                    type="button"
                    class="flex min-w-0 items-center gap-2 rounded-md px-2 py-1.5 text-left transition hover:bg-zinc-100 dark:hover:bg-[#16191c]"
                    :class="
                        address === entry.address
                            ? 'bg-teal-50 text-zinc-950 dark:bg-teal-400/10 dark:text-zinc-100'
                            : 'text-zinc-600 dark:text-zinc-400'
                    "
                    @click="filterAddress(entry.address)"
                >
                    <AtSign class="size-3 shrink-0" />
                    <span
                        class="min-w-0 flex-1 truncate font-mono text-[11.5px]"
                    >
                        {{ entry.address.split('@')[0] }}
                    </span>
                    <span class="font-mono text-[10.5px] text-zinc-500">
                        {{ entry.count }}
                    </span>
                </button>
            </aside>

            <section
                class="grid min-h-0 grid-rows-[auto_minmax(0,1fr)] border-r border-zinc-200 dark:border-[#1d2125]"
            >
                <div
                    class="border-b border-zinc-200 p-2.5 dark:border-[#1d2125]"
                >
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-zinc-400"
                        />
                        <input
                            ref="searchInput"
                            v-model="searchQuery"
                            placeholder="Search conversations"
                            class="h-8 w-full rounded-md border border-zinc-200 bg-white pl-8 text-[12.5px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-[#1d2125] dark:bg-[#101111]"
                            @input="onSearchInput"
                        />
                    </div>
                </div>
                <div class="min-h-0 overflow-auto">
                    <div
                        v-if="!threads.length"
                        class="grid gap-1 p-6 text-center text-zinc-500"
                    >
                        <span class="font-semibold">No conversations</span>
                        <span class="text-xs">
                            Mail sent to your receiving addresses lands here.
                        </span>
                    </div>
                    <button
                        v-for="thread in threads"
                        :key="thread.public_id"
                        type="button"
                        class="grid w-full gap-0.5 border-b border-zinc-100 px-3.5 py-2.5 text-left transition hover:bg-zinc-50 dark:border-[#141618] dark:hover:bg-[#111315]"
                        :class="
                            selectedThread?.public_id === thread.public_id
                                ? 'bg-teal-50/70 dark:bg-teal-400/10'
                                : ''
                        "
                        @click="openThread(thread.public_id)"
                    >
                        <div class="flex items-baseline gap-2">
                            <span
                                v-if="thread.unread"
                                class="size-1.5 shrink-0 self-center rounded-full bg-teal-400"
                            />
                            <span
                                class="min-w-0 flex-1 truncate"
                                :class="
                                    thread.unread
                                        ? 'font-semibold'
                                        : 'font-medium text-zinc-700 dark:text-zinc-300'
                                "
                            >
                                {{ participantSummary(thread) }}
                            </span>
                            <span
                                class="inline-flex shrink-0 items-center gap-1 font-mono text-[10.5px] text-zinc-500"
                            >
                                <AlarmClock
                                    v-if="thread.snoozed"
                                    class="size-3 text-amber-500"
                                />
                                {{
                                    thread.snoozed
                                        ? thread.snoozed_until_human
                                        : thread.last_activity_human
                                }}
                            </span>
                        </div>
                        <div
                            class="truncate text-[12.5px]"
                            :class="
                                thread.unread
                                    ? 'text-zinc-900 dark:text-zinc-100'
                                    : 'text-zinc-600 dark:text-zinc-400'
                            "
                        >
                            {{ thread.subject || '(no subject)' }}
                        </div>
                        <div
                            class="flex items-center gap-1.5 truncate text-[12px] text-zinc-500"
                        >
                            <Send
                                v-if="thread.direction === 'outbound'"
                                class="size-3 shrink-0 text-zinc-400"
                            />
                            <span class="truncate">{{ thread.snippet }}</span>
                            <span
                                v-if="thread.message_count > 1"
                                class="ml-auto shrink-0 rounded border border-zinc-200 px-1 font-mono text-[10px] text-zinc-500 dark:border-[#1d2125]"
                            >
                                {{ thread.message_count }}
                            </span>
                        </div>
                    </button>
                </div>
            </section>

            <section
                v-if="selectedThread"
                class="grid min-h-0 grid-rows-[auto_minmax(0,1fr)_auto]"
            >
                <div
                    class="flex items-center gap-3 border-b border-zinc-200 px-5 py-3 dark:border-[#1d2125]"
                >
                    <div class="min-w-0 flex-1">
                        <h1 class="truncate text-[15px] font-semibold">
                            {{ selectedThread.subject || '(no subject)' }}
                        </h1>
                        <p class="truncate font-mono text-[11px] text-zinc-500">
                            {{ selectedThread.participants.join(' · ') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-100 dark:border-[#1d2125] dark:text-zinc-300 dark:hover:bg-[#16191c]"
                        :title="
                            selectedThread.unread ? 'Mark read' : 'Mark unread'
                        "
                        @click="
                            threadAction(
                                selectedThread.unread ? 'read' : 'unread',
                            )
                        "
                    >
                        <MailOpen
                            v-if="selectedThread.unread"
                            class="size-3.5"
                        />
                        <Mail v-else class="size-3.5" />
                        {{ selectedThread.unread ? 'Read' : 'Unread' }}
                    </button>
                    <div class="relative">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-100 dark:border-[#1d2125] dark:text-zinc-300 dark:hover:bg-[#16191c]"
                            :title="
                                selectedThread.snoozed
                                    ? `Snoozed until ${selectedThread.snoozed_until_human}`
                                    : 'Snooze'
                            "
                            @click="
                                selectedThread.snoozed
                                    ? threadAction('unsnooze')
                                    : (showSnoozeMenu = !showSnoozeMenu)
                            "
                        >
                            <AlarmClock class="size-3.5" />
                            {{ selectedThread.snoozed ? 'Unsnooze' : 'Snooze' }}
                        </button>
                        <div
                            v-if="showSnoozeMenu"
                            class="absolute top-full right-0 z-30 mt-1 grid w-44 rounded-md border border-zinc-200 bg-white p-1 shadow-lg dark:border-[#1d2125] dark:bg-[#111315]"
                        >
                            <button
                                v-for="option in snoozeOptions"
                                :key="option.key"
                                type="button"
                                class="rounded px-2 py-1.5 text-left text-xs font-medium text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#16191c]"
                                @click="snoozeThread(option.key)"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </div>
                    <button
                        v-if="canSend"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-100 dark:border-[#1d2125] dark:text-zinc-300 dark:hover:bg-[#16191c]"
                        title="Forward (f)"
                        @click="showForward = true"
                    >
                        <Forward class="size-3.5" />
                        Forward
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-100 dark:border-[#1d2125] dark:text-zinc-300 dark:hover:bg-[#16191c]"
                        @click="
                            threadAction(
                                selectedThread.archived
                                    ? 'unarchive'
                                    : 'archive',
                            )
                        "
                    >
                        <ArchiveRestore
                            v-if="selectedThread.archived"
                            class="size-3.5"
                        />
                        <Archive v-else class="size-3.5" />
                        {{ selectedThread.archived ? 'Restore' : 'Archive' }}
                    </button>
                </div>

                <div class="grid min-h-0 content-start gap-3 overflow-auto p-5">
                    <article
                        v-for="message in selectedThread.messages"
                        :key="message.id"
                        class="mx-auto grid w-full max-w-3xl gap-1.5 rounded-lg border px-4 py-3"
                        :class="
                            message.direction === 'note'
                                ? 'border-amber-200 bg-amber-50/60 dark:border-amber-400/20 dark:bg-amber-400/5'
                                : message.direction === 'outbound'
                                  ? 'border-teal-200 bg-teal-50/50 dark:border-teal-400/20 dark:bg-teal-400/5'
                                  : 'border-zinc-200 bg-white dark:border-[#1d2125] dark:bg-[#0d0f10]'
                        "
                    >
                        <div class="flex items-baseline gap-2">
                            <span class="truncate text-[12.5px] font-semibold">
                                {{ message.from }}
                            </span>
                            <span
                                v-if="message.direction === 'outbound'"
                                class="rounded px-1.5 font-mono text-[10px] font-semibold"
                                :class="statusBadgeClass(message.status)"
                            >
                                {{ message.status ?? 'queued' }}
                            </span>
                            <span
                                v-else-if="message.direction === 'note'"
                                class="inline-flex items-center gap-1 rounded bg-amber-300/80 px-1.5 font-mono text-[10px] font-semibold text-zinc-950"
                            >
                                <StickyNote class="size-2.5" />
                                internal note
                            </span>
                            <span
                                class="ml-auto shrink-0 font-mono text-[10.5px] text-zinc-500"
                            >
                                {{ message.at_human }}
                            </span>
                        </div>
                        <div
                            v-if="message.direction !== 'note'"
                            class="font-mono text-[10.5px] text-zinc-500"
                        >
                            to {{ message.to }}
                        </div>
                        <iframe
                            v-if="message.html"
                            :srcdoc="messageDocument(message.html)"
                            sandbox="allow-same-origin"
                            class="h-6 w-full rounded-md bg-white"
                            :title="`Message from ${message.from_email}`"
                            @load="fitMessageFrame"
                        />
                        <pre
                            v-else
                            class="font-sans text-[13px] leading-6 whitespace-pre-wrap text-zinc-800 dark:text-zinc-200"
                            >{{ message.text || '(empty body)' }}</pre
                        >
                        <div
                            v-if="message.attachments.length"
                            class="flex flex-wrap gap-2"
                        >
                            <a
                                v-for="(
                                    attachment, index
                                ) in message.attachments"
                                :key="attachment.filename ?? index"
                                :href="attachmentUrl(message.id, index)"
                                class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2 py-1 font-mono text-[11px] text-zinc-600 transition hover:border-teal-300 hover:text-zinc-950 dark:border-[#1d2125] dark:text-zinc-400 dark:hover:text-zinc-100"
                            >
                                <Paperclip class="size-3" />
                                {{ attachment.filename ?? 'attachment' }}
                            </a>
                        </div>
                    </article>
                </div>

                <div class="border-t border-zinc-200 p-4 dark:border-[#1d2125]">
                    <div class="mx-auto grid w-full max-w-3xl gap-2">
                        <div
                            class="flex items-center gap-1 font-mono text-[10.5px] text-zinc-500"
                        >
                            <button
                                v-if="canSend"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded px-2 py-1 font-semibold transition"
                                :class="
                                    composerMode === 'reply'
                                        ? 'bg-teal-50 text-zinc-950 dark:bg-teal-400/10 dark:text-zinc-100'
                                        : 'hover:text-zinc-700 dark:hover:text-zinc-300'
                                "
                                @click="composerMode = 'reply'"
                            >
                                <Reply class="size-3" />
                                Reply
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded px-2 py-1 font-semibold transition"
                                :class="
                                    composerMode === 'note' || !canSend
                                        ? 'bg-amber-50 text-zinc-950 dark:bg-amber-400/10 dark:text-zinc-100'
                                        : 'hover:text-zinc-700 dark:hover:text-zinc-300'
                                "
                                @click="composerMode = 'note'"
                            >
                                <StickyNote class="size-3" />
                                Note
                            </button>
                            <span
                                v-if="composerMode === 'reply' && canSend"
                                class="ml-2"
                            >
                                replying as
                                {{ selectedThread.reply_from ?? '—' }}
                            </span>
                        </div>
                        <form
                            v-if="composerMode === 'reply' && canSend"
                            class="grid gap-2"
                            @submit.prevent="sendReply"
                        >
                            <RichTextEditor
                                ref="replyEditor"
                                v-model="replyForm.html"
                                placeholder="Write a reply… (⌘↵ to send)"
                                @update:text="replyForm.text = $event"
                                @submit="sendReply"
                            />
                            <div
                                v-if="replyForm.attachments.length"
                                class="flex flex-wrap gap-2"
                            >
                                <span
                                    v-for="(
                                        file, index
                                    ) in replyForm.attachments"
                                    :key="`${file.name}-${index}`"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2 py-1 font-mono text-[11px] text-zinc-600 dark:border-[#1d2125] dark:text-zinc-400"
                                >
                                    <Paperclip class="size-3" />
                                    {{ file.name }}
                                    <span class="text-zinc-400">
                                        {{ fileSize(file) }}
                                    </span>
                                    <button
                                        type="button"
                                        class="text-zinc-400 hover:text-red-500"
                                        @click="removeFile(replyForm, index)"
                                    >
                                        <X class="size-3" />
                                    </button>
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    type="submit"
                                    class="inline-flex items-center gap-2 rounded-md bg-teal-300 px-3 py-1.5 text-xs font-bold text-zinc-950 transition hover:brightness-105 disabled:cursor-wait disabled:opacity-60"
                                    :disabled="
                                        replyForm.processing ||
                                        !replyForm.text.trim()
                                    "
                                >
                                    <Send class="size-3.5" />
                                    {{
                                        replyForm.processing
                                            ? 'Sending…'
                                            : 'Reply'
                                    }}
                                </button>
                                <button
                                    type="button"
                                    title="Attach files"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-100 dark:border-[#1d2125] dark:text-zinc-300 dark:hover:bg-[#16191c]"
                                    @click="pickFiles(replyForm)"
                                >
                                    <Paperclip class="size-3.5" />
                                    Attach
                                </button>
                                <span
                                    v-if="
                                        replyForm.errors.text ||
                                        replyForm.errors.attachments
                                    "
                                    class="text-xs text-red-500"
                                >
                                    {{
                                        replyForm.errors.text ||
                                        replyForm.errors.attachments
                                    }}
                                </span>
                            </div>
                        </form>
                        <form
                            v-else
                            class="grid gap-2"
                            @submit.prevent="sendNote"
                        >
                            <textarea
                                v-model="noteForm.body"
                                rows="3"
                                placeholder="Add an internal note for your team… (never emailed, ⌘↵ to save)"
                                class="w-full resize-y rounded-md border border-amber-200 bg-amber-50/40 p-3 text-[13px] leading-6 transition outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-300/20 dark:border-amber-400/20 dark:bg-amber-400/5"
                                @keydown="onNoteKeydown"
                            />
                            <div class="flex items-center gap-3">
                                <button
                                    type="submit"
                                    class="inline-flex items-center gap-2 rounded-md bg-amber-300 px-3 py-1.5 text-xs font-bold text-zinc-950 transition hover:brightness-105 disabled:cursor-wait disabled:opacity-60"
                                    :disabled="
                                        noteForm.processing ||
                                        !noteForm.body.trim()
                                    "
                                >
                                    <StickyNote class="size-3.5" />
                                    {{
                                        noteForm.processing
                                            ? 'Saving…'
                                            : 'Add note'
                                    }}
                                </button>
                                <span
                                    v-if="noteForm.errors.body"
                                    class="text-xs text-red-500"
                                >
                                    {{ noteForm.errors.body }}
                                </span>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
            <section
                v-else
                class="grid place-items-center text-sm text-zinc-500"
            >
                Select a conversation.
            </section>
        </div>

        <div
            v-if="showCompose"
            class="fixed inset-0 z-40 grid place-items-center bg-zinc-950/40 p-4 backdrop-blur-sm"
            @click.self="showCompose = false"
        >
            <form
                class="grid w-full max-w-xl gap-3 rounded-xl border border-zinc-200 bg-white p-5 shadow-xl dark:border-[#1d2125] dark:bg-[#111315]"
                @submit.prevent="sendCompose"
            >
                <div class="flex items-center">
                    <h2 class="font-semibold">New conversation</h2>
                    <button
                        type="button"
                        class="ml-auto rounded p-1 text-zinc-500 hover:text-zinc-950 dark:hover:text-zinc-100"
                        @click="showCompose = false"
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <label class="grid gap-1.5 text-sm">
                    <span class="text-zinc-500">To</span>
                    <input
                        v-model="composeForm.to"
                        type="email"
                        required
                        placeholder="person@example.com"
                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-[#1d2125] dark:bg-[#101111]"
                    />
                    <span
                        v-if="composeForm.errors.to"
                        class="text-xs text-red-500"
                    >
                        {{ composeForm.errors.to }}
                    </span>
                </label>
                <label class="grid gap-1.5 text-sm">
                    <span class="text-zinc-500">Subject</span>
                    <input
                        v-model="composeForm.subject"
                        required
                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-[#1d2125] dark:bg-[#101111]"
                    />
                </label>
                <div class="grid gap-1.5 text-sm">
                    <span class="text-zinc-500">Message</span>
                    <RichTextEditor
                        v-model="composeForm.html"
                        min-height="120px"
                        @update:text="composeForm.text = $event"
                        @submit="sendCompose"
                    />
                </div>
                <div
                    v-if="composeForm.attachments.length"
                    class="flex flex-wrap gap-2"
                >
                    <span
                        v-for="(file, index) in composeForm.attachments"
                        :key="`${file.name}-${index}`"
                        class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2 py-1 font-mono text-[11px] text-zinc-600 dark:border-[#1d2125] dark:text-zinc-400"
                    >
                        <Paperclip class="size-3" />
                        {{ file.name }}
                        <span class="text-zinc-400">{{ fileSize(file) }}</span>
                        <button
                            type="button"
                            class="text-zinc-400 hover:text-red-500"
                            @click="removeFile(composeForm, index)"
                        >
                            <X class="size-3" />
                        </button>
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded-md bg-teal-300 px-3 py-1.5 text-xs font-bold text-zinc-950 transition hover:brightness-105 disabled:cursor-wait disabled:opacity-60"
                        :disabled="
                            composeForm.processing || !composeForm.text.trim()
                        "
                    >
                        <Send class="size-3.5" />
                        {{ composeForm.processing ? 'Sending…' : 'Send' }}
                    </button>
                    <button
                        type="button"
                        title="Attach files"
                        class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-100 dark:border-[#1d2125] dark:text-zinc-300 dark:hover:bg-[#16191c]"
                        @click="pickFiles(composeForm)"
                    >
                        <Paperclip class="size-3.5" />
                        Attach
                    </button>
                    <span
                        v-if="
                            composeForm.errors.text ||
                            composeForm.errors.from ||
                            composeForm.errors.attachments
                        "
                        class="text-xs text-red-500"
                    >
                        {{
                            composeForm.errors.text ||
                            composeForm.errors.from ||
                            composeForm.errors.attachments
                        }}
                    </span>
                </div>
            </form>
        </div>

        <div
            v-if="showForward && selectedThread"
            class="fixed inset-0 z-40 grid place-items-center bg-zinc-950/40 p-4 backdrop-blur-sm"
            @click.self="showForward = false"
        >
            <form
                class="grid w-full max-w-xl gap-3 rounded-xl border border-zinc-200 bg-white p-5 shadow-xl dark:border-[#1d2125] dark:bg-[#111315]"
                @submit.prevent="sendForward"
            >
                <div class="flex items-center">
                    <h2 class="font-semibold">Forward conversation</h2>
                    <button
                        type="button"
                        class="ml-auto rounded p-1 text-zinc-500 hover:text-zinc-950 dark:hover:text-zinc-100"
                        @click="showForward = false"
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <p class="font-mono text-[11px] text-zinc-500">
                    Forwards the latest message and its attachments as “{{
                        selectedThread.subject || '(no subject)'
                    }}”.
                </p>
                <label class="grid gap-1.5 text-sm">
                    <span class="text-zinc-500">To</span>
                    <input
                        v-model="forwardForm.to"
                        type="email"
                        required
                        placeholder="teammate@example.com"
                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-[#1d2125] dark:bg-[#101111]"
                    />
                    <span
                        v-if="forwardForm.errors.to"
                        class="text-xs text-red-500"
                    >
                        {{ forwardForm.errors.to }}
                    </span>
                </label>
                <label class="grid gap-1.5 text-sm">
                    <span class="text-zinc-500">Note (optional)</span>
                    <textarea
                        v-model="forwardForm.text"
                        rows="3"
                        placeholder="Adding a note above the forwarded message…"
                        class="w-full resize-y rounded-md border border-zinc-200 bg-white p-3 text-[13px] leading-6 transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-[#1d2125] dark:bg-[#101111]"
                    />
                </label>
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded-md bg-teal-300 px-3 py-1.5 text-xs font-bold text-zinc-950 transition hover:brightness-105 disabled:cursor-wait disabled:opacity-60"
                        :disabled="forwardForm.processing || !forwardForm.to"
                    >
                        <Forward class="size-3.5" />
                        {{ forwardForm.processing ? 'Forwarding…' : 'Forward' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
    <div
        v-if="showShortcuts"
        class="fixed inset-0 z-40 grid place-items-center bg-zinc-950/40 p-4 backdrop-blur-sm"
        @click.self="showShortcuts = false"
    >
        <div
            class="grid w-full max-w-sm gap-3 rounded-xl border border-zinc-200 bg-white p-5 shadow-xl dark:border-[#1d2125] dark:bg-[#111315]"
        >
            <div class="flex items-center">
                <h2 class="font-semibold">Keyboard shortcuts</h2>
                <button
                    type="button"
                    class="ml-auto rounded p-1 text-zinc-500 hover:text-zinc-950 dark:hover:text-zinc-100"
                    @click="showShortcuts = false"
                >
                    <X class="size-4" />
                </button>
            </div>
            <div class="grid gap-1.5">
                <div
                    v-for="[keys, label] in shortcuts"
                    :key="keys"
                    class="flex items-center gap-3"
                >
                    <kbd
                        class="min-w-12 rounded border border-zinc-200 px-1.5 py-0.5 text-center font-mono text-[11px] text-zinc-600 dark:border-[#1d2125] dark:text-zinc-300"
                    >
                        {{ keys }}
                    </kbd>
                    <span
                        class="text-[12.5px] text-zinc-600 dark:text-zinc-400"
                    >
                        {{ label }}
                    </span>
                </div>
            </div>
        </div>
    </div>
    <Toaster />
</template>
