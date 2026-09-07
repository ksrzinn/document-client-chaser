<script setup>
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import Toast from '@/Components/Toast.vue';
import { toasts, pushToast, dismissToast } from '@/toast.js';

const page = usePage();

watch(
    () => page.props.flash.success,
    (message) => {
        if (message) pushToast(message, 'success');
    }
);

watch(
    () => page.props.flash.error,
    (message) => {
        if (message) pushToast(message, 'error');
    }
);
</script>

<template>
    <div class="fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6">
        <div v-for="toast in toasts" :key="toast.id" class="w-full max-w-sm">
            <Toast :message="toast.message" :variant="toast.variant" @dismiss="dismissToast(toast.id)" />
        </div>
    </div>
</template>
