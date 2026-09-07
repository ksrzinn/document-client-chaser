<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusTag from '@/Components/StatusTag.vue';
import Pagination from '@/Components/Pagination.vue';
import Card from '@/Components/Card.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    documentRequests: {
        type: Object,
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
    <Head title="Document Requests" />

    <AuthenticatedLayout>
        <PageHeader title="Document Requests">
            {{ documentRequests.data.length }} request{{ documentRequests.data.length === 1 ? '' : 's' }}
            <template #actions>
                <Link :href="route('document-requests.create')" class="inline-flex min-h-[44px] items-center gap-2 rounded-control bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-600">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                    New request
                </Link>
            </template>
        </PageHeader>

        <EmptyState
            v-if="documentRequests.data.length === 0"
            title="No document requests yet"
            description="Create your first request and send it to a client. They upload, you stop chasing."
        >
            <template #action>
                <Link :href="route('document-requests.create')" class="inline-flex min-h-[44px] items-center rounded-control bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-600">
                    Create request
                </Link>
            </template>
        </EmptyState>

        <Card v-else>
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr class="border-b border-divider text-left text-xs uppercase tracking-wide text-steel-600">
                            <th class="px-4 py-3 font-medium">Client</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Documents</th>
                            <th class="px-4 py-3 font-medium">Due</th>
                            <th class="px-4 py-3 font-medium">Expires</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in documentRequests.data" :key="r.id" class="border-b border-divider last:border-b-0 hover:bg-steel-100/50">
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <span class="grid h-8 w-8 flex-none place-items-center rounded-control bg-steel-100 font-heading text-sm text-steel-800">{{ initials(r.client.name) }}</span>
                                    <Link :href="route('document-requests.show', r.id)" class="font-medium text-ink hover:text-accent-700">{{ r.client.name }}</Link>
                                </div>
                            </td>
                            <td class="px-4 py-3.5"><StatusTag :label="STATUS_LABELS[r.display_status]" /></td>
                            <td class="px-4 py-3.5 text-steel-700">{{ r.items_count }} item{{ r.items_count === 1 ? '' : 's' }}</td>
                            <td class="px-4 py-3.5 text-steel-700">{{ r.due_at ?? '—' }}</td>
                            <td class="px-4 py-3.5 text-steel-700">{{ r.expires_at ?? '—' }}</td>
                            <td class="px-4 py-3.5 text-right">
                                <Link :href="route('document-requests.show', r.id)" class="text-sm text-accent-700 hover:text-accent-900">View</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-2.5 p-3 md:hidden">
                <Link
                    v-for="r in documentRequests.data"
                    :key="r.id"
                    :href="route('document-requests.show', r.id)"
                    class="block rounded-card border border-divider p-3.5"
                >
                    <div class="flex items-start gap-2.5">
                        <span class="grid h-9 w-9 flex-none place-items-center rounded-control bg-steel-100 font-heading text-sm text-steel-800">{{ initials(r.client.name) }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] font-medium text-ink">{{ r.client.name }}</span>
                            <span class="block text-xs text-steel-600">{{ r.items_count }} item{{ r.items_count === 1 ? '' : 's' }}</span>
                        </span>
                        <StatusTag :label="STATUS_LABELS[r.display_status]" />
                    </div>
                    <div class="mt-2.5 flex flex-wrap gap-3 text-xs text-steel-700">
                        <span>Due {{ r.due_at ?? '—' }}</span>
                        <span>Expires {{ r.expires_at ?? '—' }}</span>
                    </div>
                </Link>
            </div>

            <Pagination :links="documentRequests.links" />
        </Card>
    </AuthenticatedLayout>
</template>
