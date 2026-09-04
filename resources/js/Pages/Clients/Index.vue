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
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
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
                            <tr v-if="clients.data.length === 0">
                                <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">
                                    No clients yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
