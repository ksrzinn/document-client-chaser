<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <h1 class="font-heading text-2xl text-ink">Welcome back</h1>
        <p class="mb-6 mt-1 text-sm text-steel-700">Sign in to manage your document requests.</p>

        <div v-if="status" class="mb-4 text-sm font-medium text-[#1c4a34]">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-4">
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" type="email" class="mt-1" v-model="form.email" required autofocus autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <div class="flex items-baseline justify-between gap-2">
                    <InputLabel for="password" value="Password" />
                    <Link v-if="canResetPassword" :href="route('password.request')" class="text-xs text-accent-700 hover:text-accent-900">
                        Forgot password?
                    </Link>
                </div>
                <TextInput id="password" type="password" class="mt-1" v-model="form.password" required autocomplete="current-password" />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <label class="flex min-h-[44px] cursor-pointer items-center gap-2.5 text-sm text-ink">
                <Checkbox name="remember" v-model:checked="form.remember" />
                Remember me on this device
            </label>

            <PrimaryButton class="w-full" :disabled="form.processing">Log in</PrimaryButton>

            <p class="text-center text-sm text-steel-700">
                Don't have an account? <Link :href="route('register')" class="text-accent-700 hover:text-accent-900">Create your account</Link>
            </p>
        </form>
    </GuestLayout>
</template>
