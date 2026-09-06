<script setup>
defineProps({
    message: {
        type: String,
        required: true,
    },
    variant: {
        type: String,
        default: 'success',
        validator: (v) => ['success', 'error'].includes(v),
    },
});

defineEmits(['dismiss']);
</script>

<template>
    <div
        role="status"
        aria-live="polite"
        class="flex items-center gap-3 rounded-card border px-4 py-3 shadow-lg"
        :class="variant === 'error'
            ? 'border-[#edc9c2] bg-[#fbecea] text-[#7d2a1d]'
            : 'border-[#b9d3c3] bg-[#e7f0ea] text-[#1c4a34]'"
    >
        <span class="min-w-0 flex-1 text-sm leading-snug">{{ message }}</span>
        <button
            type="button"
            aria-label="Dismiss notification"
            class="grid h-7 w-7 flex-none place-items-center rounded text-inherit opacity-60 hover:opacity-100"
            @click="$emit('dismiss')"
        >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
        </button>
    </div>
</template>
