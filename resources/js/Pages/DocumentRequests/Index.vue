<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    documentRequests: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head title="Document Requests" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Document Requests
                </h2>
                <Link :href="route('document-requests.create')">
                    <PrimaryButton type="button">New Request</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Client</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Items</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Due</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Expires</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="documentRequest in documentRequests.data" :key="documentRequest.id">
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{{ documentRequest.client.name }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 capitalize">{{ documentRequest.status }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ documentRequest.items_count }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ documentRequest.due_at ?? '—' }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ documentRequest.expires_at ?? '—' }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm">
                                    <Link :href="route('document-requests.show', documentRequest.id)" class="text-indigo-600 hover:text-indigo-900">
                                        View
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="documentRequests.data.length === 0">
                                <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">
                                    No document requests yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="documentRequests.links.length > 3" class="mt-4 flex justify-center gap-1 px-6 pb-4">
                        <template v-for="(link, index) in documentRequests.links" :key="index">
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
