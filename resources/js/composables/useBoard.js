import { computed, reactive, ref } from 'vue';
import { api, onBoardEvents } from '../lib/api';
import { connection, echo } from '../lib/echo';
import { byPosition, positionBetween } from '../lib/positions';
import { useToasts } from './useToasts';

const CURSOR_TTL_MS = 10_000;

// How long a missing sequence number may stay missing before we stop waiting
// for it and re-fetch. Events from concurrent requests can arrive slightly
// out of order, and our own requests report theirs only when they return.
const GAP_GRACE_MS = 3_000;

/**
 * All state for one open board, kept in sync over the board's presence channel.
 *
 * Sync model:
 *  - The REST snapshot (GET /api/boards/{id}) is the source of truth.
 *  - Server events keep it current. Each one carries the full row, so
 *    applying it is idempotent and events can safely be replayed.
 *  - This tab's own changes are applied optimistically and then replaced by
 *    the server's response. The server leaves this tab out of the broadcast
 *    (X-Socket-ID plus toOthers), so nothing is applied twice.
 *  - The snapshot is fetched straight away for a fast first paint, and again
 *    once the channel subscription succeeds: events sent before that moment
 *    never reach us, so only a snapshot taken after it is complete. Events
 *    are buffered while a snapshot loads and replayed after it.
 *  - Pusher-protocol servers don't replay messages missed while offline, but
 *    every reconnect re-subscribes, so the same hook re-fetches the snapshot.
 *  - Every server event carries the board's sequence number (seq). Events
 *    the snapshot already includes are dropped. A number that stays missing
 *    for GAP_GRACE_MS means a message was lost without a disconnect, and the
 *    snapshot is fetched again. Our own requests report the numbers of the
 *    events they sent to everyone else (X-Board-Events), so those don't look
 *    like gaps.
 *  - Ephemeral state (cursors, who is editing or dragging which card) never
 *    touches the server. It travels as client "whispers" between browsers.
 *    Reverb stamps each whisper with the sender's authenticated user id, and
 *    that id, never one in the payload, says whose cursor or badge it is.
 */
export function useBoard(boardId, me) {
    const { push: toast } = useToasts();
    const channelName = `board.${boardId}`;

    const board = ref(null);
    const columns = reactive({});
    const cards = reactive({});
    const activities = ref([]);
    const members = ref([]);
    const cursors = reactive({});
    const editing = reactive({});
    const dragging = reactive({});
    const status = ref(connection.state);
    const loading = ref(true);
    const error = ref(null);
    const channelError = ref(null);
    const removed = ref(false);
    // Set while we leave the board ourselves, so our own member.removed
    // isn't reported as being removed by someone else.
    let leavingOnPurpose = false;

    let pending = [];
    let snapshotSeq = 0; // events up to here are already in the snapshot
    let contiguousSeq = 0; // every number up to here has been accounted for
    const aheadSeqs = new Set(); // numbers seen beyond a gap
    let gapTimer = null;
    let loadSeq = 0;
    let disposed = false;
    // Moves of one card or column ("card:12") are sent one at a time. Two
    // requests in flight together can be processed in either order, leaving
    // the server on the older move while this tab shows the newer one.
    const moveQueues = {};
    // Where this tab has put an item whose move the server hasn't answered
    // yet ("card:12" => { column_id, position }). A snapshot can be served
    // from before the move was saved; it mustn't put the item back.
    const pendingMoves = {};
    let myEditingCardId = null;

    const sortedColumns = computed(() => Object.values(columns).sort(byPosition));

    const cardsByColumn = computed(() => {
        const grouped = {};
        for (const column of Object.values(columns)) grouped[column.id] = [];
        for (const card of Object.values(cards)) grouped[card.column_id]?.push(card);
        for (const list of Object.values(grouped)) list.sort(byPosition);
        return grouped;
    });

    const membersById = computed(() => Object.fromEntries(members.value.map((member) => [member.id, member])));

    // --- applying server state -------------------------------------------

    // The newest sequence number applied to each card and column ("card:12").
    // Events from concurrent requests can arrive in either order, and a move
    // doesn't bump a card's version, so this is what stops a late, older event
    // (or a slow HTTP response) from undoing a newer change.
    let appliedSeqs = {};
    // Cards and columns deleted here or by someone else. Ids are never reused,
    // so anything that arrives about one of them later is stale.
    const deleted = new Set();

    /** Should data about `key` stamped with `seq` be applied? Records it if so. */
    function isNewest(key, seq) {
        if (deleted.has(key)) return false;
        if (seq == null) return true; // unsequenced (a 409's current card): the version check decides
        if (seq <= Math.max(appliedSeqs[key] ?? 0, snapshotSeq)) return false;
        appliedSeqs[key] = seq;
        return true;
    }

    function upsertCard(card, seq) {
        if (!isNewest(`card:${card.id}`, seq)) return;
        const existing = cards[card.id];
        if (existing && card.version < existing.version) return;
        cards[card.id] = { ...existing, ...card };
    }

    function putColumn(column, seq) {
        if (isNewest(`column:${column.id}`, seq)) columns[column.id] = column;
    }

    function removeCard(id) {
        deleted.add(`card:${id}`);
        delete cards[id];
    }

    /** @returns {string[]} the keys marked deleted, to undo if the delete fails */
    function removeColumn(id) {
        const keys = [`column:${id}`];
        deleted.add(keys[0]);
        delete columns[id];
        for (const card of Object.values(cards)) {
            if (card.column_id !== id) continue;
            removeCard(card.id);
            keys.push(`card:${card.id}`);
        }
        return keys;
    }

    /** Send a delete; if it fails, the rows weren't deleted after all. */
    async function sendDelete(keys, send) {
        try {
            await send();
        } catch (e) {
            for (const key of keys) deleted.delete(key);
            throw e;
        }
    }

    function pushActivity(activity) {
        if (activities.value.some((existing) => existing.id === activity.id)) return;
        activities.value = [activity, ...activities.value].slice(0, 50);
    }

    /** Fetch the snapshot. If loads overlap, only the latest one is applied. */
    async function load() {
        const seq = ++loadSeq;
        loading.value = true;
        pending = [];

        try {
            const { data } = await api('GET', `/api/boards/${boardId}`);
            if (seq !== loadSeq) return;
            snapshotTaken(data.seq);
            board.value = { id: data.id, name: data.name, public: data.public, role: data.role, inviteToken: data.invite_token ?? null };
            applySnapshot(data);
            activities.value = data.activities;
            error.value = null;
        } catch (e) {
            if (seq !== loadSeq) return;
            error.value =
                {
                    403: "You're not a member of this board. Ask someone on it for an invite link.",
                    404: 'This board does not exist.',
                }[e.status] ?? e.message;
        } finally {
            if (seq === loadSeq) {
                loading.value = false;
                const replay = pending;
                pending = [];
                replay.forEach((apply) => apply());
            }
        }
    }

    // --- sequence numbers --------------------------------------------------

    function noteSeq(seq) {
        if (seq == null || seq <= contiguousSeq) return;
        aheadSeqs.add(seq);
        advanceSeq();
    }

    function advanceSeq() {
        const before = contiguousSeq;
        while (aheadSeqs.has(contiguousSeq + 1)) aheadSeqs.delete(++contiguousSeq);

        // Restart the clock whenever the gap moves, so each gap gets the full grace period.
        if (aheadSeqs.size === 0 || contiguousSeq !== before) {
            clearTimeout(gapTimer);
            gapTimer = null;
        }
        if (aheadSeqs.size > 0 && !gapTimer) gapTimer = setTimeout(onGap, GAP_GRACE_MS);
    }

    function onGap() {
        gapTimer = null;
        if (aheadSeqs.size > 0) load();
    }

    function snapshotTaken(seq) {
        snapshotSeq = seq;
        if (seq > contiguousSeq) contiguousSeq = seq;
        for (const ahead of aheadSeqs) if (ahead <= contiguousSeq) aheadSeqs.delete(ahead);
        advanceSeq();
    }

    const stopEventHeader = onBoardEvents((events) => {
        for (const [eventBoardId, seq] of events) if (eventBoardId === boardId) noteSeq(seq);
    });

    /**
     * Replace the board with a snapshot, except where this tab already knows
     * better. A snapshot can be served from before a change this tab made or
     * received: a row with a newer applied seq (say, our own move's response)
     * is kept, an item whose move is still in flight stays where we put it,
     * and a deleted item stays deleted.
     */
    function applySnapshot(data) {
        const newer = {};
        for (const [key, seq] of Object.entries(appliedSeqs)) {
            if (seq <= data.seq) continue;
            const [kind, id] = key.split(':');
            const row = kind === 'card' ? cards[id] : columns[id];
            if (row) newer[key] = { ...row };
        }

        replace(columns, data.columns.filter((row) => !deleted.has(`column:${row.id}`)));
        replace(cards, data.cards.filter((row) => !deleted.has(`card:${row.id}`)));

        appliedSeqs = Object.fromEntries(Object.entries(appliedSeqs).filter(([, seq]) => seq > data.seq));
        for (const [key, row] of Object.entries(newer)) (key.startsWith('card:') ? cards : columns)[row.id] = row;
        for (const [key, placement] of Object.entries(pendingMoves)) {
            const [kind, id] = key.split(':');
            const item = kind === 'card' ? cards[id] : columns[id];
            if (item) Object.assign(item, placement);
        }
    }

    function replace(target, rows) {
        for (const key of Object.keys(target)) delete target[key];
        for (const row of rows) target[row.id] = row;
    }

    /**
     * Wrap a server-event handler: deferred while a snapshot loads, dropped
     * if the snapshot it lands on already includes it.
     */
    function buffered(handler) {
        const apply = (payload) => {
            noteSeq(payload.seq);
            if (payload.seq <= snapshotSeq) return;
            handler(payload);
        };
        return (payload) => (loading.value ? pending.push(() => apply(payload)) : apply(payload));
    }

    // --- channel ---------------------------------------------------------

    const channel = echo
        .join(channelName)
        // Fires on the first subscription and again after every reconnect.
        .here((users) => {
            channelError.value = null;
            members.value = users;
            forgetSignalsFromAbsentMembers(users);
            load();
        })
        .joining((user) => {
            members.value = [...members.value.filter((m) => m.id !== user.id), user];
            // Newcomers can't know what we're doing until we tell them.
            if (myEditingCardId) whisper('editing', { cardId: myEditingCardId });
        })
        .leaving((user) => {
            members.value = members.value.filter((m) => m.id !== user.id);
            delete cursors[user.id];
            delete editing[user.id];
            delete dragging[user.id];
        })
        .error((e) => {
            // Refused after we were already in: the server closed our socket
            // on removal, possibly before the member.removed message arrived.
            if (e?.status === 403 && board.value) removed.value = true;
            else channelError.value = e?.status === 403 ? 'You are not allowed to join this board.' : 'Could not join the live channel.';
        })
        .listen('.card.created', buffered(({ card, seq }) => upsertCard(card, seq)))
        .listen('.card.updated', buffered(({ card, seq }) => upsertCard(card, seq)))
        .listen('.card.moved', buffered(({ card, seq }) => upsertCard(card, seq)))
        .listen('.card.deleted', buffered(({ id }) => removeCard(id)))
        .listen('.column.created', buffered(({ column, seq }) => putColumn(column, seq)))
        .listen('.column.updated', buffered(({ column, seq }) => putColumn(column, seq)))
        .listen('.column.deleted', buffered(({ id }) => removeColumn(id)))
        .listen(
            '.column.rebalanced',
            buffered(({ positions, seq }) => {
                for (const [id, position] of Object.entries(positions)) {
                    if (cards[id] && isNewest(`card:${id}`, seq)) cards[id].position = position;
                }
            }),
        )
        .listen('.column.moved', buffered(({ column, seq }) => putColumn(column, seq)))
        .listen(
            '.columns.rebalanced',
            buffered(({ positions, seq }) => {
                for (const [id, position] of Object.entries(positions)) {
                    if (columns[id] && isNewest(`column:${id}`, seq)) columns[id].position = position;
                }
            }),
        )
        .listen('.board.renamed', buffered(({ name }) => board.value && (board.value.name = name)))
        .listen('.activity.recorded', buffered(({ activity }) => pushActivity(activity)))
        // Not buffered: losing access matters even mid-resync. On a public
        // board you can still see it, you just stop being a member.
        .listen('.member.removed', ({ user_id, seq }) => {
            noteSeq(seq);
            if (user_id !== me.id || leavingOnPurpose) return;
            if (board.value?.public) Object.assign(board.value, { role: null, inviteToken: null });
            else removed.value = true;
        })
        .listenForWhisper('cursor', fromSender((userId, { x, y }) => {
            if (x == null) delete cursors[userId];
            else cursors[userId] = { x, y, at: Date.now() };
        }))
        .listenForWhisper('editing', fromSender((userId, { cardId }) => setOrClear(editing, userId, cardId)))
        .listenForWhisper('dragging', fromSender((userId, { cardId }) => setOrClear(dragging, userId, cardId)));

    /**
     * pusher-js hands whisper listeners the server's metadata as a second
     * argument. Its user_id comes from the sender's channel authorisation, so
     * a client can't put words in someone else's mouth. Without it (a server
     * that doesn't stamp whispers) the whisper is dropped.
     */
    function fromSender(handler) {
        return (data, metadata) => {
            if (metadata?.user_id == null) return;
            handler(String(metadata.user_id), data ?? {});
        };
    }

    /** People who left while we were offline never sent us "leaving"; drop their cursors and badges. */
    function forgetSignalsFromAbsentMembers(users) {
        const present = new Set(users.map((user) => String(user.id)));
        for (const map of [cursors, editing, dragging]) {
            for (const userId of Object.keys(map)) {
                if (!present.has(userId)) delete map[userId];
            }
        }
    }

    function setOrClear(map, userId, value) {
        if (value == null) delete map[userId];
        else map[userId] = value;
    }

    function whisper(event, payload) {
        // Whispers need a live subscription. The card dialog clears its
        // "editing" flag on unmount, which can run after dispose() has left.
        if (disposed || !channel.subscription?.subscribed) return;
        channel.whisper(event, payload);
    }

    let everConnected = connection.state === 'connected';
    function onStateChange({ current }) {
        status.value = current;
        if (current !== 'connected') return;
        // The snapshot itself is re-fetched by .here() once re-subscribed.
        if (everConnected) toast('Reconnected. Re-syncing the board.');
        everConnected = true;
    }
    connection.bind('state_change', onStateChange);

    const staleCursorTimer = setInterval(() => {
        const cutoff = Date.now() - CURSOR_TTL_MS;
        for (const [id, cursor] of Object.entries(cursors)) {
            if (cursor.at < cutoff) delete cursors[id];
        }
    }, 2000);

    function dispose() {
        disposed = true;
        stopEventHeader();
        clearTimeout(gapTimer);
        clearInterval(staleCursorTimer);
        connection.unbind('state_change', onStateChange);
        echo.leave(channelName);
    }

    // --- actions -----------------------------------------------------------

    async function guarded(action, failureMessage) {
        try {
            return await action();
        } catch (e) {
            toast(e.data?.message ?? failureMessage, 'error');
            await load();
        }
    }

    function createCard(columnId, title) {
        return guarded(async () => {
            const { data, seq } = await api('POST', `/api/columns/${columnId}/cards`, { title });
            upsertCard(data, seq);
            return data;
        }, 'Could not add the card.');
    }

    /** Throws ApiError (status 409) on a version conflict, for the editor to resolve. */
    async function updateCard(cardId, changes, version) {
        const { data, seq } = await api('PUT', `/api/cards/${cardId}`, { ...changes, version });
        upsertCard(data, seq);
        return data;
    }

    function moveCard(cardId, columnId, afterId) {
        const card = cards[cardId];
        if (!card || !columns[columnId]) return; // deleted while it was being dragged
        const siblings = (cardsByColumn.value[columnId] ?? []).filter((c) => c.id !== cardId);
        const index = afterId == null ? 0 : siblings.findIndex((c) => c.id === afterId) + 1;

        const unchanged = card.column_id === columnId && positionIsBetween(card, siblings, index);
        if (unchanged) return;

        card.column_id = columnId;
        card.position = positionBetween(siblings[index - 1]?.position, siblings[index]?.position);
        pendingMoves[`card:${cardId}`] = { column_id: columnId, position: card.position };

        sendMove(
            `card:${cardId}`,
            () => api('PATCH', `/api/cards/${cardId}/move`, { column_id: columnId, after_id: afterId ?? null }),
            ({ data, seq }) => upsertCard(data, seq),
            'Could not move the card.',
        );
    }

    /**
     * Send a move, or if one for the same item is still in flight, send this
     * one when it returns. Only the newest waiting move is kept: each was
     * computed from the board as it looked after the moves before it. A
     * response is applied only if no newer move is waiting to replace it.
     * Moves of an item deleted meanwhile are dropped.
     */
    function sendMove(key, send, apply, failureMessage) {
        if (deleted.has(key)) {
            delete pendingMoves[key];
            return;
        }
        const queue = (moveQueues[key] ??= { busy: false, next: null });
        if (queue.busy) {
            queue.next = { send, apply, failureMessage };
            return;
        }

        queue.busy = true;
        guarded(async () => {
            try {
                const response = await send();
                if (!queue.next) apply(response);
            } catch (e) {
                // The move failed: the snapshot guarded() fetches next is right.
                delete pendingMoves[key];
                throw e;
            }
        }, failureMessage).finally(() => {
            queue.busy = false;
            const next = queue.next;
            queue.next = null;
            if (next) sendMove(key, next.send, next.apply, next.failureMessage);
            else delete pendingMoves[key];
        });
    }

    function positionIsBetween(card, siblings, index) {
        const lower = siblings[index - 1]?.position ?? -Infinity;
        const upper = siblings[index]?.position ?? Infinity;
        return card.position > lower && card.position < upper;
    }

    function deleteCard(cardId) {
        removeCard(cardId);
        return guarded(() => sendDelete([`card:${cardId}`], () => api('DELETE', `/api/cards/${cardId}`)), 'Could not delete the card.');
    }

    function addColumn(name) {
        return guarded(async () => {
            const { data, seq } = await api('POST', `/api/boards/${boardId}/columns`, { name });
            putColumn(data, seq);
        }, 'Could not add the column.');
    }

    function renameColumn(columnId, name) {
        columns[columnId].name = name;
        return guarded(() => api('PUT', `/api/columns/${columnId}`, { name }), 'Could not rename the column.');
    }

    function moveColumn(columnId, afterId) {
        const others = sortedColumns.value.filter((column) => column.id !== columnId);
        const index = afterId == null ? 0 : others.findIndex((column) => column.id === afterId) + 1;
        const column = columns[columnId];
        if (!column) return; // deleted while it was being dragged

        const lower = others[index - 1]?.position ?? -Infinity;
        const upper = others[index]?.position ?? Infinity;
        if (column.position > lower && column.position < upper) return;

        column.position = positionBetween(others[index - 1]?.position, others[index]?.position);
        pendingMoves[`column:${columnId}`] = { position: column.position };

        sendMove(
            `column:${columnId}`,
            () => api('PATCH', `/api/columns/${columnId}/move`, { after_id: afterId ?? null }),
            ({ data, seq }) => putColumn(data, seq),
            'Could not move the column.',
        );
    }

    function deleteColumn(columnId) {
        const keys = removeColumn(columnId);
        return guarded(() => sendDelete(keys, () => api('DELETE', `/api/columns/${columnId}`)), 'Could not delete the column.');
    }

    /** Leave the board; the caller navigates away once it's done. */
    async function leave() {
        leavingOnPurpose = true;
        try {
            await api('DELETE', `/api/boards/${boardId}/members/${me.id}`);
        } catch (e) {
            leavingOnPurpose = false;
            throw e;
        }
    }

    function renameBoard(name) {
        board.value.name = name;
        return guarded(() => api('PUT', `/api/boards/${boardId}`, { name }), 'Could not rename the board.');
    }

    // --- presence signals ----------------------------------------------------

    function sendCursor(x, y) {
        whisper('cursor', { x, y });
    }

    function setEditing(cardId) {
        myEditingCardId = cardId;
        whisper('editing', { cardId });
    }

    function setDragging(cardId) {
        whisper('dragging', { cardId });
    }

    load();

    return {
        board,
        columns: sortedColumns,
        cards,
        cardsByColumn,
        activities,
        members,
        membersById,
        cursors,
        editing,
        dragging,
        status,
        loading,
        error,
        channelError,
        removed,
        leave,
        load,
        applyCard: upsertCard,
        dispose,
        createCard,
        updateCard,
        moveCard,
        deleteCard,
        addColumn,
        renameColumn,
        moveColumn,
        deleteColumn,
        renameBoard,
        sendCursor,
        setEditing,
        setDragging,
    };
}
