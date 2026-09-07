<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusTag from '@/Components/StatusTag.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    stats: {
        type: Object,
        required: true,
    },
    recentRequests: {
        type: Array,
        required: true,
    },
});

const STATUS_LABELS = {
    draft: 'Draft',
    awaiting_client: 'Awaiting client',
    completed: 'Completed',
    expired: 'Expired',
    archived: 'Archived',
};

function initials(name) {
    return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <PageHeader title="Dashboard">
            Here's what's happening with your document requests.
            <template #actions>
                <Link :href="route('clients.create')" class="inline-flex min-h-[44px] items-center gap-2 rounded-control border border-divider bg-white px-4 text-sm font-semibold text-ink hover:bg-steel-100">
                    Add client
                </Link>
                <Link :href="route('document-requests.create')" class="inline-flex min-h-[44px] items-center gap-2 rounded-control bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-600">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                    New request
                </Link>
            </template>
        </PageHeader>

        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="blueprint flex flex-col gap-1 rounded-card border border-divider bg-white p-4">
                <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
                <span class="text-[11px] uppercase tracking-wide text-steel-600">Clients</span>
                <span class="font-heading text-3xl leading-none text-ink">{{ stats.clients }}</span>
            </div>
            <div class="blueprint flex flex-col gap-1 rounded-card border border-divider bg-white p-4">
                <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
                <span class="text-[11px] uppercase tracking-wide text-steel-600">Active requests</span>
                <span class="font-heading text-3xl leading-none text-ink">{{ stats.activeRequests }}</span>
            </div>
            <div class="blueprint flex flex-col gap-1 rounded-card border border-divider bg-white p-4">
                <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
                <span class="text-[11px] uppercase tracking-wide text-steel-600">Pending documents</span>
                <span class="font-heading text-3xl leading-none text-[#8a5a12]">{{ stats.pendingDocuments }}</span>
            </div>
            <div class="blueprint flex flex-col gap-1 rounded-card border border-divider bg-white p-4">
                <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
                <span class="text-[11px] uppercase tracking-wide text-steel-600">Completed</span>
                <span class="font-heading text-3xl leading-none text-ink">{{ stats.completed }}</span>
            </div>
        </div>

        <section class="overflow-hidden rounded-card border border-divider bg-white">
            <div class="flex items-center justify-between border-b border-divider px-4 py-3.5">
                <h2 class="font-heading text-lg text-ink">Recent requests</h2>
                <Link :href="route('document-requests.index')" class="text-sm text-accent-700 hover:text-accent-900">View all</Link>
            </div>
            <p v-if="recentRequests.length === 0" class="px-4 py-8 text-center text-sm text-steel-600">
                No document requests yet.
            </p>
            <Link
                v-for="r in recentRequests"
                :key="r.id"
                :href="route('document-requests.show', r.id)"
                class="flex items-center gap-3 border-b border-divider px-4 py-3.5 last:border-b-0 hover:bg-steel-100/50"
            >
                <span class="grid h-[34px] w-[34px] flex-none place-items-center rounded-control bg-steel-100 font-heading text-sm text-steel-800">
                    {{ initials(r.client_name) }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-ink">{{ r.client_name }}</span>
                    <span class="block text-xs text-steel-600">{{ r.items_received }} of {{ r.items_total }} documents in{{ r.due_at ? ` · Due ${r.due_at}` : '' }}</span>
                </span>
                <StatusTag :label="STATUS_LABELS[r.display_status]" />
            </Link>
        </section>
    </AuthenticatedLayout>
</template>
