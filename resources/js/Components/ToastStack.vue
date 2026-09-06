<script setup>
import { usePage } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import Toast from '@/Components/Toast.vue';

const page = usePage();
const toasts = reactive([]);
let nextId = 0;
const timers = {};

function push(message, variant) {
    const id = nextId++;
    toasts.push({ id, message, variant });
    timers[id] = setTimeout(() => dismiss(id), 4200);
}

function dismiss(id) {
    clearTimeout(timers[id]);
    delete timers[id];
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

watch(
    () => page.props.flash.success,
    (message) => {
        if (message) push(message, 'success');
    }
);

watch(
    () => page.props.flash.error,
    (message) => {
        if (message) push(message, 'error');
    }
);
</script>

<template>
    <div class="fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6">
        <div v-for="toast in toasts" :key="toast.id" class="w-full max-w-sm">
            <Toast :message="toast.message" :variant="toast.variant" @dismiss="dismiss(toast.id)" />
        </div>
    </div>
</template>
