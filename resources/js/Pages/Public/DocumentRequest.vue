<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import { formatDate } from '@/format.js';
import StatusTag from '@/Components/StatusTag.vue';

const props = defineProps({
    documentRequest: {
        type: Object,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
    maxSizeMb: {
        type: Number,
        required: true,
    },
    allowedExtensions: {
        type: String,
        required: true,
    },
});

const state = reactive({});

watch(
    () => props.documentRequest.items,
    (items) => {
        for (const item of items) {
            if (!state[item.id]) {
                state[item.id] = {
                    file: null,
                    uploading: false,
                    error: null,
                    received: item.status === 'received',
                };
            }
        }
    },
    { immediate: true, deep: false }
);

const allowedExtensionsAttr = computed(() =>
    props.allowedExtensions
        .split(',')
        .map((ext) => '.' + ext.trim())
        .join(',')
);

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
            <h1 class="font-heading text-2xl text-ink">Hi, {{ documentRequest.client_name }}</h1>

            <div class="mt-3 flex items-center gap-2 rounded-control border border-divider bg-white p-3 text-sm text-steel-800">
                <span class="grid h-[30px] w-[30px] flex-none place-items-center rounded-control bg-steel-100 font-heading text-xs text-steel-800">DC</span>
                <span>Requested by Document Chaser</span>
            </div>

            <p v-if="documentRequest.message" class="mt-4 rounded-r-control border-l-[3px] border-accent bg-white p-3.5 text-sm leading-relaxed text-steel-800">
                {{ documentRequest.message }}
            </p>

            <div
                v-if="documentRequest.status === 'completed'"
                class="mt-5 flex items-start gap-3 rounded-card border border-[#b9d3c3] bg-[#e7f0ea] p-4"
            >
                <span class="grid h-9 w-9 flex-none place-items-center rounded-control bg-[#245c41] text-white">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5" /></svg>
                </span>
                <span>
                    <span class="block font-heading text-lg text-[#1c4a34]">All documents received</span>
                    <span class="mt-0.5 block text-sm leading-relaxed text-[#245c41]">Thank you. Your submission is complete — nothing else is needed.</span>
                </span>
            </div>

            <h2 class="mb-2.5 mt-6 text-xs font-medium uppercase tracking-wide text-steel-600">Documents requested</h2>
            <p v-if="documentRequest.status !== 'completed'" class="mb-3 text-sm text-steel-600">
                Accepted formats: {{ allowedExtensions }}. Maximum size: {{ maxSizeMb }} MB per file.
            </p>

            <div class="flex flex-col gap-3">
                <div
                    v-for="item in documentRequest.items"
                    :key="item.id"
                    class="rounded-card border p-4"
                    :class="state[item.id].received ? 'border-[#b9d3c3] bg-[#f4f9f6]' : 'border-divider bg-white'"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="grid h-[26px] w-[26px] flex-none place-items-center rounded-control"
                            :class="state[item.id].received ? 'bg-[#e7f0ea] text-[#1c4a34]' : 'bg-steel-100 text-steel-500'"
                        >
                            <svg v-if="state[item.id].received" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5" /></svg>
                            <svg v-else width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /></svg>
                        </span>
                        <span class="min-w-0 flex-1 font-heading text-lg leading-tight text-ink">{{ item.name }}</span>
                        <StatusTag :label="state[item.id].received ? 'Received' : 'Missing'" />
                    </div>

                    <p v-if="state[item.id].received" class="ml-[38px] mt-2 text-sm font-medium text-[#245c41]">Uploaded — thank you.</p>

                    <div v-else class="mt-3.5 flex flex-col gap-2.5">
                        <label class="relative flex min-h-[48px] cursor-pointer items-center justify-center gap-2 rounded-control bg-accent px-4 font-heading text-[15px] text-white hover:bg-accent-600">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4" /><path d="M7 9l5-5 5 5" /><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" /></svg>
                            {{ state[item.id].file ? state[item.id].file.name : 'Choose a file' }}
                            <input
                                type="file"
                                class="absolute h-px w-px opacity-0"
                                :accept="allowedExtensionsAttr"
                                :aria-label="`Upload file for ${item.name}`"
                                @change="onFileChange(item.id, $event)"
                            />
                        </label>
                        <button
                            type="button"
                            class="flex min-h-[48px] w-full items-center justify-center rounded-control border border-divider bg-white text-[15px] font-semibold text-ink disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="state[item.id].uploading || !state[item.id].file"
                            @click="upload(item.id)"
                        >
                            {{ state[item.id].uploading ? 'Uploading…' : 'Upload this document' }}
                        </button>
                    </div>

                    <p v-if="state[item.id].error" class="mt-2.5 flex items-start gap-2 rounded-control border border-[#edc9c2] bg-[#fbecea] p-2.5 text-sm text-[#7d2a1d]">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" class="mt-0.5 flex-none"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16h.01" /></svg>
                        {{ state[item.id].error }}
                    </p>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap gap-x-5 gap-y-1.5 border-t border-divider pt-4 text-xs text-steel-700">
                <span v-if="documentRequest.due_at">Due <strong class="font-medium">{{ formatDate(documentRequest.due_at) }}</strong></span>
                <span v-if="documentRequest.expires_at">Link expires <strong class="font-medium">{{ formatDate(documentRequest.expires_at) }}</strong></span>
                <span class="basis-full text-steel-600">Uploads are private. Questions? Reply to the email that sent you this link.</span>
            </div>
        </div>
    </GuestLayout>
</template>
