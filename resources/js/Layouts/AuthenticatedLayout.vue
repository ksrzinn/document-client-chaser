<script setup>
import { ref } from 'vue';
import AppSidebar from '@/Components/AppSidebar.vue';
import MobileDrawer from '@/Components/MobileDrawer.vue';
import ToastStack from '@/Components/ToastStack.vue';

const drawerOpen = ref(false);
</script>

<template>
    <div>
        <div class="flex min-h-screen bg-canvas">
            <AppSidebar class="hidden md:flex" />

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-divider bg-white/90 px-4 py-3 backdrop-blur md:hidden">
                    <span class="grid h-[26px] w-[26px] place-items-center rounded-control border border-accent text-accent">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /><path d="M8.5 14l2 2 4-4.5" /></svg>
                    </span>
                    <span class="mr-auto font-heading text-base uppercase tracking-wide text-ink">Document Chaser</span>
                    <button
                        type="button"
                        aria-label="Open menu"
                        :aria-expanded="drawerOpen"
                        class="grid h-11 w-11 place-items-center rounded-control border border-divider text-ink"
                        @click="drawerOpen = true"
                    >
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
                    </button>
                </header>

                <header v-if="$slots.header" class="border-b border-divider bg-white px-4 py-5 md:px-8">
                    <slot name="header" />
                </header>

                <main class="flex-1 px-4 py-6 md:px-8 md:py-8">
                    <slot />
                </main>
            </div>
        </div>

        <MobileDrawer :show="drawerOpen" @close="drawerOpen = false" />
        <ToastStack />
    </div>
</template>
