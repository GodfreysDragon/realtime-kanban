import { echo } from './echo';

export class ApiError extends Error {
    constructor(status, data) {
        super(data?.message ?? `Request failed (${status})`);
        this.status = status;
        this.data = data;
    }
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

    const data = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        throw new ApiError(response.status, data);
    }

    return data;
}
