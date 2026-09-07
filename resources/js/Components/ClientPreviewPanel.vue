<script setup>
import Card from '@/Components/Card.vue';

defineProps({
    clientName: {
        type: String,
        default: null,
    },
    clientEmail: {
        type: String,
        default: null,
    },
    message: {
        type: String,
        default: '',
    },
    items: {
        type: Array,
        default: () => [],
    },
    dueAt: {
        type: String,
        default: null,
    },
    expiresAt: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <aside class="rounded-card border border-dashed border-accent-400 bg-accent-100 p-5">
        <span class="text-[11px] uppercase tracking-wide text-accent-800">What your client receives</span>
        <Card class="mt-3.5 p-[18px]">
            <div class="flex items-center gap-2 border-b border-divider pb-3.5">
                <span class="grid h-[22px] w-[22px] place-items-center rounded border border-accent text-accent">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /></svg>
                </span>
                <span class="font-heading text-xs uppercase tracking-wide text-steel-700">Secure upload portal</span>
            </div>
            <p class="mb-0.5 mt-3.5 text-[11px] uppercase tracking-wide text-steel-600">Request for</p>
            <p class="font-heading text-lg text-ink">{{ clientName || 'Select a client' }}</p>
            <p v-if="clientEmail" class="mt-0.5 break-all text-xs text-steel-600">{{ clientEmail }}</p>
            <p v-if="message" class="mt-3.5 rounded-r border-l-2 border-accent-400 bg-canvas p-2.5 text-sm text-steel-800">{{ message }}</p>
            <p class="mb-2 mt-3.5 text-[11px] uppercase tracking-wide text-steel-600">Requested documents</p>
            <div class="flex flex-col gap-1.5">
                <span v-for="(item, index) in items" :key="index" class="flex items-center gap-2 text-sm text-steel-800">
                    <span class="h-3.5 w-3.5 flex-none rounded-full border-[1.5px] border-steel-400"></span>
                    {{ item.name || `Document ${index + 1}` }}
                </span>
            </div>
            <div class="mt-4 flex gap-5 border-t border-divider pt-3.5">
                <span><span class="block text-[11px] uppercase tracking-wide text-steel-600">Due</span><span class="text-sm">{{ dueAt || '—' }}</span></span>
                <span><span class="block text-[11px] uppercase tracking-wide text-steel-600">Expires</span><span class="text-sm">{{ expiresAt || '—' }}</span></span>
            </div>
        </Card>
        <p class="mt-3.5 text-xs leading-relaxed text-accent-800">Nothing is sent yet. You can review and send the request from its detail page.</p>
    </aside>
</template>
