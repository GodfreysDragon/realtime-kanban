<script setup>
import { ref } from 'vue';
import { api } from '../lib/api';

const name = ref('');
const busy = ref(false);
const error = ref('');

async function join() {
    if (!name.value.trim()) return;
    busy.value = true;
    error.value = '';

    try {
        await api('POST', '/join', { name: name.value });
        // Logging in regenerates the session and CSRF token; reloading picks
        // up the new token and opens the WebSocket as the signed-in user.
        window.location.reload();
    } catch (e) {
        error.value = e.data?.errors?.name?.[0] ?? e.message;
        busy.value = false;
    }
}
</script>

<template>
    <div class="grid h-full place-items-center p-4">
        <form class="w-full max-w-sm rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900" @submit.prevent="join">
            <h1 class="text-lg font-semibold">Join the workspace</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Pick a display name. To see collaboration live, open a second browser (or a private window) and join as someone else.
            </p>
            <label class="mt-5 block text-sm font-medium" for="name">Display name</label>
            <input
                id="name"
                v-model="name"
                autofocus
                maxlength="40"
                class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-950"
                placeholder="e.g. Ada"
            />
            <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>
            <button
                type="submit"
                :disabled="busy || !name.trim()"
                class="mt-4 w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
            >
                {{ busy ? 'Joining…' : 'Join' }}
            </button>
        </form>
    </div>
</template>
