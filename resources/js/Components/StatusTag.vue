<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: {
        type: String,
        required: true,
    },
});

const VARIANTS = {
    Draft: 'bg-steel-100 text-steel-800 border-steel-300',
    'Awaiting client': 'bg-accent-100 text-accent-800 border-accent-300',
    Completed: 'bg-[#e7f0ea] text-[#1c4a34] border-[#b9d3c3]',
    Expired: 'bg-[#f7efe1] text-[#7a5312] border-[#e3cfa8]',
    Archived: 'bg-transparent text-steel-700 border-steel-400',
    Active: 'bg-[#e7f0ea] text-[#1c4a34] border-[#b9d3c3]',
    Received: 'bg-[#e7f0ea] text-[#1c4a34] border-[#b9d3c3]',
    Missing: 'bg-steel-100 text-steel-800 border-steel-300',
};

const classes = computed(() => VARIANTS[props.label] ?? VARIANTS.Draft);
</script>

<template>
    <span
        class="inline-flex items-center whitespace-nowrap rounded border px-2.5 py-0.5 text-xs font-medium"
        :class="classes"
    >
        {{ label }}
    </span>
</template>
