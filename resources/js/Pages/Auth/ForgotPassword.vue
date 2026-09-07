<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Forgot Password" />

        <h1 class="font-heading text-2xl text-ink">Reset your password</h1>
        <p class="mb-6 mt-1 text-sm leading-relaxed text-steel-700">
            Tell us your email address and we'll send you a link to choose a new password.
        </p>

        <div v-if="status" class="mb-4 text-sm font-medium text-[#1c4a34]">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-4">
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" type="email" class="mt-1" v-model="form.email" required autofocus autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <PrimaryButton class="w-full" :disabled="form.processing">Email reset link</PrimaryButton>
        </form>
    </GuestLayout>
</template>
