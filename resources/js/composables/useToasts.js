import { reactive } from 'vue';

const toasts = reactive([]);
const timers = new Map(); // id => { handle, remaining, startedAt }
let nextId = 1;

// Errors stay longer: they usually ask the reader to do something.
const DURATION = { info: 5000, error: 8000 };

export function useToasts() {
    function push(message, tone = 'info', timeout = DURATION[tone] ?? DURATION.info) {
        const id = nextId++;
        toasts.push({ id, message, tone });
        timers.set(id, { handle: null, remaining: timeout, startedAt: 0 });
        release(id);
    }

    function dismiss(id) {
        clearTimeout(timers.get(id)?.handle);
        timers.delete(id);
        const index = toasts.findIndex((toast) => toast.id === id);
        if (index !== -1) toasts.splice(index, 1);
    }

    /** Pause a toast's countdown while it is hovered or focused. */
    function hold(id) {
        const timer = timers.get(id);
        if (!timer?.handle) return;
        clearTimeout(timer.handle);
        timer.handle = null;
        timer.remaining -= Date.now() - timer.startedAt;
    }

    function release(id) {
        const timer = timers.get(id);
        if (!timer || timer.handle) return;
        timer.startedAt = Date.now();
        timer.handle = setTimeout(() => dismiss(id), Math.max(timer.remaining, 1500));
    }

    return { toasts, push, dismiss, hold, release };
}
