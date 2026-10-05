import { computed, reactive, ref } from 'vue';
import { api } from '../lib/api';
import { connection, echo } from '../lib/echo';
import { byPosition, positionBetween } from '../lib/positions';
import { useToasts } from './useToasts';

const CURSOR_TTL_MS = 10_000;

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
 *  - Events are buffered while a snapshot is loading and replayed after it,
 *    so nothing sent between "snapshot taken" and "snapshot applied" is lost.
 *  - After a reconnect the snapshot is fetched again, because Pusher-protocol
 *    servers don't replay messages missed while offline.
 *  - Ephemeral state (cursors, who is editing or dragging which card) never
 *    touches the server. It travels as client "whispers" between browsers.
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

    let pending = [];
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

    async function load() {
        loading.value = true;
        pending = [];

        try {
            const { data } = await api('GET', `/api/boards/${boardId}`);
            board.value = { id: data.id, name: data.name };
            replace(columns, data.columns);
            replace(cards, data.cards);
            activities.value = data.activities;
            error.value = null;
        } catch (e) {
            error.value = e.status === 404 ? 'This board does not exist.' : e.message;
        } finally {
            loading.value = false;
            const replay = pending;
            pending = [];
            replay.forEach((apply) => apply());
        }
    }

    function replace(target, rows) {
        for (const key of Object.keys(target)) delete target[key];
        for (const row of rows) target[row.id] = row;
    }

    /** Wrap a server-event handler so it's deferred while a snapshot loads. */
    function buffered(handler) {
        return (payload) => (loading.value ? pending.push(() => handler(payload)) : handler(payload));
    }

    // --- channel ---------------------------------------------------------

    const channel = echo
        .join(channelName)
        .here((users) => (members.value = users))
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
            error.value = e?.status === 403 ? 'You are not allowed to join this board.' : 'Could not join the live channel.';
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
        .listen('.board.renamed', buffered(({ name }) => board.value && (board.value.name = name)))
        .listen('.activity.recorded', buffered(({ activity }) => pushActivity(activity)))
        .listenForWhisper('cursor', ({ userId, x, y }) => {
            if (x == null) delete cursors[userId];
            else cursors[userId] = { x, y, at: Date.now() };
        })
        .listenForWhisper('editing', ({ userId, cardId }) => setOrClear(editing, userId, cardId))
        .listenForWhisper('dragging', ({ userId, cardId }) => setOrClear(dragging, userId, cardId));

    function setOrClear(map, userId, value) {
        if (value == null) delete map[userId];
        else map[userId] = value;
    }

    function whisper(event, payload) {
        channel.whisper(event, { userId: me.id, ...payload });
    }

    let everConnected = connection.state === 'connected';
    function onStateChange({ current }) {
        status.value = current;
        if (current !== 'connected') return;
        if (everConnected) {
            toast('Reconnected. Re-syncing the board.');
            load();
        }
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

        return guarded(async () => {
            const { data } = await api('PATCH', `/api/cards/${cardId}/move`, { column_id: columnId, after_id: afterId ?? null });
            upsertCard(data);
        }, 'Could not move the card.');
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
        load,
        dispose,
        createCard,
        updateCard,
        moveCard,
        deleteCard,
        addColumn,
        renameColumn,
        deleteColumn,
        renameBoard,
        sendCursor,
        setEditing,
        setDragging,
    };
}
