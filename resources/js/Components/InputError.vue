<script setup>
defineProps({
    message: {
        type: String,
    },
});
</script>

<template>
    <div v-show="message">
        <p role="alert" class="flex items-center gap-1.5 text-sm text-[#9a3324]">
            {{ message }}
        </p>
    </div>
</template>
