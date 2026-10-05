<script setup>
import { computed, inject } from 'vue';

const board = inject('board');
const me = inject('me');

const visible = computed(() =>
    Object.entries(board.cursors)
        .filter(([userId]) => Number(userId) !== me.id)
        .map(([userId, cursor]) => ({ user: board.membersById.value[userId], ...cursor }))
        .filter((cursor) => cursor.user),
);
</script>

<template>
    <div class="pointer-events-none absolute inset-0 z-20 overflow-hidden" aria-hidden="true">
        <div
            v-for="cursor in visible"
            :key="cursor.user.id"
            class="absolute top-0 left-0 transition-transform duration-75 ease-linear"
            :style="{ transform: `translate(${cursor.x}px, ${cursor.y}px)` }"
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
