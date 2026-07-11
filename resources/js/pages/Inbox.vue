<script setup lang="ts">
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    ArrowLeft,
    AtSign,
    Inbox as InboxIcon,
    Mail,
    MailOpen,
    Paperclip,
    PenSquare,
    Reply,
    Search,
    Send,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
    last_activity_at: string | null;
    last_activity_human: string | null;
};

type Message = {
    id: string;
    direction: 'inbound' | 'outbound';
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
    counts: { inbox: number; unread: number; archived: number };
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
        key: 'archived',
        label: 'Archived',
        icon: Archive,
        count: null,
    },
]);

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

    switch (event.key) {
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
            replyBox.value?.focus();
            break;
        case 'c':
            event.preventDefault();
            showCompose.value = true;
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

const replyForm = useForm({ text: '' });
const replyBox = ref<HTMLTextAreaElement | null>(null);

function sendReply(): void {
    if (!props.selectedThread || !replyForm.text.trim()) {
        return;
    }

    replyForm.post(
        `${props.project.path}/threads/${props.selectedThread.public_id}/reply`,
        {
            preserveScroll: true,
            onSuccess: () => replyForm.reset(),
        },
    );
}

function onReplyKeydown(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
        event.preventDefault();
        sendReply();
    }
}

const showCompose = ref(false);
const composeForm = useForm({ from: '', to: '', subject: '', text: '' });

function sendCompose(): void {
    composeForm.post(`${props.project.path}/inbox/compose`, {
        onSuccess: () => {
            composeForm.reset();
            showCompose.value = false;
        },
    });
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
    <Head :title="`Inbox · ${project.name}`" />

    <div
        class="grid h-screen grid-rows-[52px_minmax(0,1fr)] bg-[#fbfaf7] font-sans text-[13px] text-zinc-950 dark:bg-[#090a0a] dark:text-zinc-100"
    >
        <header
            class="flex items-center gap-3 border-b border-zinc-200 px-4 dark:border-[#1d2125]"
        >
            <div
                class="grid size-7 place-items-center rounded-md bg-teal-300 font-mono text-xs font-semibold text-zinc-950"
            >
                L
            </div>
            <div class="flex items-baseline gap-2">
                <span class="font-semibold">{{ project.name }}</span>
                <span class="font-mono text-[11px] text-zinc-500">Inbox</span>
            </div>
            <span
                class="ml-2 hidden items-center gap-3 font-mono text-[10.5px] text-zinc-400 md:flex dark:text-zinc-600"
            >
                <span>j/k navigate</span>
                <span>e archive</span>
                <span>u unread</span>
                <span>r reply</span>
                <span>c compose</span>
                <span>/ search</span>
            </span>
            <button
                v-if="canSend"
                type="button"
                class="ml-auto inline-flex items-center gap-2 rounded-md bg-teal-300 px-3 py-1.5 text-xs font-bold text-zinc-950 transition hover:brightness-105"
                @click="showCompose = true"
            >
                <PenSquare class="size-3.5" />
                Compose
            </button>
            <Link
                :href="project.dashboard_path"
                class="inline-flex items-center gap-2 rounded-md border border-zinc-200 px-3 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-100 dark:border-[#1d2125] dark:text-zinc-300 dark:hover:bg-[#16191c]"
                :class="canSend ? '' : 'ml-auto'"
            >
                <ArrowLeft class="size-3.5" />
                Dashboard
            </Link>
        </header>

        <div class="grid min-h-0 grid-cols-[200px_360px_minmax(0,1fr)]">
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
                                class="shrink-0 font-mono text-[10.5px] text-zinc-500"
                            >
                                {{ thread.last_activity_human }}
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
                        class="grid gap-2 rounded-lg border p-4"
                        :class="
                            message.direction === 'outbound'
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
                                class="rounded bg-teal-300/80 px-1.5 font-mono text-[10px] font-semibold text-zinc-950"
                            >
                                sent{{
                                    message.status === 'failed'
                                        ? ' · failed'
                                        : ''
                                }}
                            </span>
                            <span
                                class="ml-auto shrink-0 font-mono text-[10.5px] text-zinc-500"
                            >
                                {{ message.at_human }}
                            </span>
                        </div>
                        <div class="font-mono text-[10.5px] text-zinc-500">
                            to {{ message.to }}
                        </div>
                        <iframe
                            v-if="message.html"
                            :srcdoc="message.html"
                            sandbox=""
                            class="h-72 w-full rounded-md border border-zinc-200 bg-white dark:border-[#1d2125]"
                            :title="`Message from ${message.from_email}`"
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

                <form
                    v-if="canSend"
                    class="grid gap-2 border-t border-zinc-200 p-4 dark:border-[#1d2125]"
                    @submit.prevent="sendReply"
                >
                    <div
                        class="flex items-center gap-2 font-mono text-[10.5px] text-zinc-500"
                    >
                        <Reply class="size-3" />
                        replying as {{ selectedThread.reply_from ?? '—' }}
                    </div>
                    <textarea
                        ref="replyBox"
                        v-model="replyForm.text"
                        rows="3"
                        placeholder="Write a reply… (⌘↵ to send)"
                        class="w-full resize-y rounded-md border border-zinc-200 bg-white p-3 text-[13px] leading-6 transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-[#1d2125] dark:bg-[#101111]"
                        @keydown="onReplyKeydown"
                    />
                    <div class="flex items-center gap-3">
                        <button
                            type="submit"
                            class="inline-flex items-center gap-2 rounded-md bg-teal-300 px-3 py-1.5 text-xs font-bold text-zinc-950 transition hover:brightness-105 disabled:cursor-wait disabled:opacity-60"
                            :disabled="
                                replyForm.processing || !replyForm.text.trim()
                            "
                        >
                            <Send class="size-3.5" />
                            {{ replyForm.processing ? 'Sending…' : 'Reply' }}
                        </button>
                        <span
                            v-if="replyForm.errors.text"
                            class="text-xs text-red-500"
                        >
                            {{ replyForm.errors.text }}
                        </span>
                    </div>
                </form>
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
                <label class="grid gap-1.5 text-sm">
                    <span class="text-zinc-500">Message</span>
                    <textarea
                        v-model="composeForm.text"
                        rows="6"
                        required
                        class="w-full resize-y rounded-md border border-zinc-200 bg-white p-3 text-[13px] leading-6 transition outline-none focus:border-teal-400 focus:ring-2 focus:ring-teal-300/20 dark:border-[#1d2125] dark:bg-[#101111]"
                    />
                </label>
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded-md bg-teal-300 px-3 py-1.5 text-xs font-bold text-zinc-950 transition hover:brightness-105 disabled:cursor-wait disabled:opacity-60"
                        :disabled="composeForm.processing"
                    >
                        <Send class="size-3.5" />
                        {{ composeForm.processing ? 'Sending…' : 'Send' }}
                    </button>
                    <span
                        v-if="
                            composeForm.errors.text || composeForm.errors.from
                        "
                        class="text-xs text-red-500"
                    >
                        {{ composeForm.errors.text || composeForm.errors.from }}
                    </span>
                </div>
            </form>
        </div>
    </div>
    <Toaster />
</template>
