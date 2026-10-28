<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../lib/api';

const boards = ref([]);
const loading = ref(true);
const name = ref('');
const error = ref('');

const loadError = ref('');

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await api('GET', '/api/boards');
        boards.value = data;
    } catch (e) {
        loadError.value = e.data?.message ?? 'Could not load your boards.';
    } finally {
        loading.value = false;
    }
}

async function create() {
    if (!name.value.trim()) return;
    error.value = '';

    try {
        const { data } = await api('POST', '/api/boards', { name: name.value });
        window.location.hash = `#/boards/${data.id}`;
    } catch (e) {
        error.value = e.data?.errors?.name?.[0] ?? e.message;
    }
}

onMounted(load);
</script>

<template>
    <div class="mx-auto max-w-4xl p-4 sm:p-8">
        <h1 class="text-xl font-semibold tracking-tight">Boards</h1>

        <form class="mt-4 flex gap-2" @submit.prevent="create">
            <input
                v-model="name"
                maxlength="80"
                placeholder="New board name"
                aria-label="New board name"
                class="min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-900"
            />
            <button type="submit" :disabled="!name.trim()" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                Create
            </button>
        </form>
        <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>

        <p v-if="loading" class="mt-8 text-sm text-slate-500">Loading…</p>
        <p v-else-if="loadError" class="mt-8 text-sm text-slate-500" role="alert">
            {{ loadError }}
            <button type="button" class="ml-1 text-indigo-600 hover:underline" @click="load">Try again</button>
        </p>
        <p v-else-if="!boards.length" class="mt-8 text-sm text-slate-500">No boards yet. Create one above, or open an invite link someone shared with you.</p>

        <ul v-else class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="board in boards" :key="board.id">
                <a
                    :href="`#/boards/${board.id}`"
                    class="block rounded-lg border border-slate-200 bg-white p-4 hover:border-indigo-400 hover:shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:hover:border-indigo-500"
                >
                    <span class="flex items-center gap-2 font-medium">
                        {{ board.name }}
                        <span v-if="board.public" class="rounded bg-sky-50 px-1.5 text-xs font-normal text-sky-800 dark:bg-sky-500/10 dark:text-sky-200">Public</span>
                    </span>
                    <span class="mt-1 block text-xs text-slate-500">{{ board.cards_count }} {{ board.cards_count === 1 ? 'card' : 'cards' }}</span>
                </a>
            </li>
        </ul>
    </div>
</template>
