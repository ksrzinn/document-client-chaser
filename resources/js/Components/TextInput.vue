<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="min-h-[44px] w-full rounded-control border-divider bg-white text-[15px] text-ink shadow-sm transition duration-150 ease-in-out focus:border-accent focus:ring-accent"
        v-model="model"
        ref="input"
    />
</template>
