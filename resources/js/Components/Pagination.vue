<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    links: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <div v-if="links.length > 3" class="mt-4 flex justify-center gap-1 px-6 pb-4">
        <template v-for="(link, index) in links" :key="index">
            <Link
                v-if="link.url"
                :href="link.url"
                v-html="link.label"
                class="rounded px-3 py-1 text-sm"
                :class="link.active ? 'bg-accent text-white' : 'text-steel-700 hover:bg-steel-100'"
            />
            <span
                v-else
                v-html="link.label"
                class="rounded px-3 py-1 text-sm text-steel-400"
            />
        </template>
    </div>
</template>
