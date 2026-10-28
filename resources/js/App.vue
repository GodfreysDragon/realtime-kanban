<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from './lib/api';
import JoinScreen from './components/JoinScreen.vue';
import BoardList from './components/BoardList.vue';
import BoardView from './components/BoardView.vue';
import UserAvatar from './components/UserAvatar.vue';
import ToastStack from './components/ToastStack.vue';

const me = ref(null);
const ready = ref(false);
const startupError = ref('');
const boardId = ref(parseRoute());
const inviteError = ref('');
const joining = ref(false);

function parseRoute() {
    const match = window.location.hash.match(/^#\/boards\/(\d+)/);
    return match ? Number(match[1]) : null;
}

function onHashChange() {
    boardId.value = parseRoute();
    acceptInvite();
}

/**
 * #/invite/{token}: join the board and open it. Signing in reloads the page
 * with the hash intact, so a link opened while signed out lands here too.
 */
async function acceptInvite() {
    inviteError.value = '';
    const match = window.location.hash.match(/^#\/invite\/([\w-]+)/);
    if (!match || !me.value) return;

    joining.value = true;
    try {
        const { data } = await api('POST', `/api/invites/${match[1]}`);
        window.location.replace(`#/boards/${data.board_id}`);
    } catch (e) {
        inviteError.value = e.data?.message ?? e.message;
    } finally {
        joining.value = false;
    }
}

async function logout() {
    try {
        await api('POST', '/logout');
    } catch {
        // Usually an expired session (419): signed out already, as far as
        // the server is concerned. The reload below shows that either way.
    }
    // The session (and its CSRF token) is gone, and Echo's socket is
    // authenticated as the old user: a full reload resets both cleanly.
    window.location.hash = '';
    window.location.reload();
}

async function start() {
    startupError.value = '';
    try {
        const { user } = await api('GET', '/api/me');
        me.value = user;
        ready.value = true;
        acceptInvite();
    } catch {
        startupError.value = 'Could not reach the server.';
    }
}

onMounted(() => {
    window.addEventListener('hashchange', onHashChange);
    start();
});

onBeforeUnmount(() => window.removeEventListener('hashchange', onHashChange));
</script>

<template>
    <div class="flex h-full flex-col">
        <header class="flex shrink-0 items-center gap-3 border-b border-slate-200 bg-white px-4 py-2.5 dark:border-slate-800 dark:bg-slate-900">
            <a href="#/" class="flex items-center gap-2 font-semibold tracking-tight">
                <span class="grid size-7 place-items-center rounded-md bg-indigo-600 text-sm text-white">K</span>
                <span>Kanban Live</span>
            </a>
            <div v-if="me" class="ml-auto flex items-center gap-2 text-sm">
                <UserAvatar :user="me" size="sm" />
                <span class="hidden sm:inline">{{ me.name }}</span>
                <button class="rounded-md px-2 py-1 text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-slate-800 dark:hover:text-white" @click="logout">
                    Sign out
                </button>
            </div>
        </header>

        <main class="min-h-0 flex-1">
            <template v-if="ready">
                <JoinScreen v-if="!me" />
                <BoardView v-else-if="boardId" :key="boardId" :board-id="boardId" :me="me" />
                <p v-else-if="joining" class="p-8 text-sm text-slate-500">Joining the board…</p>
                <div v-else-if="inviteError" class="p-8 text-center text-sm text-slate-500" role="alert">
                    {{ inviteError }}
                    <a href="#/" class="ml-1 text-indigo-600 hover:underline">Back to boards</a>
                </div>
                <BoardList v-else />
            </template>
            <div v-else-if="startupError" class="p-8 text-center text-sm text-slate-500" role="alert">
                {{ startupError }}
                <button type="button" class="ml-1 text-indigo-600 hover:underline" @click="start">Try again</button>
            </div>
        </main>

        <ToastStack />
    </div>
</template>
