<script setup>
const model = defineModel({
    type: String,
    required: true,
});
</script>

<template>
    <textarea
        v-model="model"
        class="w-full rounded-control border-divider bg-white text-[15px] text-ink shadow-sm transition duration-150 ease-in-out focus:border-accent focus:ring-accent"
    ></textarea>
</template>
