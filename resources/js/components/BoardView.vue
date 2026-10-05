<script setup>
import { computed, nextTick, onBeforeUnmount, provide, reactive, ref } from 'vue';
import { useBoard } from '../composables/useBoard';
import KanbanColumn from './KanbanColumn.vue';
import CardModal from './CardModal.vue';
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
const showActivity = ref(window.matchMedia('(min-width: 1024px)').matches);

provide('board', board);
provide('dnd', dnd);
provide('me', props.me);

onBeforeUnmount(() => board.dispose());

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
        <div v-if="board.error.value && !board.board.value" class="p-8 text-center text-sm text-slate-500">
            {{ board.error.value }}
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
                <h1 v-else class="cursor-text rounded-md px-2 py-0.5 text-lg font-semibold hover:bg-slate-100 dark:hover:bg-slate-800" title="Click to rename" @click="startRename">
                    {{ board.board.value.name }}
                </h1>

                <div class="ml-auto flex items-center gap-3">
                    <div class="flex -space-x-1.5" :aria-label="`${presence.length} online`">
                        <UserAvatar v-for="member in presence.slice(0, 6)" :key="member.id" :user="member" size="sm" />
                        <span v-if="presence.length > 6" class="grid size-7 place-items-center rounded-full bg-slate-200 text-xs font-medium ring-2 ring-white dark:bg-slate-700 dark:ring-slate-900">
                            +{{ presence.length - 6 }}
                        </span>
                    </div>
                    <ConnectionBadge :status="board.status.value" />
                    <button
                        class="rounded-md border border-slate-200 px-2.5 py-1 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800"
                        :aria-pressed="showActivity"
                        @click="showActivity = !showActivity"
                    >
                        Activity
                    </button>
                </div>
            </div>

            <div class="flex min-h-0 flex-1">
                <div class="min-w-0 flex-1 overflow-auto">
                    <div
                        ref="surface"
                        class="relative inline-flex min-h-full items-start gap-3 p-4"
                        @mousemove="trackPointer"
                        @dragover="trackPointer"
                        @mouseleave="hidePointer"
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
        </template>

        <div v-else class="p-8 text-sm text-slate-500">Loading board…</div>
    </div>
</template>
