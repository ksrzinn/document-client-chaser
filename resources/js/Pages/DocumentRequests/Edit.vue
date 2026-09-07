<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/Card.vue';
import ClientPreviewPanel from '@/Components/ClientPreviewPanel.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextareaInput from '@/Components/TextareaInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    documentRequest: {
        type: Object,
        required: true,
    },
    clients: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    client_id: props.documentRequest.client_id,
    message: props.documentRequest.message ?? '',
    due_at: props.documentRequest.due_at ?? '',
    expires_at: props.documentRequest.expires_at ?? '',
    items: props.documentRequest.items.map((item) => ({ id: item.id, name: item.name })),
});

const addItem = () => form.items.push({ name: '' });
const removeItem = (index) => form.items.splice(index, 1);

const submit = () => {
    form.put(route('document-requests.update', props.documentRequest.id));
};

const selectedClient = computed(() => props.clients.find((c) => String(c.id) === String(form.client_id)) ?? null);
</script>

<template>
    <Head title="Edit Document Request" />

    <AuthenticatedLayout>
        <Link :href="route('document-requests.show', props.documentRequest.id)" class="mb-4 inline-flex items-center gap-1.5 text-sm text-accent-700 hover:text-accent-900">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M15 6l-6 6 6 6" /></svg>
            Document request
        </Link>

        <h1 class="mb-5 font-heading text-2xl text-ink">Edit document request</h1>

        <div class="flex flex-wrap items-start gap-5">
            <Card class="min-w-0 flex-1 basis-[380px] p-5">
                <form @submit.prevent="submit" class="flex flex-col gap-[18px]">
                    <div>
                        <InputLabel for="client_id" value="Client" />
                        <SelectInput id="client_id" class="mt-1" v-model="form.client_id" required>
                            <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option>
                        </SelectInput>
                        <InputError class="mt-2" :message="form.errors.client_id" />
                    </div>

                    <div>
                        <InputLabel for="message">Message to the client <span class="text-steel-500">— optional</span></InputLabel>
                        <TextareaInput id="message" class="mt-1" v-model="form.message" rows="3" />
                        <InputError class="mt-2" :message="form.errors.message" />
                    </div>

                    <div class="flex flex-wrap gap-3.5">
                        <div class="min-w-0 flex-1 basis-[150px]">
                            <InputLabel for="due_at">Due date <span class="text-steel-500">— optional</span></InputLabel>
                            <TextInput id="due_at" type="date" class="mt-1" v-model="form.due_at" />
                            <InputError class="mt-2" :message="form.errors.due_at" />
                        </div>
                        <div class="min-w-0 flex-1 basis-[150px]">
                            <InputLabel for="expires_at">Link expires <span class="text-steel-500">— optional</span></InputLabel>
                            <TextInput id="expires_at" type="date" class="mt-1" v-model="form.expires_at" />
                            <InputError class="mt-2" :message="form.errors.expires_at" />
                        </div>
                    </div>

                    <fieldset class="border-0 p-0">
                        <legend class="mb-2.5 font-heading text-lg text-ink">Requested documents</legend>
                        <div class="flex flex-col gap-2.5">
                            <div v-for="(item, index) in form.items" :key="item.id ?? `new-${index}`" class="flex items-start gap-2.5 rounded-control border border-divider bg-canvas p-2.5">
                                <span class="mt-2 grid h-[26px] w-[26px] flex-none place-items-center rounded border border-divider bg-white font-heading text-xs text-steel-700">{{ index + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <label :for="`items-${index}-name`" class="mb-1 block text-[11px] uppercase tracking-wide text-steel-600">Document {{ index + 1 }}</label>
                                    <TextInput :id="`items-${index}-name`" type="text" v-model="item.name" placeholder="e.g. Bank statement" required />
                                    <InputError class="mt-1.5" :message="form.errors[`items.${index}.name`]" />
                                </div>
                                <button
                                    type="button"
                                    aria-label="Remove document"
                                    class="mt-2 grid h-[38px] w-[38px] flex-none place-items-center rounded-control border border-divider text-steel-700 hover:border-[#9a3324] hover:text-[#9a3324] disabled:cursor-not-allowed disabled:opacity-40"
                                    :disabled="form.items.length === 1"
                                    @click="removeItem(index)"
                                >
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13" /></svg>
                                </button>
                            </div>
                        </div>
                        <InputError class="mt-2" :message="form.errors.items" />
                        <button type="button" class="mt-3 flex min-h-[44px] w-full items-center justify-center gap-2 rounded-control border border-divider bg-white text-sm font-semibold text-ink hover:bg-steel-100" @click="addItem">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                            Add document
                        </button>
                    </fieldset>

                    <div class="mt-1 flex items-center justify-end gap-3">
                        <Link :href="route('document-requests.show', props.documentRequest.id)">
                            <SecondaryButton type="button">Cancel</SecondaryButton>
                        </Link>
                        <PrimaryButton :disabled="form.processing">Save changes</PrimaryButton>
                    </div>
                </form>
            </Card>

            <ClientPreviewPanel
                class="min-w-0 flex-1 basis-[300px]"
                :client-name="selectedClient?.name ?? null"
                :client-email="selectedClient?.email ?? null"
                :message="form.message"
                :items="form.items"
                :due-at="form.due_at"
                :expires-at="form.expires_at"
            />
        </div>
    </AuthenticatedLayout>
</template>
