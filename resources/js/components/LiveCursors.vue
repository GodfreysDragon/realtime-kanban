<script setup>
import { computed, inject, ref } from 'vue';

const board = inject('board');
const me = inject('me');
const layer = ref(null);

const visible = computed(() =>
    Object.entries(board.cursors)
        .filter(([userId]) => Number(userId) !== me.id)
        .map(([userId, cursor]) => ({ user: board.membersById.value[userId], ...cursor }))
        .filter((cursor) => cursor.user),
);

/**
 * Where a cursor goes on this screen: on the same card, or the same spot in
 * the same column, as the sender pointed at, measured in this viewer's own
 * layout; the sender's board coordinates if neither is here.
 */
function place(cursor) {
    const surface = layer.value?.parentElement;
    if (!surface) return { x: cursor.x, y: cursor.y };
    const origin = surface.getBoundingClientRect();

    const card = cursor.card != null && surface.querySelector(`[data-card-id="${cursor.card}"]`);
    if (card) {
        const rect = card.getBoundingClientRect();
        return { x: rect.left - origin.left + cursor.fx * rect.width, y: rect.top - origin.top + cursor.fy * rect.height };
    }
    const column = cursor.column != null && surface.querySelector(`section[data-column-id="${cursor.column}"]`);
    if (column) {
        const rect = column.getBoundingClientRect();
        return { x: rect.left - origin.left + cursor.dx, y: rect.top - origin.top + cursor.dy };
    }
    return { x: cursor.x, y: cursor.y };
}

function transform(cursor) {
    const { x, y } = place(cursor);
    return `translate(${Math.round(x)}px, ${Math.round(y)}px)`;
}
</script>

<template>
    <div ref="layer" class="pointer-events-none absolute inset-0 z-20 overflow-hidden" aria-hidden="true">
        <div
            v-for="cursor in visible"
            :key="cursor.user.id"
            class="absolute top-0 left-0 transition-transform duration-75 ease-linear"
            data-testid="live-cursor"
            :style="{ transform: transform(cursor) }"
        >
            <svg width="18" height="18" viewBox="0 0 18 18" class="drop-shadow">
                <path d="M2 1 L16 8 L9 10 L6 17 Z" :fill="cursor.user.color" stroke="white" stroke-width="1.5" stroke-linejoin="round" />
            </svg>
            <span class="ml-3 rounded px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap text-white" :style="{ backgroundColor: cursor.user.color }">
                {{ cursor.user.name }}
            </span>
        </div>
    </div>
</template>
