<script setup>
import { computed, inject } from 'vue';
import { LABELS } from '../lib/labels';
import UserAvatar from './UserAvatar.vue';

const props = defineProps({
    card: { type: Object, required: true },
});
const emit = defineEmits(['open']);

const board = inject('board');
const dnd = inject('dnd');
const me = inject('me');
const cardMoves = inject('cardMoves');

const ARROWS = { ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'left', ArrowRight: 'right' };

/** Other people whose whispered state points at this card. */
function peopleWhere(map) {
    return Object.entries(map)
        .filter(([userId, cardId]) => cardId === props.card.id && Number(userId) !== me.id)
        .map(([userId]) => board.membersById.value[userId])
        .filter(Boolean);
}

const editors = computed(() => peopleWhere(board.editing));
const movers = computed(() => peopleWhere(board.dragging));
const busyWith = computed(() => movers.value[0] ?? editors.value[0] ?? null);
const label = computed(() => LABELS[props.card.label]);

function onKeydown(event) {
    const direction = ARROWS[event.key];
    if (!direction || !event.altKey) return;
    event.preventDefault();
    cardMoves.moveByKey(props.card, direction);
}
</script>

<template>
    <li
        :data-card-id="card.id"
        tabindex="0"
        role="button"
        aria-describedby="card-move-hint"
        class="group relative shrink-0 cursor-grab rounded-lg select-none [-webkit-touch-callout:none] border bg-white px-3 py-2 text-sm shadow-xs transition hover:border-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 active:cursor-grabbing dark:bg-slate-800 dark:hover:border-slate-600"
        :class="[dnd.cardId === card.id ? 'opacity-40' : '', busyWith ? 'border-transparent ring-2' : 'border-slate-200 dark:border-slate-700']"
        :style="busyWith ? { '--tw-ring-color': busyWith.color } : null"
        @click="emit('open')"
        @keydown.enter.prevent="emit('open')"
        @keydown.space.prevent="emit('open')"
        @keydown="onKeydown"
        @pointerdown="cardMoves.startDrag($event, card, $event.currentTarget)"
    >
        <span v-if="label" class="mb-1.5 inline-block rounded px-1.5 py-0.5 text-[11px] font-medium" :class="label.soft">{{ label.name }}</span>
        <p class="break-words">{{ card.title }}</p>
        <p v-if="card.description" class="mt-1 text-xs text-slate-500 dark:text-slate-400" aria-label="Has description">≡</p>

        <div
            v-if="busyWith"
            class="absolute -top-2.5 right-2 flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium text-white shadow"
            :style="{ backgroundColor: busyWith.color }"
        >
            <UserAvatar :user="busyWith" size="xs" class="ring-0!" />
            {{ busyWith.name }} {{ movers.length ? 'is moving' : 'is editing' }}
        </div>
    </li>
</template>
