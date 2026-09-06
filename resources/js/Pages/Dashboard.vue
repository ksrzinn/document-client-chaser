<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800"
            >
                Dashboard
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Link :href="route('clients.index')" class="rounded-lg bg-white p-6 shadow-sm hover:bg-gray-50">
                        <h3 class="text-base font-semibold text-gray-900">Clients</h3>
                        <p class="mt-1 text-sm text-gray-500">View and manage your clients.</p>
                    </Link>
                    <Link :href="route('document-requests.index')" class="rounded-lg bg-white p-6 shadow-sm hover:bg-gray-50">
                        <h3 class="text-base font-semibold text-gray-900">Document Requests</h3>
                        <p class="mt-1 text-sm text-gray-500">View and manage document requests.</p>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
