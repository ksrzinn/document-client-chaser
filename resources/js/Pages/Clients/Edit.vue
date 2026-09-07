<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    client: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: props.client.name,
    email: props.client.email,
});

const submit = () => {
    form.put(route('clients.update', props.client.id));
};
</script>

<template>
    <Head title="Edit Client" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-heading text-xl text-ink">Edit client</h2>
        </template>

        <div class="mx-auto max-w-[520px]">
            <Link :href="route('clients.show', props.client.id)" class="mb-4 inline-flex items-center gap-1.5 text-sm text-accent-700 hover:text-accent-900">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M15 6l-6 6 6 6" /></svg>
                {{ props.client.name }}
            </Link>
            <Card class="p-6">
                <h1 class="font-heading text-2xl text-ink">Edit client</h1>
                <form @submit.prevent="submit" class="mt-5 flex flex-col gap-4">
                    <div>
                        <InputLabel for="name" value="Client name" />
                        <TextInput id="name" type="text" class="mt-1" v-model="form.name" required autofocus />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput id="email" type="email" class="mt-1" v-model="form.email" required />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div class="mt-2 flex items-center justify-end gap-3">
                        <Link :href="route('clients.show', props.client.id)">
                            <SecondaryButton type="button">Cancel</SecondaryButton>
                        </Link>
                        <PrimaryButton :disabled="form.processing">Save changes</PrimaryButton>
                    </div>
                </form>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>
