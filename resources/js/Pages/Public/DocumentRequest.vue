<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    documentRequest: {
        type: Object,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
});

const state = reactive({});
props.documentRequest.items.forEach((item) => {
    state[item.id] = {
        file: null,
        uploading: false,
        error: null,
        received: item.status === 'received',
    };
});

function onFileChange(itemId, event) {
    state[itemId].file = event.target.files[0] ?? null;
    state[itemId].error = null;
}

function upload(itemId) {
    const entry = state[itemId];

    if (!entry.file) {
        entry.error = 'Choose a file first.';
        return;
    }

    const formData = new FormData();
    formData.append('file', entry.file);

    entry.uploading = true;
    entry.error = null;

    router.post(
        route('public.document-request.upload', { token: props.token, item: itemId }),
        formData,
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                entry.received = true;
                entry.file = null;
            },
            onError: (errors) => {
                entry.error = errors.file ?? 'Upload failed. Please try again.';
            },
            onFinish: () => {
                entry.uploading = false;
            },
        }
    );
}
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

            <div
                v-if="documentRequest.status === 'completed'"
                class="mt-4 rounded border border-green-200 bg-green-50 p-3 text-sm text-green-800"
            >
                All requested documents have been received. Thank you!
            </div>

            <div class="mt-6 space-y-4">
                <h2 class="text-sm font-medium text-gray-500">Documents requested</h2>

                <div
                    v-for="item in documentRequest.items"
                    :key="item.id"
                    class="rounded border border-gray-200 p-4"
                >
                    <h3 class="text-sm font-medium text-gray-900">{{ item.name }}</h3>

                    <p v-if="state[item.id].received" class="mt-2 text-sm font-medium text-green-700">
                        ✓ Received
                    </p>

                    <div v-else class="mt-2 flex items-center gap-2">
                        <input
                            type="file"
                            class="text-sm text-gray-700"
                            @change="onFileChange(item.id, $event)"
                        />
                        <button
                            type="button"
                            class="rounded bg-gray-800 px-3 py-1 text-sm text-white disabled:opacity-50"
                            :disabled="state[item.id].uploading"
                            @click="upload(item.id)"
                        >
                            {{ state[item.id].uploading ? 'Uploading…' : 'Upload' }}
                        </button>
                    </div>

                    <p v-if="state[item.id].error" class="mt-1 text-sm text-red-600">
                        {{ state[item.id].error }}
                    </p>
                </div>
            </div>

            <div class="mt-6 space-y-1 text-sm text-gray-700">
                <p v-if="documentRequest.due_at">Due: {{ documentRequest.due_at }}</p>
                <p v-if="documentRequest.expires_at">Request expires: {{ documentRequest.expires_at }}</p>
            </div>
        </div>
    </GuestLayout>
</template>
