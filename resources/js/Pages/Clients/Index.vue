<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusTag from '@/Components/StatusTag.vue';
import Pagination from '@/Components/Pagination.vue';
import Card from '@/Components/Card.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    clients: {
        type: Object,
        required: true,
    },
});

function initials(name) {
    return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}
</script>

<template>
    <Head title="Clients" />

    <AuthenticatedLayout>
        <PageHeader title="Clients">
            {{ clients.data.length }} client{{ clients.data.length === 1 ? '' : 's' }}
            <template #actions>
                <Link :href="route('clients.create')" class="inline-flex min-h-[44px] items-center gap-2 rounded-control bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-600">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                    New client
                </Link>
            </template>
        </PageHeader>

        <EmptyState
            v-if="clients.data.length === 0"
            title="No clients yet"
            description="Add your first client to start sending document requests."
        >
            <template #action>
                <Link :href="route('clients.create')" class="inline-flex min-h-[44px] items-center rounded-control bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-600">
                    Add client
                </Link>
            </template>
        </EmptyState>

        <Card v-else>
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[520px] text-sm">
                    <thead>
                        <tr class="border-b border-divider text-left text-xs uppercase tracking-wide text-steel-600">
                            <th class="px-4 py-3 font-medium">Client</th>
                            <th class="px-4 py-3 font-medium">Email</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="client in clients.data" :key="client.id" class="border-b border-divider last:border-b-0 hover:bg-steel-100/50">
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <span class="grid h-8 w-8 flex-none place-items-center rounded-control bg-accent-100 font-heading text-sm text-accent-800">{{ initials(client.name) }}</span>
                                    <Link :href="route('clients.show', client.id)" class="font-medium text-ink hover:text-accent-700">{{ client.name }}</Link>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-steel-700">{{ client.email }}</td>
                            <td class="px-4 py-3.5"><StatusTag :label="client.archived_at ? 'Archived' : 'Active'" /></td>
                            <td class="px-4 py-3.5 text-right">
                                <Link :href="route('clients.show', client.id)" class="text-sm text-accent-700 hover:text-accent-900">View</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-2.5 p-3 md:hidden">
                <Link
                    v-for="client in clients.data"
                    :key="client.id"
                    :href="route('clients.show', client.id)"
                    class="flex min-h-[44px] items-center gap-3 rounded-card border border-divider p-3.5"
                >
                    <span class="grid h-10 w-10 flex-none place-items-center rounded-control bg-accent-100 font-heading text-base text-accent-800">{{ initials(client.name) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[15px] font-medium text-ink">{{ client.name }}</span>
                        <span class="block truncate text-xs text-steel-600">{{ client.email }}</span>
                        <StatusTag class="mt-1" :label="client.archived_at ? 'Archived' : 'Active'" />
                    </span>
                </Link>
            </div>

            <Pagination :links="clients.links" />
        </Card>
    </AuthenticatedLayout>
</template>
