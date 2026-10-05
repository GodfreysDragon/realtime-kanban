import { reactive } from 'vue';

const toasts = reactive([]);
let nextId = 1;

export function useToasts() {
    function push(message, tone = 'info', timeout = 4000) {
        const id = nextId++;
        toasts.push({ id, message, tone });
        setTimeout(() => dismiss(id), timeout);
    }

    function dismiss(id) {
        const index = toasts.findIndex((toast) => toast.id === id);
        if (index !== -1) toasts.splice(index, 1);
    }

    return { toasts, push, dismiss };
}
