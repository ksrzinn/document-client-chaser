<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ToastStack from '@/Components/ToastStack.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-canvas px-4 py-10">
        <Link href="/" class="mb-6 flex items-center gap-2.5">
            <span class="grid h-7 w-7 place-items-center rounded-control border border-accent text-accent">
                <ApplicationLogo class="h-4 w-4 fill-current" />
            </span>
            <span class="font-heading text-base uppercase tracking-wide text-ink">Document Chaser</span>
        </Link>

        <div class="w-full max-w-[380px] rounded-card border border-divider bg-white p-6 shadow-sm">
            <slot />
        </div>

        <ToastStack />
    </div>
</template>
