<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    clients: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head title="Clients" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Clients
                </h2>
                <Link :href="route('clients.create')">
                    <PrimaryButton type="button">New Client</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div v-if="clients.data.length === 0" class="overflow-hidden bg-white p-8 text-center shadow-sm sm:rounded-lg">
                    <h3 class="text-sm font-medium text-gray-900">No clients yet</h3>
                    <p class="mt-1 text-sm text-gray-500">Add your first client to start sending document requests.</p>
                    <Link :href="route('clients.create')" class="mt-4 inline-block">
                        <PrimaryButton type="button">Add your first client</PrimaryButton>
                    </Link>
                </div>
                <div v-else class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                    <th class="px-6 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr v-for="client in clients.data" :key="client.id">
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{{ client.name }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ client.email }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                        {{ client.archived_at ? 'Archived' : 'Active' }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm">
                                        <Link :href="route('clients.show', client.id)" class="text-indigo-600 hover:text-indigo-900">
                                            View
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="clients.links.length > 3" class="mt-4 flex justify-center gap-1 px-6 pb-4">
                        <template v-for="(link, index) in clients.links" :key="index">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                v-html="link.label"
                                class="rounded px-3 py-1 text-sm"
                                :class="link.active ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100'"
                            />
                            <span
                                v-else
                                v-html="link.label"
                                class="rounded px-3 py-1 text-sm text-gray-400"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
