<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-control border border-divider bg-white px-4 py-2 font-heading text-[15px] font-semibold text-ink transition duration-150 ease-in-out hover:bg-steel-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-45"
    >
        <slot />
    </button>
</template>
