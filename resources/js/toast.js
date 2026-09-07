import { reactive } from 'vue';

export const toasts = reactive([]);

let nextId = 0;
const timers = {};

export function pushToast(message, variant = 'success') {
    const id = nextId++;
    toasts.push({ id, message, variant });
    timers[id] = setTimeout(() => dismissToast(id), 4200);
}

export function dismissToast(id) {
    clearTimeout(timers[id]);
    delete timers[id];
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) toasts.splice(index, 1);
}
