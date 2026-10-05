import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/**
 * Reverb speaks the Pusher protocol, so Echo drives it through pusher-js.
 * Private/presence subscriptions are authorised by POST /broadcasting/auth,
 * which Echo sends with the session cookie and the CSRF token from <meta>.
 */
export const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

/** The underlying pusher-js connection (state: connecting, connected, unavailable, ...). */
export const connection = echo.connector.pusher.connection;
