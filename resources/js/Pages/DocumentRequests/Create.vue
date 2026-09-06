<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    clients: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    client_id: '',
    message: '',
    due_at: '',
    expires_at: '',
    items: [{ name: '' }],
});

const addItem = () => form.items.push({ name: '' });
const removeItem = (index) => form.items.splice(index, 1);

const submit = () => {
    form.post(route('document-requests.store'));
};
</script>

<template>
    <Head title="New Document Request" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                New Document Request
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-xl sm:px-6 lg:px-8">
                <div v-if="clients.length === 0" class="overflow-hidden bg-white p-8 text-center shadow-sm sm:rounded-lg">
                    <h3 class="text-sm font-medium text-gray-900">You need a client first</h3>
                    <p class="mt-1 text-sm text-gray-500">Add a client before you can create a document request for them.</p>
                    <Link :href="route('clients.create')" class="mt-4 inline-block">
                        <PrimaryButton type="button">Add a client</PrimaryButton>
                    </Link>
                </div>
                <div v-else class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <form @submit.prevent="submit">
                        <div>
                            <InputLabel for="client_id" value="Client" />
                            <select
                                id="client_id"
                                v-model="form.client_id"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                required
                            >
                                <option value="" disabled>Select a client</option>
                                <option v-for="client in clients" :key="client.id" :value="client.id">
                                    {{ client.name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.client_id" />
                        </div>

                        <div class="mt-4">
                            <InputLabel for="message" value="Message (optional)" />
                            <textarea
                                id="message"
                                v-model="form.message"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                rows="3"
                            ></textarea>
                            <InputError class="mt-2" :message="form.errors.message" />
                        </div>

                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel for="due_at" value="Due date (optional)" />
                                <TextInput id="due_at" type="date" class="mt-1 block w-full" v-model="form.due_at" />
                                <InputError class="mt-2" :message="form.errors.due_at" />
                            </div>
                            <div>
                                <InputLabel for="expires_at" value="Expires (optional)" />
                                <TextInput id="expires_at" type="date" class="mt-1 block w-full" v-model="form.expires_at" />
                                <InputError class="mt-2" :message="form.errors.expires_at" />
                            </div>
                        </div>

                        <fieldset class="mt-6">
                            <legend class="text-sm font-medium text-gray-700">Requested documents</legend>
                            <div v-for="(item, index) in form.items" :key="`new-${index}`" class="mt-2">
                                <div class="flex items-center gap-2">
                                    <TextInput
                                        :id="`items-${index}-name`"
                                        type="text"
                                        class="block w-full"
                                        v-model="item.name"
                                        placeholder="e.g. Bank statement"
                                        :aria-label="`Document ${index + 1} name`"
                                        required
                                    />
                                    <button
                                        type="button"
                                        class="px-2 py-2 text-sm text-red-600 hover:text-red-900"
                                        :disabled="form.items.length === 1"
                                        @click="removeItem(index)"
                                    >
                                        Remove
                                    </button>
                                </div>
                                <InputError class="mt-1" :message="form.errors[`items.${index}.name`]" />
                            </div>
                            <InputError class="mt-2" :message="form.errors.items" />
                            <button type="button" class="mt-2 text-sm text-indigo-600 hover:text-indigo-900" @click="addItem">
                                + Add document
                            </button>
                        </fieldset>

                        <div class="mt-6 flex items-center justify-end gap-4">
                            <Link :href="route('document-requests.index')">
                                <SecondaryButton type="button">Cancel</SecondaryButton>
                            </Link>

                            <PrimaryButton
                                :disabled="form.processing"
                            >
                                Create Request
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
