<script setup>
import { computed, nextTick, onBeforeUnmount, provide, reactive, ref, watch } from 'vue';
import { useBoard } from '../composables/useBoard';
import { useToasts } from '../composables/useToasts';
import { startPointerDrag } from '../lib/pointerDrag';
import KanbanColumn from './KanbanColumn.vue';
import CardModal from './CardModal.vue';
import ShareDialog from './ShareDialog.vue';
import ActivityFeed from './ActivityFeed.vue';
import LiveCursors from './LiveCursors.vue';
import ConnectionBadge from './ConnectionBadge.vue';
import UserAvatar from './UserAvatar.vue';

const props = defineProps({
    boardId: { type: Number, required: true },
    me: { type: Object, required: true },
});

const board = useBoard(props.boardId, props.me);
const dnd = reactive({ cardId: null, overColumnId: null, overIndex: null });
const openCardId = ref(null);
const showShare = ref(false);
const showActivity = ref(window.matchMedia('(min-width: 1024px)').matches);

provide('board', board);
provide('dnd', dnd);
provide('me', props.me);
provide('cardMoves', { startDrag: startCardDrag, moveByKey: moveCardByKey });

// --- moving cards: pointer drag ----------------------------------------------

function startCardDrag(event, card, element) {
    startPointerDrag(event, element, {
        onStart() {
            dnd.cardId = card.id;
            board.setDragging(card.id);
        },
        onMove: hitTestCards,
        onDrop() {
            if (dnd.overColumnId === null) return;
            const others = (board.cardsByColumn.value[dnd.overColumnId] ?? []).filter((c) => c.id !== card.id);
            board.moveCard(card.id, dnd.overColumnId, others[dnd.overIndex - 1]?.id ?? null);
        },
        onEnd() {
            Object.assign(dnd, { cardId: null, overColumnId: null, overIndex: null });
            board.setDragging(null);
        },
    });
}

/** Which column, and which slot in it, is under the pointer. */
function hitTestCards(x, y) {
    const section = document.elementFromPoint(x, y)?.closest('section[data-column-id]');
    if (!section) {
        Object.assign(dnd, { overColumnId: null, overIndex: null });
        return;
    }

    const cardsAbove = [...section.querySelectorAll('[data-card-id]')].filter((el) => {
        if (Number(el.dataset.cardId) === dnd.cardId) return false;
        const rect = el.getBoundingClientRect();
        return rect.top + rect.height / 2 < y;
    });
    Object.assign(dnd, { overColumnId: Number(section.dataset.columnId), overIndex: cardsAbove.length });
}

// --- moving cards: keyboard --------------------------------------------------

const announcement = ref('');

/** Alt+Arrow on a focused card: up/down within its column, left/right to the next column. */
async function moveCardByKey(card, direction) {
    const columns = board.columns.value;
    const columnIndex = columns.findIndex((column) => column.id === card.column_id);
    const siblings = board.cardsByColumn.value[card.column_id] ?? [];
    const index = siblings.findIndex((c) => c.id === card.id);

    let target = columns[columnIndex];
    let afterId;
    if (direction === 'up') {
        if (index === 0) return;
        afterId = siblings[index - 2]?.id ?? null;
    } else if (direction === 'down') {
        if (index === siblings.length - 1) return;
        afterId = siblings[index + 1].id;
    } else {
        target = columns[columnIndex + (direction === 'left' ? -1 : 1)];
        if (!target) return;
        const targetCards = board.cardsByColumn.value[target.id] ?? [];
        afterId = targetCards[Math.min(index, targetCards.length) - 1]?.id ?? null;
    }

    board.moveCard(card.id, target.id, afterId);

    // A card that changed column is a new element; keep the keyboard on it.
    await nextTick();
    document.querySelector(`[data-card-id="${card.id}"]`)?.focus();
    const placed = board.cardsByColumn.value[target.id];
    announcement.value = `Moved “${card.title}” to position ${placed.findIndex((c) => c.id === card.id) + 1} of ${placed.length} in ${target.name}.`;
}

onBeforeUnmount(() => board.dispose());

const problem = computed(() => board.error.value ?? board.channelError.value);

// The open card vanished: someone else deleted it, or its column. (Deleting
// it yourself closes the dialog first, so this doesn't fire.)
const { push: toast } = useToasts();
watch(
    () => openCardId.value != null && !board.cards[openCardId.value],
    (gone) => {
        if (!gone) return;
        toast('That card was deleted by someone else.', 'error');
        openCardId.value = null;
    },
);

watch(board.removed, (removed) => {
    if (!removed) return;
    toast('You were removed from this board.', 'error');
    window.location.hash = '#/';
});

// Everyone else on the board, me first so I always see myself in the stack.
const presence = computed(() => [props.me, ...board.members.value.filter((m) => m.id !== props.me.id)]);

// --- inline board rename -------------------------------------------------

const renaming = ref(false);
const nameDraft = ref('');
const nameInput = ref(null);

async function startRename() {
    nameDraft.value = board.board.value.name;
    renaming.value = true;
    await nextTick();
    nameInput.value?.select();
}

function finishRename() {
    const name = nameDraft.value.trim();
    renaming.value = false;
    if (name && name !== board.board.value.name) board.renameBoard(name);
}

// --- new column ----------------------------------------------------------

const newColumnName = ref('');

function addColumn() {
    const name = newColumnName.value.trim();
    if (!name) return;
    newColumnName.value = '';
    board.addColumn(name);
}

// --- live cursor broadcast -----------------------------------------------

const surface = ref(null);
let lastCursorSentAt = 0;

/** Whisper my pointer position, in board-content coordinates, at most ~20×/s. */
function trackPointer(event) {
    const now = performance.now();
    if (now - lastCursorSentAt < 50) return;
    lastCursorSentAt = now;

    const rect = surface.value.getBoundingClientRect();
    board.sendCursor(Math.round(event.clientX - rect.left), Math.round(event.clientY - rect.top));
}

function hidePointer() {
    board.sendCursor(null, null);
}
</script>

<template>
    <div class="flex h-full flex-col">
        <div v-if="problem && !board.board.value" class="p-8 text-center text-sm text-slate-500" role="alert">
            {{ problem }}
            <a href="#/" class="ml-1 text-indigo-600 hover:underline">Back to boards</a>
        </div>

        <template v-else-if="board.board.value">
            <div class="flex shrink-0 flex-wrap items-center gap-3 border-b border-slate-200 px-4 py-2 dark:border-slate-800">
                <a href="#/" class="text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white" aria-label="All boards">←</a>

                <input
                    v-if="renaming"
                    ref="nameInput"
                    v-model="nameDraft"
                    maxlength="80"
                    class="rounded-md border border-indigo-400 bg-white px-2 py-0.5 text-lg font-semibold outline-none dark:bg-slate-900"
                    @keydown.enter.prevent="finishRename"
                    @keydown.esc="renaming = false"
                    @blur="finishRename"
                />
                <h1 v-else class="text-lg font-semibold">
                    <button type="button" class="cursor-text rounded-md px-2 py-0.5 text-left hover:bg-slate-100 dark:hover:bg-slate-800" title="Rename board" @click="startRename">
                        {{ board.board.value.name }}
                    </button>
                </h1>

                <div class="ml-auto flex items-center gap-3">
                    <div class="flex -space-x-1.5" role="group" :aria-label="`${presence.length} online`">
                        <UserAvatar v-for="member in presence.slice(0, 6)" :key="member.id" :user="member" size="sm" />
                        <span v-if="presence.length > 6" class="grid size-7 place-items-center rounded-full bg-slate-200 text-xs font-medium ring-2 ring-white dark:bg-slate-700 dark:ring-slate-900">
                            +{{ presence.length - 6 }}
                        </span>
                    </div>
                    <ConnectionBadge :status="board.status.value" />
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 px-2.5 py-1 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                        @click="showShare = true"
                    >
                        Share
                    </button>
                    <button
                        class="rounded-md border border-slate-200 px-2.5 py-1 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                        :aria-pressed="showActivity"
                        @click="showActivity = !showActivity"
                    >
                        Activity
                    </button>
                </div>
            </div>

            <div
                v-if="problem"
                role="alert"
                class="flex shrink-0 items-center gap-3 border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"
            >
                {{ problem }} The board may be out of date.
                <button v-if="board.error.value" type="button" class="font-medium underline" @click="board.load()">Retry</button>
            </div>

            <div class="flex min-h-0 flex-1">
                <div class="min-w-0 flex-1 overflow-auto">
                    <div
                        ref="surface"
                        class="relative inline-flex min-h-full items-start gap-3 p-4"
                        @pointermove="trackPointer"
                        @pointerleave="hidePointer"
                    >
                        <KanbanColumn
                            v-for="column in board.columns.value"
                            :key="column.id"
                            :column="column"
                            :cards="board.cardsByColumn.value[column.id] ?? []"
                            @open="openCardId = $event"
                        />

                        <form class="w-72 shrink-0 rounded-xl border-2 border-dashed border-slate-200 p-2 dark:border-slate-800" @submit.prevent="addColumn">
                            <input
                                v-model="newColumnName"
                                maxlength="60"
                                placeholder="+ Add column"
                                class="w-full rounded-md bg-transparent px-2 py-1.5 text-sm outline-none placeholder:text-slate-400 focus:bg-white dark:focus:bg-slate-900"
                            />
                        </form>

                        <LiveCursors />
                    </div>
                </div>

                <aside
                    v-if="showActivity"
                    class="fixed inset-y-0 right-0 z-30 w-72 shrink-0 overflow-y-auto border-l border-slate-200 bg-white shadow-xl lg:static lg:shadow-none dark:border-slate-800 dark:bg-slate-900"
                >
                    <ActivityFeed @close="showActivity = false" />
                </aside>
            </div>

            <CardModal v-if="openCardId && board.cards[openCardId]" :key="openCardId" :card-id="openCardId" @close="openCardId = null" />
            <ShareDialog v-if="showShare" @close="showShare = false" />
            <p id="card-move-hint" class="sr-only">Press Enter to open the card. Alt and the arrow keys move it.</p>
            <p class="sr-only" aria-live="assertive">{{ announcement }}</p>
        </template>

        <div v-else class="p-8 text-sm text-slate-500">Loading board…</div>
    </div>
</template>
