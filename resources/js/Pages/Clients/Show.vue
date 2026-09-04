<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    client: {
        type: Object,
        required: true,
    },
});

const archive = () => {
    router.post(route('clients.archive', props.client.id));
};
</script>

<template>
    <Head :title="client.name" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ client.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <dl class="space-y-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Name</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ client.name }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Email</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ client.email }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Status</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ client.archived_at ? 'Archived' : 'Active' }}
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-6 flex items-center gap-4">
                        <Link :href="route('clients.edit', client.id)">
                            <SecondaryButton type="button">Edit</SecondaryButton>
                        </Link>
                        <PrimaryButton
                            v-if="!client.archived_at"
                            type="button"
                            @click="archive"
                        >
                            Archive
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
