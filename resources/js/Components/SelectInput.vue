<script setup>
const model = defineModel({
    required: true,
});
</script>

<template>
    <select
        v-model="model"
        class="min-h-[44px] w-full rounded-control border-divider bg-white text-[15px] text-ink shadow-sm transition duration-150 ease-in-out focus:border-accent focus:ring-accent"
    >
        <slot />
    </select>
</template>
