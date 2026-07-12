<script setup lang="ts">
import { Placeholder } from '@tiptap/extensions';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { Bold, Italic, List, ListOrdered, TextQuote } from 'lucide-vue-next';
import { onBeforeUnmount, watch } from 'vue';

const props = defineProps<{
    modelValue: string;
    placeholder?: string;
    minHeight?: string;
}>();

const emit = defineEmits<{
    (event: 'update:modelValue', html: string): void;
    (event: 'update:text', text: string): void;
    (event: 'submit'): void;
}>();

const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit.configure({
            heading: false,
            codeBlock: false,
            horizontalRule: false,
        }),
        Placeholder.configure({
            placeholder: props.placeholder ?? 'Write a message…',
        }),
    ],
    editorProps: {
        attributes: {
            class: 'prose-mail focus:outline-none',
            style: `min-height: ${props.minHeight ?? '72px'}`,
        },
        handleKeyDown: (_view, event) => {
            if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                emit('submit');

                return true;
            }

            return false;
        },
    },
    onUpdate({ editor: instance }) {
        const text = instance.getText().trim();
        emit('update:modelValue', text ? instance.getHTML() : '');
        emit('update:text', text);
    },
});

// External writes (draft restore, reset after send) flow back in; skip
// echoes of the editor's own updates to preserve cursor position.
watch(
    () => props.modelValue,
    (value) => {
        if (!editor.value) {
            return;
        }

        const current = editor.value.getText().trim()
            ? editor.value.getHTML()
            : '';

        if (value !== current) {
            editor.value.commands.setContent(value || '');
        }
    },
);

function focus(): void {
    editor.value?.commands.focus('end');
}

defineExpose({ focus });

onBeforeUnmount(() => editor.value?.destroy());

const tools = [
    {
        icon: Bold,
        title: 'Bold (⌘B)',
        mark: 'bold',
        run: () => editor.value?.chain().focus().toggleBold().run(),
    },
    {
        icon: Italic,
        title: 'Italic (⌘I)',
        mark: 'italic',
        run: () => editor.value?.chain().focus().toggleItalic().run(),
    },
    {
        icon: List,
        title: 'Bullet list',
        mark: 'bulletList',
        run: () => editor.value?.chain().focus().toggleBulletList().run(),
    },
    {
        icon: ListOrdered,
        title: 'Numbered list',
        mark: 'orderedList',
        run: () => editor.value?.chain().focus().toggleOrderedList().run(),
    },
    {
        icon: TextQuote,
        title: 'Quote',
        mark: 'blockquote',
        run: () => editor.value?.chain().focus().toggleBlockquote().run(),
    },
];
</script>

<template>
    <div
        class="grid rounded-md border border-zinc-200 bg-white transition focus-within:border-teal-400 focus-within:ring-2 focus-within:ring-teal-300/20 dark:border-[#1d2125] dark:bg-[#101111]"
    >
        <div
            class="flex items-center gap-0.5 border-b border-zinc-100 px-1.5 py-1 dark:border-[#16191c]"
        >
            <button
                v-for="tool in tools"
                :key="tool.title"
                type="button"
                tabindex="-1"
                :title="tool.title"
                class="rounded p-1.5 transition"
                :class="
                    editor?.isActive(tool.mark)
                        ? 'bg-teal-50 text-zinc-950 dark:bg-teal-400/10 dark:text-zinc-100'
                        : 'text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-[#16191c] dark:hover:text-zinc-300'
                "
                @mousedown.prevent="tool.run()"
            >
                <component :is="tool.icon" class="size-3.5" />
            </button>
            <slot name="toolbar-end" />
        </div>
        <EditorContent :editor="editor" class="px-3 py-2.5" />
    </div>
</template>

<style>
.prose-mail {
    font-size: 13px;
    line-height: 1.6;
}
.prose-mail p {
    margin: 0 0 0.5em;
}
.prose-mail p:last-child {
    margin-bottom: 0;
}
.prose-mail ul,
.prose-mail ol {
    margin: 0 0 0.5em;
    padding-left: 1.4em;
}
.prose-mail ul {
    list-style: disc;
}
.prose-mail ol {
    list-style: decimal;
}
.prose-mail blockquote {
    margin: 0 0 0.5em;
    padding-left: 0.8em;
    border-left: 2px solid rgb(94 234 212);
    color: rgb(113 113 122);
}
.prose-mail a {
    color: rgb(13 148 136);
    text-decoration: underline;
}
.prose-mail p.is-editor-empty:first-child::before {
    content: attr(data-placeholder);
    float: left;
    height: 0;
    color: rgb(161 161 170);
    pointer-events: none;
}
</style>
