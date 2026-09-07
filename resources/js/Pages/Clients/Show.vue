<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import Card from '@/Components/Card.vue';
import StatusTag from '@/Components/StatusTag.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    client: {
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
    archiveForm.post(route('clients.archive', props.client.id), {
        preserveScroll: true,
        onFinish: () => {
            confirmingArchive.value = false;
        },
    });
};
</script>

<template>
    <Head :title="props.client.name" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-[640px]">
            <Link :href="route('clients.index')" class="mb-4 inline-flex items-center gap-1.5 text-sm text-accent-700 hover:text-accent-900">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M15 6l-6 6 6 6" /></svg>
                Clients
            </Link>

            <Card class="p-5">
                <div class="flex flex-wrap items-start gap-4">
                    <span class="grid h-14 w-14 flex-none place-items-center rounded-card bg-accent-100 font-heading text-xl text-accent-800">
                        {{ props.client.name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase() }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <h1 class="font-heading text-2xl text-ink">{{ props.client.name }}</h1>
                        <p class="mt-1 break-all text-sm text-steel-700">{{ props.client.email }}</p>
                        <StatusTag class="mt-2" :label="props.client.archived_at ? 'Archived' : 'Active'" />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="route('clients.edit', props.client.id)">
                            <SecondaryButton type="button">Edit</SecondaryButton>
                        </Link>
                        <PrimaryButton
                            v-if="!props.client.archived_at"
                            type="button"
                            :disabled="archiveForm.processing"
                            @click="openArchiveConfirm"
                        >
                            Archive
                        </PrimaryButton>
                    </div>
                </div>
            </Card>
        </div>

        <ConfirmDialog
            :show="confirmingArchive"
            title="Archive this client?"
            body="Their document requests will remain but the client can no longer be edited."
            confirm-label="Archive"
            danger
            :processing="archiveForm.processing"
            @confirm="archive"
            @cancel="confirmingArchive = false"
        />
    </AuthenticatedLayout>
</template>
