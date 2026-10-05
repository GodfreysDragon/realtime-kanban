<script setup>
import { computed, inject, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { LABELS } from '../lib/labels';
import { useToasts } from '../composables/useToasts';
import UserAvatar from './UserAvatar.vue';

const props = defineProps({
    cardId: { type: Number, required: true },
});
const emit = defineEmits(['close']);

const board = inject('board');
const me = inject('me');
const { push: toast } = useToasts();

const card = computed(() => board.cards[props.cardId]);

// The draft is what I'm typing; baseVersion is the card version it started
// from. Saving sends baseVersion, and the server rejects the save if the card
// has moved on since then.
const draft = reactive({ title: '', description: '', label: null });
const baseVersion = ref(0);
const remoteEdit = ref(false);
const conflict = ref(null);
const saving = ref(false);
const titleInput = ref(null);

function resetFrom(source) {
    draft.title = source.title;
    draft.description = source.description ?? '';
    draft.label = source.label;
    baseVersion.value = source.version;
    remoteEdit.value = false;
}

const dirty = computed(() => {
    const current = card.value;
    if (!current) return false;
    return draft.title !== current.title || draft.description !== (current.description ?? '') || draft.label !== current.label;
});

const coEditors = computed(() =>
    Object.entries(board.editing)
        .filter(([userId, cardId]) => cardId === props.cardId && Number(userId) !== me.id)
        .map(([userId]) => board.membersById.value[userId])
        .filter(Boolean),
);

const lastEditor = computed(() => board.membersById.value[card.value?.updated_by]);

// React to other people's changes while the dialog is open.
watch(
    () => card.value && { ...card.value },
    (current) => {
        if (!current) {
            toast('That card was deleted by someone else.', 'error');
            emit('close');
            return;
        }
        if (current.version === baseVersion.value) return;
        // Pristine draft: just follow along. Dirty draft: keep the user's
        // text and flag it; the save will hit the version check.
        if (!dirty.value) resetFrom(current);
        else remoteEdit.value = true;
    },
);

async function save() {
    if (!draft.title.trim()) return;
    if (!dirty.value && baseVersion.value === card.value.version) return emit('close');

    saving.value = true;
    try {
        await board.updateCard(props.cardId, { title: draft.title.trim(), description: draft.description || null, label: draft.label }, baseVersion.value);
        emit('close');
    } catch (e) {
        if (e.status === 409) conflict.value = e.data;
        else toast(e.data?.message ?? 'Could not save the card.', 'error');
    } finally {
        saving.value = false;
    }
}

function useTheirs() {
    resetFrom(card.value);
    conflict.value = null;
}

function keepMine() {
    // Rebase my draft onto their version, which overwrites their change on save.
    baseVersion.value = conflict.value.card.version;
    conflict.value = null;
    save();
}

function remove() {
    if (!window.confirm('Delete this card?')) return;
    board.deleteCard(props.cardId);
    emit('close');
}

function moveTo(columnId) {
    const target = board.cardsByColumn.value[columnId] ?? [];
    const last = target.filter((c) => c.id !== props.cardId).at(-1);
    board.moveCard(props.cardId, Number(columnId), last?.id ?? null);
}

function onKeydown(event) {
    if (event.key === 'Escape') emit('close');
}

onMounted(() => {
    resetFrom(card.value);
    board.setEditing(props.cardId);
    window.addEventListener('keydown', onKeydown);
    titleInput.value?.focus();
});

onBeforeUnmount(() => {
    board.setEditing(null);
    window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div class="fixed inset-0 z-40 grid place-items-center bg-slate-950/40 p-4" @mousedown.self="emit('close')">
        <form
            role="dialog"
            aria-modal="true"
            aria-labelledby="card-title"
            class="w-full max-w-lg rounded-xl bg-white p-5 shadow-2xl dark:bg-slate-900"
            @submit.prevent="save"
        >
            <div v-if="coEditors.length" class="mb-3 flex items-center gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                <div class="flex -space-x-1"><UserAvatar v-for="user in coEditors" :key="user.id" :user="user" size="xs" /></div>
                {{ coEditors.map((u) => u.name).join(', ') }} {{ coEditors.length === 1 ? 'is' : 'are' }} also editing this card.
            </div>

            <div v-if="remoteEdit && !conflict" class="mb-3 rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:bg-sky-500/10 dark:text-sky-200">
                {{ lastEditor?.name ?? 'Someone' }} just saved changes to this card. Your edits are kept; saving will ask how to resolve them.
            </div>

            <div v-if="conflict" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm dark:border-red-500/30 dark:bg-red-500/10">
                <p class="font-medium text-red-900 dark:text-red-200">{{ conflict.editor?.name ?? 'Someone' }} changed this card while you were editing it.</p>
                <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-slate-700 dark:text-slate-300">
                    <dt class="text-slate-500">Theirs</dt>
                    <dd class="break-words">{{ conflict.card.title }}<span v-if="conflict.card.description" class="text-slate-500"> · {{ conflict.card.description }}</span></dd>
                    <dt class="text-slate-500">Yours</dt>
                    <dd class="break-words">{{ draft.title }}<span v-if="draft.description" class="text-slate-500"> · {{ draft.description }}</span></dd>
                </dl>
                <div class="mt-3 flex gap-2">
                    <button type="button" class="rounded-md bg-white px-3 py-1 font-medium shadow-sm ring-1 ring-slate-300 hover:bg-slate-50 dark:bg-slate-800 dark:ring-slate-600" @click="useTheirs">
                        Use theirs
                    </button>
                    <button type="button" class="rounded-md bg-red-600 px-3 py-1 font-medium text-white hover:bg-red-500" @click="keepMine">Overwrite with mine</button>
                </div>
            </div>

            <label for="card-title" class="sr-only">Title</label>
            <input
                id="card-title"
                ref="titleInput"
                v-model="draft.title"
                maxlength="255"
                class="w-full rounded-md border border-transparent px-2 py-1 text-lg font-semibold outline-none hover:border-slate-200 focus:border-indigo-500 dark:bg-slate-900 dark:hover:border-slate-700"
            />

            <label for="card-description" class="mt-3 block text-xs font-medium tracking-wide text-slate-500 uppercase">Description</label>
            <textarea
                id="card-description"
                v-model="draft.description"
                rows="5"
                maxlength="5000"
                placeholder="Add more detail…"
                class="mt-1 w-full rounded-md border border-slate-200 bg-white px-2.5 py-2 text-sm outline-none focus:border-indigo-500 dark:border-slate-700 dark:bg-slate-950"
            />

            <fieldset class="mt-3">
                <legend class="text-xs font-medium tracking-wide text-slate-500 uppercase">Label</legend>
                <div class="mt-1 flex flex-wrap gap-1.5">
                    <button
                        type="button"
                        class="rounded px-2 py-0.5 text-xs ring-1 ring-slate-200 dark:ring-slate-700"
                        :class="draft.label === null ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : ''"
                        @click="draft.label = null"
                    >
                        None
                    </button>
                    <button
                        v-for="(meta, key) in LABELS"
                        :key="key"
                        type="button"
                        class="rounded px-2 py-0.5 text-xs font-medium"
                        :class="[meta.soft, draft.label === key ? 'ring-2 ring-slate-900 dark:ring-white' : '']"
                        :aria-pressed="draft.label === key"
                        @click="draft.label = key"
                    >
                        {{ meta.name }}
                    </button>
                </div>
            </fieldset>

            <label for="card-column" class="mt-3 block text-xs font-medium tracking-wide text-slate-500 uppercase">Column</label>
            <select
                id="card-column"
                :value="card.column_id"
                class="mt-1 rounded-md border border-slate-200 bg-white px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-950"
                @change="moveTo($event.target.value)"
            >
                <option v-for="column in board.columns.value" :key="column.id" :value="column.id">{{ column.name }}</option>
            </select>

            <div class="mt-5 flex items-center gap-2">
                <button type="button" class="rounded-md px-2 py-1.5 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10" @click="remove">Delete</button>
                <span class="ml-auto text-xs text-slate-400">v{{ card.version }}</span>
                <button type="button" class="rounded-md px-3 py-1.5 text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="emit('close')">Cancel</button>
                <button
                    type="submit"
                    :disabled="saving || !draft.title.trim() || !!conflict"
                    class="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                >
                    {{ saving ? 'Saving…' : 'Save' }}
                </button>
            </div>
        </form>
    </div>
</template>
