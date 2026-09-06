<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <div class="flex flex-col items-center gap-2.5 rounded-card border border-divider bg-white px-6 py-14 text-center">
        <span
            v-if="$slots.icon"
            class="grid h-13 w-13 place-items-center rounded-card border border-accent-300 bg-accent-100 text-accent-700"
        >
            <slot name="icon" />
        </span>
        <h2 class="mt-1.5 font-heading text-xl text-ink">{{ title }}</h2>
        <p class="max-w-sm text-sm leading-relaxed text-steel-700">{{ description }}</p>
        <div v-if="$slots.action" class="mt-2">
            <slot name="action" />
        </div>
    </div>
</template>
