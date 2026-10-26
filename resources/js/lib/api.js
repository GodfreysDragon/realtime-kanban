import { echo } from './echo';

export class ApiError extends Error {
    constructor(status, data) {
        super(data?.message ?? `Request failed (${status})`);
        this.status = status;
        this.data = data;
    }
}

const boardEventListeners = new Set();

/**
 * Subscribe to the sequence numbers each response reports in X-Board-Events
 * ("board:seq,..."): the events a request broadcast, including the ones
 * sent ->toOthers() that this tab will never receive.
 *
 * @param {(events: Array<[number, number]>) => void} listener
 * @returns {() => void} unsubscribe
 */
export function onBoardEvents(listener) {
    boardEventListeners.add(listener);
    return () => boardEventListeners.delete(listener);
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * JSON fetch wrapper for the session-authenticated API.
 *
 * X-Socket-ID tells Laravel which WebSocket connection made the request, so
 * `broadcast(...)->toOthers()` skips it: this tab already applied the change
 * optimistically and must not apply it twice.
 */
export async function api(method, url, body) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
    };

    const socketId = echo.socketId();
    if (socketId) {
        headers['X-Socket-ID'] = socketId;
    }

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const sent = response.headers.get('X-Board-Events');
    if (sent) {
        const events = sent.split(',').map((pair) => pair.split(':').map(Number));
        boardEventListeners.forEach((listener) => listener(events));
    }

    const data = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        throw new ApiError(response.status, data);
    }

    return data;
}
