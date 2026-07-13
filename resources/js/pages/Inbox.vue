<script setup lang="ts">
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import {
    AlarmClock,
    Archive,
    ArchiveRestore,
    ArrowLeft,
    AtSign,
    Bell,
    CheckSquare,
    Forward,
    Inbox as InboxIcon,
    Mail,
    MailOpen,
    MoreHorizontal,
    Paperclip,
    PenSquare,
    Reply,
    Search,
    Send,
    SlidersHorizontal,
    StickyNote,
    Tag,
    History,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import GlobalRail from '@/components/GlobalRail.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import { Toaster } from '@/components/ui/sonner';
import { inbox as inboxRoute } from '@/routes/projects';

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
    status: 'open' | 'pending' | 'closed';
    priority: 'low' | 'normal' | 'high' | 'urgent';
    tags: string[];
    assigned_to: { id: number; name: string } | null;
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
    status_detail?: string | null;
    can_retry?: boolean;
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
    projects: {
        name: string;
        slug: string;
        environment: string;
        provider_label: string;
        is_current: boolean;
        href: string;
    }[];
    canSend: boolean;
    teamMembers: { id: number; name: string; email: string }[];
    templates: {
        id: number;
        name: string;
        subject: string;
        html: string | null;
        text: string | null;
    }[];
    mailbox: string;
    address: string | null;
    filters: { q: string; assigned: string };
    addresses: { address: string; count: number }[];
    counts: {
        inbox: number;
        unread: number;
        snoozed: number;
        archived: number;
        closed: number;
    };
    threads: ThreadRow[];
    pagination: { page: number; has_more: boolean };
    selectedThread:
        | (ThreadRow & {
              messages: Message[];
              reply_from: string | null;
              active_viewers: { id: number; name: string }[];
              activity: {
                  id: number;
                  type: string;
                  actor: string;
                  metadata: Record<string, unknown>;
                  at_human: string | null;
              }[];
          })
        | null;
}>();

const isDarkMode = ref(false);
const isThemeReady = ref(false);
let themeObserver: MutationObserver | null = null;

const searchQuery = ref(props.filters.q);
const searchInput = ref<HTMLInputElement | null>(null);
const mobileThreadOpen = ref(false);
const showFilters = ref(false);
const bulkMode = ref(false);
const selectedThreadIds = ref<string[]>([]);
const showActivity = ref(false);
const tagInput = ref('');
const notificationsEnabled = ref(false);
let searchTimer: ReturnType<typeof window.setTimeout> | null = null;

const inboxPath = computed(() => inboxRoute.url(props.project.slug));

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
    { key: 'closed', label: 'Closed', icon: ArchiveRestore, count: null },
]);

const pageTitle = computed(() =>
    props.counts.unread
        ? `(${props.counts.unread}) Inbox · ${props.project.name}`
        : `Inbox · ${props.project.name}`,
);

const hasFilters = computed(() =>
    Boolean(props.filters.assigned || props.address),
);

function visitInbox(params: Record<string, string | null>): void {
    if (params.thread === null) {
        mobileThreadOpen.value = false;
    }

    const query: Record<string, string> = {};
    const merged = {
        mailbox: props.mailbox,
        address: props.address,
        q: searchQuery.value,
        assigned: props.filters.assigned,
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
    visitInbox({ mailbox: key, address: null, thread: null });
}

function filterAddress(address: string): void {
    const isSelected = props.address === address;

    visitInbox({
        mailbox: isSelected ? 'inbox' : 'all',
        address: isSelected ? null : address,
        thread: null,
    });
}

function filterAssignment(assigned: string): void {
    visitInbox({
        assigned: props.filters.assigned === assigned ? null : assigned,
        thread: null,
    });
}

function setAssignmentFilter(event: Event): void {
    const assigned = (event.target as HTMLSelectElement).value;

    visitInbox({ assigned: assigned || null, thread: null });
}

function setAddressFilter(event: Event): void {
    const address = (event.target as HTMLSelectElement).value;

    visitInbox({
        mailbox: address ? 'all' : 'inbox',
        address: address || null,
        thread: null,
    });
}

function clearFilters(): void {
    visitInbox({
        mailbox: props.address ? 'inbox' : props.mailbox,
        address: null,
        assigned: null,
        thread: null,
    });
}

function openThread(publicId: string): void {
    mobileThreadOpen.value = true;
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

function updateWorkflow(
    data: Record<string, string | number | string[] | null>,
): void {
    if (!props.selectedThread) {
        return;
    }

    router.patch(
        `${props.project.path}/threads/${props.selectedThread.public_id}/workflow`,
        data,
        { preserveScroll: true, showProgress: false },
    );
}

function toggleThreadSelection(publicId: string): void {
    selectedThreadIds.value = selectedThreadIds.value.includes(publicId)
        ? selectedThreadIds.value.filter((id) => id !== publicId)
        : [...selectedThreadIds.value, publicId];
}

function bulkAction(
    action: string,
    assignedToUserId: number | null = null,
): void {
    if (!selectedThreadIds.value.length) {
        return;
    }

    router.post(
        `${props.project.path}/inbox/bulk`,
        {
            thread_ids: selectedThreadIds.value,
            action,
            assigned_to_user_id: assignedToUserId,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedThreadIds.value = [];
                bulkMode.value = false;
            },
        },
    );
}

function addTag(): void {
    const tag = tagInput.value.trim().toLowerCase();

    if (
        !props.selectedThread ||
        !tag ||
        props.selectedThread.tags.includes(tag)
    ) {
        return;
    }

    updateWorkflow({ tags: [...props.selectedThread.tags, tag] });
    tagInput.value = '';
}

function removeTag(tag: string): void {
    if (!props.selectedThread) {
        return;
    }

    updateWorkflow({
        tags: props.selectedThread.tags.filter((item) => item !== tag),
    });
}

async function toggleNotifications(): Promise<void> {
    if (!('Notification' in window)) {
        return;
    }

    const permission =
        Notification.permission === 'granted'
            ? 'granted'
            : await Notification.requestPermission();
    notificationsEnabled.value =
        permission === 'granted' ? !notificationsEnabled.value : false;
    localStorage.setItem(
        'larasend:inbox-notifications',
        notificationsEnabled.value ? '1' : '0',
    );
}

function retryMessage(messageId: string): void {
    router.post(
        `${props.project.path}/emails/${messageId}/resend`,
        {},
        { preserveScroll: true },
    );
}

const emptyMessage = computed(() => {
    if (searchQuery.value) {
        return 'No conversations match your search.';
    }

    if (props.address) {
        return `No conversations for ${props.address}.`;
    }

    if (props.mailbox === 'unread') {
        return 'You are caught up. There are no unread conversations.';
    }

    if (props.mailbox === 'snoozed') {
        return 'No conversations are snoozed.';
    }

    if (props.mailbox === 'archived') {
        return 'No archived conversations.';
    }

    return 'Mail sent to your receiving addresses lands here.';
});

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

onMounted(() => {
    isDarkMode.value = document.documentElement.classList.contains('dark');
    isThemeReady.value = true;
    themeObserver = new MutationObserver(() => {
        isDarkMode.value = document.documentElement.classList.contains('dark');
    });
    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
    mobileThreadOpen.value = new URLSearchParams(window.location.search).has(
        'thread',
    );
    window.addEventListener('keydown', onKeydown);
    notificationsEnabled.value =
        localStorage.getItem('larasend:inbox-notifications') === '1';
});
onBeforeUnmount(() => {
    themeObserver?.disconnect();
    window.removeEventListener('keydown', onKeydown);
});

usePoll(
    10000,
    {
        only: ['threads', 'counts', 'addresses', 'selectedThread'],
        showProgress: false,
    },
    { autoStart: true },
);

let previousUnreadCount = props.counts.unread;
watch(
    () => props.counts.unread,
    (count) => {
        if (
            notificationsEnabled.value &&
            count > previousUnreadCount &&
            Notification.permission === 'granted'
        ) {
            new Notification('New Larasend conversation', {
                body: `${count} unread conversations in ${props.project.name}`,
            });
        }

        previousUnreadCount = count;
    },
);

const replyForm = useForm({
    text: '',
    html: '',
    reply_all: false,
    cc: '',
    bcc: '',
    attachments: [] as File[],
});
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
    cc: '',
    bcc: '',
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

function applyTemplate(templateId: string): void {
    const template = props.templates.find(
        (item) => item.id === Number(templateId),
    );

    if (!template) {
        return;
    }

    composeForm.subject = template.subject;
    composeForm.html = template.html ?? '';
    composeForm.text = template.text ?? '';
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
const showThreadActions = ref(false);
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
function messageDocument(html: string, dark: boolean): string {
    const safeHtml = html
        .replace(
            /<img\b[^>]*>/gi,
            '<span style="color:#71717a">[remote image blocked]</span>',
        )
        .replace(/<a\s/gi, '<a target="_blank" rel="noopener noreferrer" ');

    const foreground = dark ? '#e4e4e7' : '#27272a';
    const muted = dark ? '#a1a1aa' : '#71717a';
    const link = dark ? '#5eead4' : '#0d9488';

    return `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data: cid:"><style>
html{color-scheme:${dark ? 'dark' : 'light'};background:transparent}
body{margin:0;padding:1px;background:transparent;font:13px/1.6 ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:${foreground};word-break:break-word;overflow-wrap:anywhere}
${dark ? `body :where(p,div,span,td,th,li,pre,code,strong,em){color:${foreground}!important}` : ''}
img{max-width:100%;height:auto}
p{margin:0 0 .6em}p:last-child{margin-bottom:0}
ul,ol{margin:0 0 .6em;padding-left:1.4em}
blockquote{margin:0 0 .6em;padding-left:.8em;border-left:2px solid #99f6e4;color:${muted}}
pre{white-space:pre-wrap;font:12px/1.6 ui-monospace,monospace}
a{color:${link}!important}
table{max-width:100%}
</style></head><body>${safeHtml}</body></html>`;
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
        class="grid h-screen grid-cols-1 grid-rows-[60px_minmax(0,1fr)] bg-[#fbfaf7] pb-16 font-sans text-sm text-zinc-950 lg:grid-cols-[248px_minmax(0,1fr)] lg:grid-rows-[64px_minmax(0,1fr)] lg:pb-0 dark:bg-[#090a0a] dark:text-zinc-100"
    >
        <GlobalRail
            :project-path="project.path"
            :project-name="project.name"
            :project-slug="project.slug"
            section="inbox"
            :projects="projects"
            :inbox-unread="counts.unread"
        />
        <header
            class="col-start-1 row-start-1 flex items-center gap-3 border-b border-zinc-200 px-4 lg:col-start-2 dark:border-[#1d2125]"
        >
            <div class="flex min-w-0 items-center gap-2.5">
                <Link
                    href="/dashboard"
                    class="grid size-8 shrink-0 place-items-center rounded-lg bg-teal-300 font-mono text-xs font-bold text-[#07221c] lg:hidden"
                >
                    L
                </Link>
                <span class="truncate font-semibold">{{ project.name }}</span>
                <span
                    class="hidden font-mono text-[11px] text-zinc-500 sm:inline"
                    >Inbox</span
                >
            </div>
            <button
                type="button"
                class="ml-auto grid size-9 place-items-center rounded-lg border border-zinc-200 text-zinc-500 xl:hidden dark:border-[#1d2125]"
                aria-label="Mailbox filters"
                @click="showFilters = true"
            >
                <SlidersHorizontal class="size-4" />
            </button>
            <button
                v-if="canSend"
                type="button"
                class="inline-flex h-9 items-center gap-2 rounded-lg bg-teal-300 px-3 text-[13px] font-bold text-zinc-950 transition hover:brightness-105 xl:ml-auto"
                @click="showCompose = true"
            >
                <PenSquare class="size-3.5" />
                Compose
            </button>
            <button
                type="button"
                class="grid size-8 place-items-center rounded-md border border-zinc-200 text-zinc-500 dark:border-[#1d2125]"
                :class="notificationsEnabled ? 'text-teal-500' : ''"
                :title="
                    notificationsEnabled
                        ? 'Disable new-mail notifications'
                        : 'Enable new-mail notifications'
                "
                @click="toggleNotifications"
            >
                <Bell class="size-3.5" />
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
            class="col-start-1 row-start-2 grid min-h-0 grid-cols-1 md:grid-cols-[320px_minmax(0,1fr)] lg:col-start-2 xl:grid-cols-[200px_360px_minmax(0,1fr)]"
        >
            <aside
                class="hidden min-h-0 content-start gap-1 overflow-auto border-r border-zinc-200 p-3 xl:grid dark:border-[#1d2125]"
            >
                <section class="grid gap-0.5">
                    <p
                        class="px-2 pb-1 font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                    >
                        Mailboxes
                    </p>
                    <button
                        v-for="box in mailboxes"
                        :key="box.key"
                        type="button"
                        class="flex h-7 items-center gap-2.5 rounded-md px-2 text-left font-medium transition hover:bg-zinc-100 dark:hover:bg-[#16191c]"
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
                </section>

                <section
                    class="mt-4 border-t border-zinc-200 pt-4 dark:border-[#1d2125]"
                >
                    <div class="mb-2.5 flex items-center gap-2 px-1">
                        <span
                            class="grid size-6 place-items-center rounded-md bg-zinc-100 text-zinc-500 dark:bg-[#16191c] dark:text-zinc-400"
                        >
                            <SlidersHorizontal class="size-3.5" />
                        </span>
                        <p
                            class="text-[12.5px] font-semibold text-zinc-800 dark:text-zinc-200"
                        >
                            Filters
                        </p>
                        <button
                            v-if="hasFilters"
                            type="button"
                            class="ml-auto rounded px-1 py-0.5 text-[10.5px] font-semibold text-teal-700 transition hover:bg-teal-50 dark:text-teal-300 dark:hover:bg-teal-400/10"
                            @click="clearFilters"
                        >
                            Clear
                        </button>
                    </div>

                    <div
                        class="grid gap-2.5 rounded-lg border border-zinc-200 bg-zinc-50/70 p-2.5 dark:border-[#1d2125] dark:bg-[#101111]"
                    >
                        <label class="grid min-w-0 gap-1">
                            <span
                                class="text-[10.5px] font-medium text-zinc-500 dark:text-zinc-400"
                            >
                                Assignee
                            </span>
                            <select
                                :value="filters.assigned"
                                class="h-8 min-w-0 rounded-md border border-zinc-200 bg-white px-2 text-[11.5px] font-medium text-zinc-700 transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-[#262a2e] dark:bg-[#16191c] dark:text-zinc-200"
                                @change="setAssignmentFilter"
                            >
                                <option value="">Any assignee</option>
                                <option value="mine">Assigned to me</option>
                                <option value="unassigned">Unassigned</option>
                            </select>
                        </label>

                        <label
                            v-if="addresses.length"
                            class="grid min-w-0 gap-1"
                        >
                            <span
                                class="text-[10.5px] font-medium text-zinc-500 dark:text-zinc-400"
                            >
                                Receiving address
                            </span>
                            <select
                                :value="address ?? ''"
                                class="h-8 min-w-0 rounded-md border border-zinc-200 bg-white px-2 font-mono text-[10.5px] text-zinc-700 transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-[#262a2e] dark:bg-[#16191c] dark:text-zinc-200"
                                @change="setAddressFilter"
                            >
                                <option value="">All addresses</option>
                                <option
                                    v-for="entry in addresses"
                                    :key="entry.address"
                                    :value="entry.address"
                                >
                                    {{ entry.address }} · {{ entry.count }}
                                </option>
                            </select>
                        </label>
                    </div>
                </section>
            </aside>

            <section
                class="min-h-0 grid-rows-[auto_minmax(0,1fr)] border-r border-zinc-200 md:grid dark:border-[#1d2125]"
                :class="
                    selectedThread && mobileThreadOpen
                        ? 'hidden md:grid'
                        : 'grid'
                "
            >
                <div
                    class="grid gap-2 border-b border-zinc-200 p-2.5 dark:border-[#1d2125]"
                >
                    <div class="flex gap-2">
                        <div class="relative min-w-0 flex-1">
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
                        <button
                            type="button"
                            class="grid size-8 place-items-center rounded-md border border-zinc-200 text-zinc-500 dark:border-[#1d2125]"
                            :class="
                                bulkMode
                                    ? 'bg-teal-50 text-teal-700 dark:bg-teal-400/10 dark:text-teal-300'
                                    : ''
                            "
                            title="Select conversations"
                            @click="
                                bulkMode = !bulkMode;
                                selectedThreadIds = [];
                            "
                        >
                            <CheckSquare class="size-3.5" />
                        </button>
                    </div>
                    <div
                        v-if="bulkMode"
                        class="flex flex-wrap items-center gap-1.5 text-xs"
                    >
                        <span class="mr-auto font-semibold"
                            >{{ selectedThreadIds.length }} selected</span
                        >
                        <button
                            type="button"
                            class="rounded border border-zinc-200 px-2 py-1 dark:border-[#1d2125]"
                            :disabled="!selectedThreadIds.length"
                            @click="bulkAction('mark_read')"
                        >
                            Read
                        </button>
                        <button
                            type="button"
                            class="rounded border border-zinc-200 px-2 py-1 dark:border-[#1d2125]"
                            :disabled="!selectedThreadIds.length"
                            @click="bulkAction('archive')"
                        >
                            Archive
                        </button>
                        <button
                            type="button"
                            class="rounded border border-zinc-200 px-2 py-1 dark:border-[#1d2125]"
                            :disabled="!selectedThreadIds.length"
                            @click="bulkAction('close')"
                        >
                            Close
                        </button>
                        <select
                            class="h-7 rounded border border-zinc-200 bg-transparent px-1 dark:border-[#1d2125]"
                            :disabled="!selectedThreadIds.length"
                            @change="
                                bulkAction(
                                    'assign',
                                    ($event.target as HTMLSelectElement).value
                                        ? Number(
                                              (
                                                  $event.target as HTMLSelectElement
                                              ).value,
                                          )
                                        : null,
                                )
                            "
                        >
                            <option value="">Assign…</option>
                            <option
                                v-for="member in teamMembers"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.name }}
                            </option>
                        </select>
                    </div>
                </div>
                <div class="min-h-0 overflow-auto">
                    <div
                        v-if="!threads.length"
                        class="grid gap-1 p-6 text-center text-zinc-500"
                    >
                        <span class="font-semibold">No conversations</span>
                        <span class="text-xs">
                            {{ emptyMessage }}
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
                        @click="
                            bulkMode
                                ? toggleThreadSelection(thread.public_id)
                                : openThread(thread.public_id)
                        "
                    >
                        <div class="flex items-baseline gap-2">
                            <span
                                v-if="bulkMode"
                                class="grid size-4 shrink-0 place-items-center rounded border border-zinc-300 text-[10px]"
                                :class="
                                    selectedThreadIds.includes(thread.public_id)
                                        ? 'border-teal-400 bg-teal-400 text-zinc-950'
                                        : ''
                                "
                            >
                                {{
                                    selectedThreadIds.includes(thread.public_id)
                                        ? '✓'
                                        : ''
                                }}
                            </span>
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
                    <div
                        v-if="pagination.page > 1 || pagination.has_more"
                        class="flex gap-2 p-3"
                    >
                        <button
                            v-if="pagination.page > 1"
                            type="button"
                            class="flex-1 rounded-md border border-zinc-200 py-2 text-xs font-semibold dark:border-[#1d2125]"
                            @click="
                                visitInbox({
                                    page: String(pagination.page - 1),
                                    thread: null,
                                })
                            "
                        >
                            Previous
                        </button>
                        <button
                            v-if="pagination.has_more"
                            type="button"
                            class="flex-1 rounded-md border border-zinc-200 py-2 text-xs font-semibold dark:border-[#1d2125]"
                            @click="
                                visitInbox({
                                    page: String(pagination.page + 1),
                                    thread: null,
                                })
                            "
                        >
                            Next 50
                        </button>
                    </div>
                </div>
            </section>

            <section
                v-if="selectedThread"
                class="min-h-0 grid-rows-[auto_minmax(0,1fr)_auto] md:grid"
                :class="mobileThreadOpen ? 'grid' : 'hidden'"
            >
                <div
                    class="flex flex-wrap items-center gap-2 border-b border-zinc-200 px-3 py-3 sm:gap-3 sm:px-5 dark:border-[#1d2125]"
                >
                    <button
                        type="button"
                        class="grid size-9 shrink-0 place-items-center rounded-lg border border-zinc-200 text-zinc-500 md:hidden dark:border-[#1d2125]"
                        aria-label="Back to conversations"
                        @click="visitInbox({ thread: null })"
                    >
                        <ArrowLeft class="size-4" />
                    </button>
                    <div class="min-w-0 flex-1 md:basis-full 2xl:basis-auto">
                        <h1 class="truncate text-[15px] font-semibold">
                            {{ selectedThread.subject || '(no subject)' }}
                        </h1>
                        <p class="truncate font-mono text-[11px] text-zinc-500">
                            {{ selectedThread.participants.join(' · ') }}
                        </p>
                        <div
                            v-if="selectedThread.tags.length"
                            class="mt-1 flex flex-wrap gap-1"
                        >
                            <button
                                v-for="tag in selectedThread.tags"
                                :key="tag"
                                type="button"
                                class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                                :title="`Remove ${tag}`"
                                @click="removeTag(tag)"
                            >
                                #{{ tag }} ×
                            </button>
                        </div>
                        <p
                            v-if="selectedThread.active_viewers.length"
                            class="mt-1 truncate text-[10.5px] font-semibold text-amber-500"
                        >
                            {{
                                selectedThread.active_viewers
                                    .map((viewer) => viewer.name)
                                    .join(', ')
                            }}
                            viewing now
                        </p>
                    </div>
                    <select
                        :value="selectedThread.status"
                        class="hidden h-8 rounded-md border border-zinc-200 bg-transparent px-2 text-xs font-semibold lg:block dark:border-[#1d2125]"
                        aria-label="Conversation status"
                        @change="
                            updateWorkflow({
                                status: ($event.target as HTMLSelectElement)
                                    .value,
                            })
                        "
                    >
                        <option value="open">Open</option>
                        <option value="pending">Pending</option>
                        <option value="closed">Closed</option>
                    </select>
                    <form
                        class="hidden items-center gap-1 2xl:flex"
                        @submit.prevent="addTag"
                    >
                        <Tag class="size-3.5 text-zinc-400" />
                        <input
                            v-model="tagInput"
                            maxlength="32"
                            placeholder="tag"
                            class="h-8 w-20 rounded-md border border-zinc-200 bg-transparent px-2 text-xs dark:border-[#1d2125]"
                        />
                    </form>
                    <button
                        type="button"
                        class="grid size-8 place-items-center rounded-md border border-zinc-200 text-zinc-500 dark:border-[#1d2125]"
                        title="Activity history"
                        @click="showActivity = true"
                    >
                        <History class="size-3.5" />
                    </button>
                    <select
                        :value="selectedThread.assigned_to?.id ?? ''"
                        class="hidden h-8 max-w-36 rounded-md border border-zinc-200 bg-transparent px-2 text-xs lg:block dark:border-[#1d2125]"
                        aria-label="Assign conversation"
                        @change="
                            updateWorkflow({
                                assigned_to_user_id: (
                                    $event.target as HTMLSelectElement
                                ).value
                                    ? Number(
                                          ($event.target as HTMLSelectElement)
                                              .value,
                                      )
                                    : null,
                            })
                        "
                    >
                        <option value="">Unassigned</option>
                        <option
                            v-for="member in teamMembers"
                            :key="member.id"
                            :value="member.id"
                        >
                            {{ member.name }}
                        </option>
                    </select>
                    <select
                        :value="selectedThread.priority"
                        class="hidden h-8 rounded-md border border-zinc-200 bg-transparent px-2 text-xs lg:block dark:border-[#1d2125]"
                        aria-label="Conversation priority"
                        @change="
                            updateWorkflow({
                                priority: ($event.target as HTMLSelectElement)
                                    .value,
                            })
                        "
                    >
                        <option value="low">Low</option>
                        <option value="normal">Normal</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                    <button
                        type="button"
                        class="hidden items-center gap-1.5 rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-100 2xl:inline-flex dark:border-[#1d2125] dark:text-zinc-300 dark:hover:bg-[#16191c]"
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
                            class="hidden items-center gap-1.5 rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-100 2xl:inline-flex dark:border-[#1d2125] dark:text-zinc-300 dark:hover:bg-[#16191c]"
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
                        class="hidden items-center gap-1.5 rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-100 2xl:inline-flex dark:border-[#1d2125] dark:text-zinc-300 dark:hover:bg-[#16191c]"
                        title="Forward (f)"
                        @click="showForward = true"
                    >
                        <Forward class="size-3.5" />
                        Forward
                    </button>
                    <button
                        type="button"
                        class="hidden items-center gap-1.5 rounded-md border border-zinc-200 px-2.5 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-100 2xl:inline-flex dark:border-[#1d2125] dark:text-zinc-300 dark:hover:bg-[#16191c]"
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
                    <div class="relative 2xl:hidden">
                        <button
                            type="button"
                            class="grid size-9 place-items-center rounded-lg border border-zinc-200 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-950 dark:border-[#1d2125] dark:hover:bg-[#16191c] dark:hover:text-zinc-100"
                            aria-label="Conversation actions"
                            @click="showThreadActions = !showThreadActions"
                        >
                            <MoreHorizontal class="size-4" />
                        </button>
                        <div
                            v-if="showThreadActions"
                            class="absolute top-full right-0 z-30 mt-1 grid w-52 gap-0.5 rounded-lg border border-zinc-200 bg-white p-1.5 shadow-xl dark:border-[#1d2125] dark:bg-[#111315]"
                        >
                            <button
                                type="button"
                                class="flex min-h-9 items-center gap-2 rounded-md px-2.5 text-left text-xs font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#1a1e22]"
                                @click="
                                    showThreadActions = false;
                                    threadAction(
                                        selectedThread.unread
                                            ? 'read'
                                            : 'unread',
                                    );
                                "
                            >
                                <MailOpen
                                    v-if="selectedThread.unread"
                                    class="size-3.5"
                                />
                                <Mail v-else class="size-3.5" />
                                Mark
                                {{ selectedThread.unread ? 'read' : 'unread' }}
                            </button>
                            <button
                                v-if="selectedThread.snoozed"
                                type="button"
                                class="flex min-h-9 items-center gap-2 rounded-md px-2.5 text-left text-xs font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#1a1e22]"
                                @click="
                                    showThreadActions = false;
                                    threadAction('unsnooze');
                                "
                            >
                                <AlarmClock class="size-3.5" />
                                Unsnooze
                            </button>
                            <template v-else>
                                <p
                                    class="px-2.5 pt-1 font-mono text-[9.5px] tracking-widest text-zinc-400 uppercase"
                                >
                                    Snooze
                                </p>
                                <button
                                    v-for="option in snoozeOptions"
                                    :key="option.key"
                                    type="button"
                                    class="flex min-h-8 items-center gap-2 rounded-md px-2.5 text-left text-xs font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#1a1e22]"
                                    @click="
                                        showThreadActions = false;
                                        snoozeThread(option.key);
                                    "
                                >
                                    <AlarmClock class="size-3.5" />
                                    {{ option.label }}
                                </button>
                            </template>
                            <button
                                v-if="canSend"
                                type="button"
                                class="flex min-h-9 items-center gap-2 rounded-md px-2.5 text-left text-xs font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#1a1e22]"
                                @click="
                                    showThreadActions = false;
                                    showForward = true;
                                "
                            >
                                <Forward class="size-3.5" />
                                Forward
                            </button>
                            <button
                                type="button"
                                class="flex min-h-9 items-center gap-2 rounded-md px-2.5 text-left text-xs font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#1a1e22]"
                                @click="
                                    showThreadActions = false;
                                    threadAction(
                                        selectedThread.archived
                                            ? 'unarchive'
                                            : 'archive',
                                    );
                                "
                            >
                                <ArchiveRestore
                                    v-if="selectedThread.archived"
                                    class="size-3.5"
                                />
                                <Archive v-else class="size-3.5" />
                                {{
                                    selectedThread.archived
                                        ? 'Restore'
                                        : 'Archive'
                                }}
                            </button>
                        </div>
                    </div>
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
                        <p
                            v-if="message.status_detail"
                            class="rounded-md bg-red-50 px-2 py-1 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300"
                        >
                            {{ message.status_detail }}
                        </p>
                        <button
                            v-if="message.can_retry"
                            type="button"
                            class="w-fit rounded-md border border-red-200 px-2 py-1 text-xs font-semibold text-red-600 dark:border-red-500/30 dark:text-red-300"
                            @click="retryMessage(message.id)"
                        >
                            Retry send
                        </button>
                        <div
                            v-if="message.direction !== 'note'"
                            class="font-mono text-[10.5px] text-zinc-500"
                        >
                            to {{ message.to }}
                        </div>
                        <iframe
                            v-if="message.html && isThemeReady"
                            :srcdoc="messageDocument(message.html, isDarkMode)"
                            sandbox="allow-same-origin allow-popups"
                            class="h-6 w-full rounded-md bg-white dark:bg-[#101111]"
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
                            <template v-if="message.direction === 'inbound'">
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
                            </template>
                            <span
                                v-for="(
                                    attachment, index
                                ) in message.direction === 'outbound'
                                    ? message.attachments
                                    : []"
                                :key="`outbound-${attachment.filename ?? index}`"
                                class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2 py-1 font-mono text-[11px] text-zinc-600 dark:border-[#1d2125] dark:text-zinc-400"
                            >
                                <Paperclip class="size-3" />
                                {{ attachment.filename ?? 'attachment' }}
                            </span>
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
                            <div class="grid gap-2 sm:grid-cols-3">
                                <label
                                    class="flex items-center gap-2 text-xs text-zinc-500"
                                >
                                    <input
                                        v-model="replyForm.reply_all"
                                        type="checkbox"
                                    />
                                    Reply all
                                </label>
                                <input
                                    v-model="replyForm.cc"
                                    type="text"
                                    placeholder="CC"
                                    class="h-8 rounded-md border border-zinc-200 bg-transparent px-2 text-xs dark:border-[#1d2125]"
                                />
                                <input
                                    v-model="replyForm.bcc"
                                    type="text"
                                    placeholder="BCC"
                                    class="h-8 rounded-md border border-zinc-200 bg-transparent px-2 text-xs dark:border-[#1d2125]"
                                />
                            </div>
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
            v-if="showFilters"
            class="fixed inset-0 z-50 bg-zinc-950/45 xl:hidden"
            @click.self="showFilters = false"
        >
            <aside
                class="grid h-full w-72 content-start gap-1 overflow-y-auto bg-white p-4 shadow-2xl dark:bg-[#111315]"
            >
                <div class="mb-3 flex items-center">
                    <h2 class="font-semibold">Mailbox filters</h2>
                    <button
                        type="button"
                        class="ml-auto p-2"
                        aria-label="Close filters"
                        @click="showFilters = false"
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <button
                    v-for="box in mailboxes"
                    :key="box.key"
                    type="button"
                    class="flex min-h-10 items-center gap-2 rounded-lg px-3 text-left"
                    :class="
                        mailbox === box.key && !address
                            ? 'bg-teal-50 dark:bg-teal-400/10'
                            : ''
                    "
                    @click="
                        showFilters = false;
                        openMailbox(box.key);
                    "
                >
                    <component :is="box.icon" class="size-4" />
                    <span class="flex-1">{{ box.label }}</span>
                    <span v-if="box.count" class="font-mono text-xs">{{
                        box.count
                    }}</span>
                </button>
                <p
                    class="mt-4 px-3 font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                >
                    Assignment
                </p>
                <button
                    v-for="entry in [
                        { key: 'mine', label: 'Mine' },
                        { key: 'unassigned', label: 'Unassigned' },
                    ]"
                    :key="entry.key"
                    type="button"
                    class="min-h-10 rounded-lg px-3 text-left"
                    :class="
                        filters.assigned === entry.key
                            ? 'bg-teal-50 dark:bg-teal-400/10'
                            : ''
                    "
                    @click="
                        showFilters = false;
                        filterAssignment(entry.key);
                    "
                >
                    {{ entry.label }}
                </button>
                <p
                    class="mt-4 px-3 font-mono text-[10px] tracking-widest text-zinc-500 uppercase"
                >
                    Addresses
                </p>
                <button
                    v-for="entry in addresses"
                    :key="entry.address"
                    type="button"
                    class="flex min-h-10 items-center gap-2 rounded-lg px-3 text-left"
                    :class="
                        address === entry.address
                            ? 'bg-teal-50 dark:bg-teal-400/10'
                            : ''
                    "
                    @click="
                        showFilters = false;
                        filterAddress(entry.address);
                    "
                >
                    <AtSign class="size-4" />
                    <span class="min-w-0 flex-1 truncate">{{
                        entry.address
                    }}</span>
                    <span class="font-mono text-xs">{{ entry.count }}</span>
                </button>
            </aside>
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
                    <span class="text-zinc-500">From</span>
                    <select
                        v-model="composeForm.from"
                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] dark:border-[#1d2125] dark:bg-[#101111]"
                    >
                        <option value="">Default sender</option>
                        <option
                            v-for="entry in addresses"
                            :key="entry.address"
                            :value="entry.address"
                        >
                            {{ entry.address }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-1.5 text-sm">
                    <span class="text-zinc-500">To</span>
                    <input
                        v-model="composeForm.to"
                        type="text"
                        required
                        placeholder="One or more comma-separated addresses"
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
                <label v-if="templates.length" class="grid gap-1.5 text-sm">
                    <span class="text-zinc-500">Template</span>
                    <select
                        class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] dark:border-[#1d2125] dark:bg-[#101111]"
                        @change="
                            applyTemplate(
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="">Start from scratch</option>
                        <option
                            v-for="template in templates"
                            :key="template.id"
                            :value="template.id"
                        >
                            {{ template.name }}
                        </option>
                    </select>
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1.5 text-sm">
                        <span class="text-zinc-500">CC</span>
                        <input
                            v-model="composeForm.cc"
                            type="text"
                            placeholder="Comma-separated addresses"
                            class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] dark:border-[#1d2125] dark:bg-[#101111]"
                        />
                    </label>
                    <label class="grid gap-1.5 text-sm">
                        <span class="text-zinc-500">BCC</span>
                        <input
                            v-model="composeForm.bcc"
                            type="text"
                            placeholder="Comma-separated addresses"
                            class="h-9 rounded-md border border-zinc-200 bg-white px-2.5 text-[13px] dark:border-[#1d2125] dark:bg-[#101111]"
                        />
                    </label>
                </div>
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
        v-if="showActivity && selectedThread"
        class="fixed inset-0 z-50 grid place-items-center bg-zinc-950/45 p-4 backdrop-blur-sm"
        @click.self="showActivity = false"
    >
        <section
            class="grid max-h-[80vh] w-full max-w-lg grid-rows-[auto_minmax(0,1fr)] overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-2xl dark:border-[#1d2125] dark:bg-[#111315]"
        >
            <div
                class="flex items-center border-b border-zinc-200 p-4 dark:border-[#1d2125]"
            >
                <div>
                    <h2 class="font-semibold">Conversation activity</h2>
                    <p class="text-xs text-zinc-500">
                        Assignment, status, snooze, and archive history
                    </p>
                </div>
                <button
                    type="button"
                    class="ml-auto p-2"
                    aria-label="Close activity"
                    @click="showActivity = false"
                >
                    <X class="size-4" />
                </button>
            </div>
            <div class="overflow-y-auto p-4">
                <form class="mb-4 flex gap-2" @submit.prevent="addTag">
                    <div class="relative min-w-0 flex-1">
                        <Tag
                            class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-zinc-400"
                        />
                        <input
                            v-model="tagInput"
                            maxlength="32"
                            placeholder="Add a conversation tag"
                            class="h-9 w-full rounded-md border border-zinc-200 bg-transparent pr-3 pl-8 text-sm dark:border-[#1d2125]"
                        />
                    </div>
                    <button
                        type="submit"
                        class="rounded-md bg-teal-300 px-3 text-xs font-bold text-zinc-950 disabled:opacity-50"
                        :disabled="!tagInput.trim()"
                    >
                        Add tag
                    </button>
                </form>
                <div
                    v-if="selectedThread.tags.length"
                    class="mb-4 flex flex-wrap gap-1.5"
                >
                    <button
                        v-for="tag in selectedThread.tags"
                        :key="tag"
                        type="button"
                        class="rounded bg-zinc-100 px-2 py-1 text-xs dark:bg-zinc-800"
                        @click="removeTag(tag)"
                    >
                        #{{ tag }} ×
                    </button>
                </div>
                <p
                    v-if="!selectedThread.activity.length"
                    class="py-8 text-center text-sm text-zinc-500"
                >
                    No workflow activity yet.
                </p>
                <div
                    v-for="event in selectedThread.activity"
                    :key="event.id"
                    class="flex gap-3 border-b border-zinc-100 py-3 last:border-0 dark:border-[#1d2125]"
                >
                    <span
                        class="grid size-7 shrink-0 place-items-center rounded-full bg-zinc-100 font-mono text-[10px] dark:bg-zinc-800"
                        >{{ event.actor.slice(0, 2).toUpperCase() }}</span
                    >
                    <div class="min-w-0 flex-1">
                        <p class="text-sm">
                            <strong>{{ event.actor }}</strong>
                            {{ event.type.replaceAll('_', ' ') }}
                        </p>
                        <p class="font-mono text-[10.5px] text-zinc-500">
                            {{ event.at_human }}
                        </p>
                    </div>
                </div>
            </div>
        </section>
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
