<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, required: true },
});

// pusher-js connection states.
const appearance = computed(
    () =>
        ({
            connected: { text: 'Live', dot: 'bg-emerald-500' },
            connecting: { text: 'Connecting…', dot: 'bg-amber-400 animate-pulse' },
            initialized: { text: 'Connecting…', dot: 'bg-amber-400 animate-pulse' },
            unavailable: { text: 'Offline, retrying', dot: 'bg-red-500 animate-pulse' },
            failed: { text: 'WebSockets unsupported', dot: 'bg-red-500' },
            disconnected: { text: 'Disconnected', dot: 'bg-slate-400' },
        })[props.status] ?? { text: props.status, dot: 'bg-slate-400' },
);
</script>

<template>
    <span class="inline-flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400" role="status" data-testid="connection-status">
        <span class="size-2 rounded-full" :class="appearance.dot" />
        {{ appearance.text }}
    </span>
</template>
