<script setup>
import { useToasts } from '../composables/useToasts';

const { toasts, dismiss } = useToasts();
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex flex-col items-center gap-2 px-4" aria-live="polite">
        <TransitionGroup
            enter-from-class="translate-y-2 opacity-0"
            enter-active-class="transition duration-200"
            leave-to-class="opacity-0"
            leave-active-class="transition duration-150"
        >
            <button
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto max-w-md rounded-lg px-4 py-2.5 text-left text-sm shadow-lg"
                :class="toast.tone === 'error' ? 'bg-red-600 text-white' : 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'"
                @click="dismiss(toast.id)"
            >
                {{ toast.message }}
            </button>
        </TransitionGroup>
    </div>
</template>
