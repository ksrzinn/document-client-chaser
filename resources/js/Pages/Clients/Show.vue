<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    client: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head title="Client" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ client.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <div class="space-y-4">
                        <div>
                            <label class="text-sm font-semibold text-gray-700">Name</label>
                            <p class="mt-1 text-gray-900">{{ client.name }}</p>
                        </div>

                        <div>
                            <label class="text-sm font-semibold text-gray-700">Email</label>
                            <p class="mt-1 text-gray-900">{{ client.email }}</p>
                        </div>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-4">
                        <Link :href="route('clients.index')">
                            <SecondaryButton type="button">Back</SecondaryButton>
                        </Link>

                        <Link :href="route('clients.edit', client.id)">
                            <PrimaryButton type="button">Edit</PrimaryButton>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
