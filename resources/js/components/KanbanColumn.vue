<script setup>
import { computed, inject, nextTick, ref } from 'vue';
import KanbanCard from './KanbanCard.vue';

const props = defineProps({
    column: { type: Object, required: true },
    cards: { type: Array, required: true },
});
const emit = defineEmits(['open']);

const board = inject('board');
const dnd = inject('dnd');
const list = ref(null);

// Cards other than the one being dragged; drop indexes are relative to these.
const others = computed(() => props.cards.filter((card) => card.id !== dnd.cardId));
const isTarget = computed(() => dnd.cardId !== null && dnd.overColumnId === props.column.id);

// --- drag and drop (native HTML5) ----------------------------------------

function onDragOver(event) {
    if (dnd.cardId === null) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';

    const elements = [...list.value.querySelectorAll('[data-card-id]')].filter((el) => Number(el.dataset.cardId) !== dnd.cardId);
    const index = elements.filter((el) => {
        const rect = el.getBoundingClientRect();
        return rect.top + rect.height / 2 < event.clientY;
    }).length;

    dnd.overColumnId = props.column.id;
    dnd.overIndex = index;
}

function onDragLeave(event) {
    if (!list.value.contains(event.relatedTarget) && dnd.overColumnId === props.column.id) {
        dnd.overColumnId = null;
        dnd.overIndex = null;
    }
}

function onDrop(event) {
    event.preventDefault();
    if (dnd.cardId === null || dnd.overIndex === null) return;

    const afterId = others.value[dnd.overIndex - 1]?.id ?? null;
    board.moveCard(dnd.cardId, props.column.id, afterId);
    dnd.overColumnId = null;
    dnd.overIndex = null;
}

// --- add card -------------------------------------------------------------

const adding = ref(false);
const newTitle = ref('');
const titleInput = ref(null);

async function startAdding() {
    adding.value = true;
    await nextTick();
    titleInput.value?.focus();
}

async function addCard() {
    const title = newTitle.value.trim();
    if (!title) return;
    newTitle.value = '';
    await board.createCard(props.column.id, title);
    await nextTick();
    list.value?.scrollTo({ top: list.value.scrollHeight, behavior: 'smooth' });
}

// --- column menu ----------------------------------------------------------

const renaming = ref(false);
const nameDraft = ref('');
const nameInput = ref(null);

async function startRename() {
    nameDraft.value = props.column.name;
    renaming.value = true;
    await nextTick();
    nameInput.value?.select();
}

function finishRename() {
    const name = nameDraft.value.trim();
    renaming.value = false;
    if (name && name !== props.column.name) board.renameColumn(props.column.id, name);
}

function remove() {
    const count = props.cards.length;
    const message = count ? `Delete “${props.column.name}” and its ${count} ${count === 1 ? 'card' : 'cards'}?` : `Delete “${props.column.name}”?`;
    if (window.confirm(message)) board.deleteColumn(props.column.id);
}
</script>

<template>
    <section class="flex max-h-[calc(100vh-9rem)] w-72 shrink-0 flex-col rounded-xl bg-slate-100 dark:bg-slate-900" :data-column-id="column.id">
        <header class="group flex items-center gap-2 px-3 pt-3 pb-2">
            <input
                v-if="renaming"
                ref="nameInput"
                v-model="nameDraft"
                maxlength="60"
                class="min-w-0 flex-1 rounded border border-indigo-400 bg-white px-1.5 py-0.5 text-sm font-semibold outline-none dark:bg-slate-950"
                @keydown.enter.prevent="finishRename"
                @keydown.esc="renaming = false"
                @blur="finishRename"
            />
            <h2 v-else class="min-w-0 flex-1 cursor-text truncate text-sm font-semibold" title="Click to rename" @click="startRename">{{ column.name }}</h2>
            <span class="rounded-full bg-slate-200 px-2 text-xs text-slate-600 tabular-nums dark:bg-slate-800 dark:text-slate-300">{{ cards.length }}</span>
            <button
                class="rounded p-0.5 text-slate-400 opacity-0 group-hover:opacity-100 hover:bg-slate-200 hover:text-red-600 focus:opacity-100 dark:hover:bg-slate-800"
                :aria-label="`Delete column ${column.name}`"
                @click="remove"
            >
                ✕
            </button>
        </header>

        <ol
            ref="list"
            class="flex min-h-12 flex-1 flex-col gap-2 overflow-y-auto px-2 pb-2 transition-colors"
            :class="isTarget ? 'bg-indigo-50/60 dark:bg-indigo-500/5' : ''"
            @dragover="onDragOver"
            @dragleave="onDragLeave"
            @drop="onDrop"
        >
            <template v-for="card in cards" :key="card.id">
                <li v-if="isTarget && others[dnd.overIndex] && others[dnd.overIndex].id === card.id" class="h-1 shrink-0 rounded-full bg-indigo-500" aria-hidden="true" />
                <KanbanCard :card="card" @open="emit('open', card.id)" />
            </template>
            <li v-if="isTarget && dnd.overIndex >= others.length" class="h-1 shrink-0 rounded-full bg-indigo-500" aria-hidden="true" />
        </ol>

        <div class="px-2 pb-2">
            <form v-if="adding" @submit.prevent="addCard">
                <textarea
                    ref="titleInput"
                    v-model="newTitle"
                    rows="2"
                    maxlength="255"
                    placeholder="Card title"
                    class="w-full resize-none rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-sm shadow-sm outline-none focus:border-indigo-500 dark:border-slate-700 dark:bg-slate-950"
                    @keydown.enter.exact.prevent="addCard"
                    @keydown.esc="adding = false"
                />
                <div class="mt-1 flex gap-2">
                    <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1 text-sm font-medium text-white hover:bg-indigo-500">Add</button>
                    <button type="button" class="rounded-md px-2 py-1 text-sm text-slate-500 hover:bg-slate-200 dark:hover:bg-slate-800" @click="adding = false">Cancel</button>
                </div>
            </form>
            <button
                v-else
                class="w-full rounded-lg px-2 py-1.5 text-left text-sm text-slate-500 hover:bg-slate-200 hover:text-slate-900 dark:hover:bg-slate-800 dark:hover:text-white"
                @click="startAdding"
            >
                + Add card
            </button>
        </div>
    </section>
</template>
