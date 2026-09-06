<script setup>
defineProps({
    type: {
        type: String,
        default: 'submit',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-control border border-transparent bg-accent px-4 py-2 font-heading text-[15px] font-semibold text-white shadow-sm transition duration-150 ease-in-out hover:bg-accent-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 active:bg-accent-700 disabled:cursor-not-allowed disabled:opacity-45"
    >
        <slot />
    </button>
</template>
