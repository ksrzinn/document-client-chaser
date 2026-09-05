<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    documentRequest: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head title="Document Request" />

    <GuestLayout>
        <div class="mx-auto max-w-xl">
            <h1 class="text-xl font-semibold text-gray-800">Document Request</h1>

            <p class="mt-4 text-sm text-gray-700">
                Hi, {{ documentRequest.client_name }}
            </p>

            <p v-if="documentRequest.message" class="mt-2 text-sm text-gray-700">
                {{ documentRequest.message }}
            </p>

            <div class="mt-6">
                <h2 class="text-sm font-medium text-gray-500">Documents requested</h2>
                <ul class="mt-2 list-disc pl-5">
                    <li v-for="(item, index) in documentRequest.items" :key="index" class="text-sm text-gray-900">
                        {{ item.name }}
                    </li>
                </ul>
            </div>

            <div class="mt-6 space-y-1 text-sm text-gray-700">
                <p v-if="documentRequest.due_at">Due: {{ documentRequest.due_at }}</p>
                <p v-if="documentRequest.expires_at">Request expires: {{ documentRequest.expires_at }}</p>
            </div>

            <p class="mt-6 text-sm text-gray-500">Upload functionality will be added later.</p>
        </div>
    </GuestLayout>
</template>
