<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import Card from '@/Components/Card.vue';
import StatusTag from '@/Components/StatusTag.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { formatDateTime } from '@/format.js';
import { pushToast } from '@/toast.js';

const props = defineProps({
    documentRequest: {
        type: Object,
        required: true,
    },
});

const STATUS_LABELS = {
    draft: 'Draft',
    awaiting_client: 'Awaiting client',
    completed: 'Completed',
    expired: 'Expired',
    archived: 'Archived',
};

const receivedCount = computed(() => props.documentRequest.items.filter((i) => i.status === 'received').length);

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
        onSuccess: () => {
            copiedLink.value = usePage().props.flash.accessLink ?? null;
        },
        onFinish: () => {
            confirmingSend.value = false;
        },
    });
};

const copyLinkForm = useForm({});
const copiedLink = ref(null);
const copyLink = () => {
    // The link is already known locally (e.g. just sent/resent in this
    // session) — re-copy it directly. Hitting access-link again would
    // return "accessLinkExists" with no link, wiping out what we have.
    if (copiedLink.value) {
        navigator.clipboard?.writeText(copiedLink.value)
            .then(() => pushToast('Link copied to clipboard.'))
            .catch(() => {});
        return;
    }

    copyLinkForm.post(route('document-requests.access-link', props.documentRequest.id), {
        preserveScroll: true,
        onSuccess: () => {
            copiedLink.value = usePage().props.flash.accessLink ?? null;
            if (copiedLink.value) {
                navigator.clipboard?.writeText(copiedLink.value)
                    .then(() => pushToast('Link copied to clipboard.'))
                    .catch(() => {});
            }
        },
    });
};
</script>

<template>
    <Head title="Document Request" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-[900px]">
            <Link :href="route('document-requests.index')" class="mb-3.5 inline-flex items-center gap-1.5 text-sm text-accent-700 hover:text-accent-900">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M15 6l-6 6 6 6" /></svg>
                Document requests
            </Link>

            <Card class="mb-4 p-5">
                <div class="flex flex-wrap items-start gap-3.5">
                    <div class="min-w-0 flex-1 basis-[220px]">
                        <div class="mb-2 flex items-center gap-2.5">
                            <StatusTag :label="STATUS_LABELS[documentRequest.display_status]" />
                            <span class="text-xs text-steel-600">Request #{{ documentRequest.id }}</span>
                        </div>
                        <h1 class="font-heading text-2xl text-ink">{{ documentRequest.client.name }}</h1>
                        <p class="break-all text-sm text-steel-700">{{ documentRequest.client.email }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Link :href="route('document-requests.edit', documentRequest.id)">
                            <SecondaryButton type="button">Edit</SecondaryButton>
                        </Link>
                        <template v-if="documentRequest.display_status !== 'archived'">
                            <SecondaryButton type="button" class="!text-[#9a3324]" :disabled="archiveForm.processing" @click="openArchiveConfirm">
                                Archive
                            </SecondaryButton>
                            <PrimaryButton type="button" :disabled="sendForm.processing" @click="openSendConfirm">
                                {{ documentRequest.sent_at ? 'Resend' : 'Send request' }}
                            </PrimaryButton>
                        </template>
                    </div>
                </div>
                <p v-if="documentRequest.display_status === 'archived'" class="mt-4 rounded-control border border-steel-300 bg-steel-100 p-3 text-sm leading-relaxed text-steel-800">
                    This request is archived. The secure link no longer works and the client can't submit documents. You can still edit it.
                </p>
            </Card>

            <Card v-if="documentRequest.display_status !== 'archived'" class="mb-4 p-[18px]">
                <span class="mb-2.5 block text-[11px] uppercase tracking-wide text-steel-600">Secure link</span>
                <div class="flex flex-wrap items-center gap-2.5">
                    <input
                        type="text"
                        readonly
                        :value="copiedLink ?? ''"
                        placeholder="Copy the link to see it here"
                        aria-label="Secure upload link"
                        class="min-h-[44px] flex-1 basis-[240px] rounded-control border-divider bg-canvas text-sm text-steel-800"
                        @focus="$event.target.select()"
                    />
                    <PrimaryButton type="button" :disabled="copyLinkForm.processing" @click="copyLink">
                        Copy link
                    </PrimaryButton>
                </div>
                <p v-if="$page.props.flash.accessLinkExists && !copiedLink" class="mt-2 text-xs text-steel-600">
                    A secure link already exists for this request.
                </p>
            </Card>

            <div class="flex flex-wrap items-start gap-4">
                <section class="min-w-0 flex-1 basis-[340px] overflow-hidden rounded-card border border-divider bg-white">
                    <div class="flex items-center justify-between gap-2.5 border-b border-divider px-[18px] py-3.5">
                        <h2 class="font-heading text-lg text-ink">Requested documents</h2>
                        <span class="text-xs text-steel-700">{{ receivedCount }} of {{ documentRequest.items.length }} received</span>
                    </div>
                    <div v-for="item in documentRequest.items" :key="item.id" class="border-b border-divider px-[18px] py-3.5 last:border-b-0">
                        <div class="flex items-center gap-2.5">
                            <span
                                class="grid h-[26px] w-[26px] flex-none place-items-center rounded-control"
                                :class="item.status === 'received' ? 'bg-[#e7f0ea] text-[#1c4a34]' : 'bg-steel-100 text-steel-500'"
                            >
                                <svg v-if="item.status === 'received'" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5" /></svg>
                                <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="8" /></svg>
                            </span>
                            <span class="min-w-0 flex-1 text-[15px] font-medium text-ink">{{ item.name }}</span>
                            <StatusTag :label="item.status === 'received' ? 'Received' : 'Missing'" />
                        </div>
                        <div v-for="document in item.documents" :key="document.id" class="ml-9 mt-2.5 flex items-center gap-2.5 rounded-control border border-divider bg-canvas p-2.5">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--color-accent-700)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="flex-none"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /></svg>
                            <span class="min-w-0 flex-1 truncate text-sm">{{ document.original_filename }}</span>
                            <span class="flex-none text-xs text-steel-600">{{ Math.round(document.size / 1024) }} KB</span>
                        </div>
                        <p v-if="item.status !== 'received'" class="ml-9 mt-2 text-xs text-steel-600">Nothing uploaded yet.</p>
                    </div>
                </section>

                <aside class="min-w-0 flex-1 basis-[240px] rounded-card border border-divider bg-white p-[18px]">
                    <h2 class="mb-3.5 font-heading text-lg text-ink">Overview</h2>
                    <div class="flex flex-col gap-3">
                        <div class="flex items-baseline justify-between gap-3.5">
                            <span class="text-xs text-steel-600">Sent</span>
                            <span class="text-sm">{{ documentRequest.sent_at ? formatDateTime(documentRequest.sent_at) : 'Not sent yet' }}</span>
                        </div>
                        <div v-if="documentRequest.completed_at" class="flex items-baseline justify-between gap-3.5">
                            <span class="text-xs text-steel-600">Completed</span>
                            <span class="text-sm font-medium text-[#1c4a34]">{{ formatDateTime(documentRequest.completed_at) }}</span>
                        </div>
                        <div class="flex items-baseline justify-between gap-3.5">
                            <span class="text-xs text-steel-600">Due</span>
                            <span class="text-sm">{{ documentRequest.due_at ?? 'None' }}</span>
                        </div>
                        <div class="flex items-baseline justify-between gap-3.5">
                            <span class="text-xs text-steel-600">Expires</span>
                            <span class="text-sm">{{ documentRequest.expires_at ?? 'None' }}</span>
                        </div>
                    </div>
                    <div v-if="documentRequest.message" class="mt-4 border-t border-divider pt-3.5">
                        <span class="mb-1.5 block text-[11px] uppercase tracking-wide text-steel-600">Message</span>
                        <p class="text-sm leading-relaxed text-steel-800">{{ documentRequest.message }}</p>
                    </div>
                </aside>
            </div>
        </div>

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
    </AuthenticatedLayout>
</template>
