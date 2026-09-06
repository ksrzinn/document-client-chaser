<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-heading text-3xl leading-tight text-ink">{{ title }}</h1>
            <p v-if="$slots.default" class="mt-1 text-sm text-steel-700">
                <slot />
            </p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
