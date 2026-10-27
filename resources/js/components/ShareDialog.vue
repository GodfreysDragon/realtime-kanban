<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import { api } from '../lib/api';
import { useDialog } from '../composables/useDialog';
import { useToasts } from '../composables/useToasts';
import UserAvatar from './UserAvatar.vue';

const emit = defineEmits(['close']);

const board = inject('board');
const me = inject('me');
const { push: toast } = useToasts();

const dialog = ref(null);
const linkInput = ref(null);
const members = ref(null);
const busy = ref(false);

const info = computed(() => board.board.value);
const isOwner = computed(() => info.value.role === 'owner');
const inviteUrl = computed(() => info.value.inviteToken && `${window.location.origin}/#/invite/${info.value.inviteToken}`);

useDialog(dialog, () => emit('close'));

async function loadMembers() {
    const { data, invite_token } = await api('GET', `/api/boards/${info.value.id}/members`);
    members.value = data;
    // The link changes when someone is removed; show the one that works now.
    info.value.inviteToken = invite_token;
}

async function copyLink() {
    try {
        await navigator.clipboard.writeText(inviteUrl.value);
        toast('Invite link copied.');
    } catch {
        // No clipboard permission (or an insecure origin): select it instead.
        linkInput.value.select();
    }
}

async function resetLink() {
    if (!window.confirm('Reset the invite link? The current link will stop working.')) return;
    busy.value = true;
    try {
        const { data } = await api('POST', `/api/boards/${info.value.id}/invite`);
        info.value.inviteToken = data.invite_token;
        toast('New invite link created.');
    } catch (e) {
        toast(e.data?.message ?? 'Could not reset the link.', 'error');
    } finally {
        busy.value = false;
    }
}

async function remove(member) {
    const leaving = member.id === me.id;
    if (!window.confirm(leaving ? 'Leave this board?' : `Remove ${member.name} from this board?`)) return;
    busy.value = true;
    try {
        if (leaving) await board.leave();
        else await api('DELETE', `/api/boards/${info.value.id}/members/${member.id}`);
        if (leaving && !info.value.public) {
            window.location.hash = '#/';
            return;
        }
        if (leaving) Object.assign(info.value, { role: null, inviteToken: null });
        await loadMembers();
    } catch (e) {
        toast(e.data?.message ?? 'Could not update the members.', 'error');
    } finally {
        busy.value = false;
    }
}

function canRemove(member) {
    if (member.role === 'owner') return false;
    return isOwner.value || member.id === me.id;
}

onMounted(async () => {
    await loadMembers();
    (linkInput.value ?? dialog.value.querySelector('button'))?.focus();
});
</script>

<template>
    <div class="fixed inset-0 z-40 grid place-items-center bg-slate-950/40 p-4" @mousedown.self="emit('close')">
        <div
            ref="dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="share-title"
            class="max-h-[calc(100dvh-2rem)] w-full max-w-md overflow-y-auto rounded-xl bg-white p-5 shadow-2xl dark:bg-slate-900"
        >
            <div class="flex items-center">
                <h2 id="share-title" class="text-lg font-semibold">Share “{{ info.name }}”</h2>
                <button type="button" class="ml-auto rounded px-1.5 text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white" aria-label="Close" @click="emit('close')">
                    ✕
                </button>
            </div>

            <p v-if="info.public" class="mt-2 rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:bg-sky-500/10 dark:text-sky-200">
                This board is public: everyone who signs in can open and edit it.
            </p>

            <template v-if="inviteUrl">
                <label for="invite-link" class="mt-4 block text-xs font-medium tracking-wide text-slate-500 uppercase dark:text-slate-400">Invite link</label>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Anyone with this link can join the board as a member.</p>
                <div class="mt-2 flex gap-2">
                    <input
                        id="invite-link"
                        ref="linkInput"
                        :value="inviteUrl"
                        readonly
                        class="min-w-0 flex-1 rounded-md border border-slate-300 bg-slate-50 px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-950"
                        @focus="$event.target.select()"
                    />
                    <button type="button" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500" @click="copyLink">Copy</button>
                </div>
                <button v-if="isOwner" type="button" :disabled="busy" class="mt-2 text-sm text-slate-600 underline hover:text-slate-900 disabled:opacity-50 dark:text-slate-400 dark:hover:text-white" @click="resetLink">
                    Reset link
                </button>
            </template>
            <p v-else class="mt-4 text-sm text-slate-600 dark:text-slate-400">Only members can invite people to this board.</p>

            <h3 class="mt-5 text-xs font-medium tracking-wide text-slate-500 uppercase dark:text-slate-400">Members</h3>
            <p v-if="!members" class="mt-2 text-sm text-slate-500">Loading…</p>
            <ul v-else class="mt-2 divide-y divide-slate-100 dark:divide-slate-800" data-testid="members">
                <li v-for="member in members" :key="member.id" class="flex items-center gap-2 py-2 text-sm">
                    <UserAvatar :user="member" size="sm" />
                    <span class="min-w-0 truncate">{{ member.name }}<span v-if="member.id === me.id" class="text-slate-500 dark:text-slate-400"> (you)</span></span>
                    <span v-if="member.role === 'owner'" class="rounded bg-slate-100 px-1.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">Owner</span>
                    <button
                        v-if="canRemove(member)"
                        type="button"
                        :disabled="busy"
                        class="ml-auto rounded px-2 py-0.5 text-red-600 hover:bg-red-50 disabled:opacity-50 dark:hover:bg-red-500/10"
                        @click="remove(member)"
                    >
                        {{ member.id === me.id ? 'Leave' : 'Remove' }}
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
