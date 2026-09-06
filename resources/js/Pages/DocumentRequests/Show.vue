<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { formatDateTime } from '@/format.js';

const props = defineProps({
    documentRequest: {
        type: Object,
        required: true,
    },
});

const archiveForm = useForm({});
const confirmingArchive = ref(false);
const openArchiveConfirm = () => {
    confirmingArchive.value = true;
};
const archive = () => {
    archiveForm.post(route('document-requests.archive', props.documentRequest.id), {
        preserveScroll: true,
        onFinish: () => {
            confirmingArchive.value = false;
        },
    });
};

const sendForm = useForm({});
const confirmingSend = ref(false);
const openSendConfirm = () => {
    if (!props.documentRequest.sent_at) {
        send();
        return;
    }
    confirmingSend.value = true;
};
const send = () => {
    sendForm.post(route('document-requests.send', props.documentRequest.id), {
        preserveScroll: true,
        onFinish: () => {
            confirmingSend.value = false;
        },
    });
};

const copyLinkForm = useForm({});
const copiedLink = ref(null);
const copyLink = () => {
    copyLinkForm.post(route('document-requests.access-link', props.documentRequest.id), {
        preserveScroll: true,
        onSuccess: () => {
            copiedLink.value = usePage().props.flash.accessLink ?? null;
            if (copiedLink.value) {
                navigator.clipboard?.writeText(copiedLink.value).catch(() => {});
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
                            <dd class="mt-1 text-sm text-gray-900">{{ documentRequest.sent_at ? formatDateTime(documentRequest.sent_at) : 'Not sent yet' }}</dd>
                        </div>
                        <div v-if="documentRequest.completed_at">
                            <dt class="text-sm font-medium text-gray-500">Completed</dt>
                            <dd class="mt-1 text-sm font-medium text-green-700">{{ formatDateTime(documentRequest.completed_at) }}</dd>
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

                    <div class="mt-6 flex flex-wrap items-center gap-4">
                        <Link :href="route('document-requests.edit', documentRequest.id)">
                            <SecondaryButton type="button">Edit</SecondaryButton>
                        </Link>
                        <PrimaryButton
                            v-if="documentRequest.status !== 'archived'"
                            type="button"
                            :disabled="archiveForm.processing"
                            @click="openArchiveConfirm"
                        >
                            Archive
                        </PrimaryButton>
                        <PrimaryButton
                            v-if="documentRequest.status !== 'archived'"
                            type="button"
                            :disabled="copyLinkForm.processing"
                            @click="copyLink"
                        >
                            Copy secure link
                        </PrimaryButton>
                        <PrimaryButton
                            v-if="documentRequest.status !== 'archived'"
                            type="button"
                            :disabled="sendForm.processing"
                            @click="openSendConfirm"
                        >
                            {{ documentRequest.sent_at ? 'Resend' : 'Send' }}
                        </PrimaryButton>
                    </div>
                    <div v-if="copiedLink" class="mt-2 flex items-center gap-2">
                        <input
                            type="text"
                            readonly
                            :value="copiedLink"
                            class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            @focus="$event.target.select()"
                        />
                    </div>
                    <p v-if="$page.props.flash.accessLinkExists && !copiedLink" class="mt-2 text-sm text-gray-600">
                        A secure link already exists for this request.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>

    <ConfirmDialog
        :show="confirmingArchive"
        title="Archive this request?"
        body="The client will no longer be able to access the upload link."
        confirm-label="Archive"
        danger
        :processing="archiveForm.processing"
        @confirm="archive"
        @cancel="confirmingArchive = false"
    />
    <ConfirmDialog
        :show="confirmingSend"
        title="Resend this request?"
        body="The previous link will stop working."
        confirm-label="Resend"
        :processing="sendForm.processing"
        @confirm="send"
        @cancel="confirmingSend = false"
    />
</template>
