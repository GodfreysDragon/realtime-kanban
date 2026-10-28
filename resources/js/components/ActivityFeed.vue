<script setup>
import { inject, onBeforeUnmount, ref } from 'vue';
import UserAvatar from './UserAvatar.vue';

const emit = defineEmits(['close']);
const board = inject('board');

const now = ref(Date.now());
const ticker = setInterval(() => (now.value = Date.now()), 30_000);
onBeforeUnmount(() => clearInterval(ticker));

const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto', style: 'short' });

function ago(iso) {
    const seconds = Math.round((new Date(iso).getTime() - now.value) / 1000);
    if (seconds > -45) return 'just now';
    if (seconds > -3600) return format.format(Math.round(seconds / 60), 'minute');
    if (seconds > -86400) return format.format(Math.round(seconds / 3600), 'hour');
    return format.format(Math.round(seconds / 86400), 'day');
}
</script>

<template>
    <div class="p-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold">Activity</h2>
            <button class="grid size-6 place-items-center rounded text-slate-500 dark:text-slate-400 hover:text-slate-900 lg:hidden dark:hover:text-white" aria-label="Close activity" @click="emit('close')">✕</button>
        </div>

        <p v-if="!board.activities.value.length" class="mt-4 text-sm text-slate-500">Nothing has happened yet.</p>

        <TransitionGroup tag="ol" class="mt-3 space-y-3" enter-from-class="-translate-y-1 opacity-0" enter-active-class="transition duration-300" data-testid="activity-feed">
            <li v-for="activity in board.activities.value" :key="activity.id" class="flex gap-2 text-sm">
                <UserAvatar v-if="activity.user" :user="activity.user" size="xs" class="mt-0.5" />
                <p class="min-w-0 break-words">
                    <span class="font-medium">{{ activity.user?.name ?? 'Someone' }}</span>
                    {{ activity.description }}
                    <time class="block text-xs text-slate-500 dark:text-slate-400" :datetime="activity.created_at">{{ ago(activity.created_at) }}</time>
                </p>
            </li>
        </TransitionGroup>
    </div>
</template>
