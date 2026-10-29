<script setup>
import { computed } from 'vue';
import { useToasts } from '../composables/useToasts';

const { toasts, dismiss, hold, release } = useToasts();

// Two live regions, present from the start so screen readers watch them:
// errors interrupt, everything else waits its turn.
const errors = computed(() => toasts.filter((toast) => toast.tone === 'error'));
const notices = computed(() => toasts.filter((toast) => toast.tone !== 'error'));
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex flex-col items-center gap-2 px-4">
        <div v-for="[list, live] in [[errors, 'assertive'], [notices, 'polite']]" :key="live" class="flex flex-col items-center gap-2" :aria-live="live" :role="live === 'assertive' ? 'alert' : 'status'">
            <TransitionGroup
                enter-from-class="translate-y-2 opacity-0"
                enter-active-class="transition duration-200"
                leave-to-class="opacity-0"
                leave-active-class="transition duration-150"
            >
                <div
                    v-for="toast in list"
                    :key="toast.id"
                    class="pointer-events-auto flex max-w-md items-start gap-3 rounded-lg py-2.5 pr-2 pl-4 text-sm shadow-lg"
                    :class="toast.tone === 'error' ? 'bg-red-600 text-white' : 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'"
                    data-testid="toast"
                    @mouseenter="hold(toast.id)"
                    @mouseleave="release(toast.id)"
                    @focusin="hold(toast.id)"
                    @focusout="release(toast.id)"
                >
                    <span class="py-0.5">{{ toast.message }}</span>
                    <button type="button" class="grid size-6 shrink-0 place-items-center rounded opacity-80 hover:opacity-100" aria-label="Dismiss" @click="dismiss(toast.id)">✕</button>
                </div>
            </TransitionGroup>
        </div>
    </div>
</template>
