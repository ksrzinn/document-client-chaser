<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    documentRequest: {
        type: Object,
        required: true,
    },
});

const archive = () => {
    router.post(route('document-requests.archive', props.documentRequest.id));
};

const sending = ref(false);

const send = () => {
    sending.value = true;
    router.post(route('document-requests.send', props.documentRequest.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            sending.value = false;
        },
    });
};

const copyLink = () => {
    router.post(route('document-requests.access-link', props.documentRequest.id), {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            const link = page.props.flash?.accessLink;
            if (link) {
                Promise.resolve(navigator.clipboard?.writeText(link)).catch(() => {});
                window.prompt('Secure link (copy manually if needed):', link);
            } else {
                alert('A secure link already exists for this request.');
            }
        },
    });
};
</script>

<template>
    <Head title="Document Request" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Document Request — {{ documentRequest.client.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <dl class="space-y-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Client</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ documentRequest.client.name }} ({{ documentRequest.client.email }})</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Status</dt>
                            <dd class="mt-1 text-sm text-gray-900 capitalize">{{ documentRequest.status }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Sent</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ documentRequest.sent_at ?? 'Not sent yet' }}</dd>
                        </div>
                        <div v-if="documentRequest.message">
                            <dt class="text-sm font-medium text-gray-500">Message</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ documentRequest.message }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Due date</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ documentRequest.due_at ?? 'None' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Expires</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ documentRequest.expires_at ?? 'None' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Requested documents</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                <ul class="space-y-2">
                                    <li v-for="item in documentRequest.items" :key="item.id">
                                        <div class="flex items-center gap-2">
                                            <span>{{ item.name }}</span>
                                            <span
                                                class="rounded px-2 py-0.5 text-xs font-medium"
                                                :class="item.status === 'received' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                            >
                                                {{ item.status === 'received' ? 'Received' : 'Missing' }}
                                            </span>
                                        </div>
                                        <ul v-if="item.documents.length" class="mt-1 list-disc pl-5 text-xs text-gray-500">
                                            <li v-for="document in item.documents" :key="document.id">
                                                {{ document.original_filename }} ({{ Math.round(document.size / 1024) }} KB, {{ document.mime_type }})
                                            </li>
                                        </ul>
                                    </li>
                                </ul>
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-6 flex items-center gap-4">
                        <Link :href="route('document-requests.edit', documentRequest.id)">
                            <SecondaryButton type="button">Edit</SecondaryButton>
                        </Link>
                        <PrimaryButton
                            v-if="documentRequest.status !== 'archived'"
                            type="button"
                            @click="archive"
                        >
                            Archive
                        </PrimaryButton>
                        <PrimaryButton type="button" @click="copyLink">Copy secure link</PrimaryButton>
                        <PrimaryButton
                            v-if="documentRequest.status !== 'archived'"
                            type="button"
                            :disabled="sending"
                            @click="send"
                        >
                            {{ documentRequest.sent_at ? 'Resend' : 'Send' }}
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
