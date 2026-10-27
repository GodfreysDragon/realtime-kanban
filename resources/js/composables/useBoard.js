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

    function upsertCard(card) {
        const existing = cards[card.id];
        if (existing && card.version < existing.version) return;
        cards[card.id] = { ...existing, ...card };
    }

    function removeColumn(id) {
        delete columns[id];
        for (const card of Object.values(cards)) {
            if (card.column_id === id) delete cards[card.id];
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
            replace(columns, data.columns);
            replace(cards, data.cards);
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
        .listen('.card.created', buffered(({ card }) => upsertCard(card)))
        .listen('.card.updated', buffered(({ card }) => upsertCard(card)))
        .listen('.card.moved', buffered(({ card }) => upsertCard(card)))
        .listen('.card.deleted', buffered(({ id }) => delete cards[id]))
        .listen('.column.created', buffered(({ column }) => (columns[column.id] = column)))
        .listen('.column.updated', buffered(({ column }) => (columns[column.id] = column)))
        .listen('.column.deleted', buffered(({ id }) => removeColumn(id)))
        .listen(
            '.column.rebalanced',
            buffered(({ positions }) => {
                for (const [id, position] of Object.entries(positions)) {
                    if (cards[id]) cards[id].position = position;
                }
            }),
        )
        .listen('.column.moved', buffered(({ column }) => (columns[column.id] = column)))
        .listen(
            '.columns.rebalanced',
            buffered(({ positions }) => {
                for (const [id, position] of Object.entries(positions)) {
                    if (columns[id]) columns[id].position = position;
                }
            }),
        )
        .listen('.board.renamed', buffered(({ name }) => board.value && (board.value.name = name)))
        .listen('.activity.recorded', buffered(({ activity }) => pushActivity(activity)))
        // Not buffered: losing access matters even mid-resync. On a public
        // board you can still see it, you just stop being a member.
        .listen('.member.removed', ({ user_id, seq }) => {
            noteSeq(seq);
            if (user_id !== me.id) return;
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
            const { data } = await api('POST', `/api/columns/${columnId}/cards`, { title });
            upsertCard(data);
            return data;
        }, 'Could not add the card.');
    }

    /** Throws ApiError (status 409) on a version conflict, for the editor to resolve. */
    async function updateCard(cardId, changes, version) {
        const { data } = await api('PUT', `/api/cards/${cardId}`, { ...changes, version });
        upsertCard(data);
        return data;
    }

    function moveCard(cardId, columnId, afterId) {
        const card = cards[cardId];
        const siblings = (cardsByColumn.value[columnId] ?? []).filter((c) => c.id !== cardId);
        const index = afterId == null ? 0 : siblings.findIndex((c) => c.id === afterId) + 1;

        const unchanged = card.column_id === columnId && positionIsBetween(card, siblings, index);
        if (unchanged) return;

        card.column_id = columnId;
        card.position = positionBetween(siblings[index - 1]?.position, siblings[index]?.position);

        sendMove(
            `card:${cardId}`,
            () => api('PATCH', `/api/cards/${cardId}/move`, { column_id: columnId, after_id: afterId ?? null }),
            upsertCard,
            'Could not move the card.',
        );
    }

    /**
     * Send a move, or if one for the same item is still in flight, send this
     * one when it returns. Only the newest waiting move is kept: each was
     * computed from the board as it looked after the moves before it. A
     * response is applied only if no newer move is waiting to replace it.
     */
    function sendMove(key, send, apply, failureMessage) {
        const queue = (moveQueues[key] ??= { busy: false, next: null });
        if (queue.busy) {
            queue.next = { send, apply, failureMessage };
            return;
        }

        queue.busy = true;
        guarded(async () => {
            const { data } = await send();
            if (!queue.next) apply(data);
        }, failureMessage).finally(() => {
            queue.busy = false;
            const next = queue.next;
            queue.next = null;
            if (next) sendMove(key, next.send, next.apply, next.failureMessage);
        });
    }

    function positionIsBetween(card, siblings, index) {
        const lower = siblings[index - 1]?.position ?? -Infinity;
        const upper = siblings[index]?.position ?? Infinity;
        return card.position > lower && card.position < upper;
    }

    function deleteCard(cardId) {
        delete cards[cardId];
        return guarded(() => api('DELETE', `/api/cards/${cardId}`), 'Could not delete the card.');
    }

    function addColumn(name) {
        return guarded(async () => {
            const { data } = await api('POST', `/api/boards/${boardId}/columns`, { name });
            columns[data.id] = data;
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

        const lower = others[index - 1]?.position ?? -Infinity;
        const upper = others[index]?.position ?? Infinity;
        if (column.position > lower && column.position < upper) return;

        column.position = positionBetween(others[index - 1]?.position, others[index]?.position);

        sendMove(
            `column:${columnId}`,
            () => api('PATCH', `/api/columns/${columnId}/move`, { after_id: afterId ?? null }),
            (column) => (columns[columnId] = column),
            'Could not move the column.',
        );
    }

    function deleteColumn(columnId) {
        removeColumn(columnId);
        return guarded(() => api('DELETE', `/api/columns/${columnId}`), 'Could not delete the column.');
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
