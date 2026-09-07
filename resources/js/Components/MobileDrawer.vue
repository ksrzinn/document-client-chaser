<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);
const page = usePage();

function onKeydown(event) {
    if (event.key === 'Escape' && props.show) emit('close');
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));

const navLinkClass = (active) => [
    'flex items-center gap-2.5 rounded-control px-3 py-3 text-[15px] font-medium',
    active ? 'bg-accent-100 text-accent-800' : 'text-steel-700 hover:bg-steel-100',
];
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 bg-[#1d2d3d]/55" @click="emit('close')" />
        <nav
            v-if="show"
            class="fixed inset-y-0 right-0 z-50 flex w-[82%] max-w-[320px] flex-col bg-white p-4 shadow-lg"
        >
            <div class="mb-4 flex items-center justify-between">
                <span class="font-heading text-sm uppercase tracking-wide text-ink">Menu</span>
                <button
                    type="button"
                    aria-label="Close menu"
                    class="grid h-11 w-11 place-items-center rounded-control border border-divider text-ink"
                    @click="emit('close')"
                >
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
                </button>
            </div>

            <Link :href="route('dashboard')" :class="navLinkClass(route().current('dashboard'))" @click="emit('close')">Dashboard</Link>
            <Link :href="route('clients.index')" :class="navLinkClass(route().current('clients.*'))" @click="emit('close')">Clients</Link>
            <Link :href="route('document-requests.index')" :class="navLinkClass(route().current('document-requests.*'))" @click="emit('close')">Document Requests</Link>

            <div class="mt-auto flex items-center gap-2.5 border-t border-divider pt-4">
                <span class="text-sm text-steel-700">{{ page.props.auth.user.name }}</span>
            </div>
            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="mt-2 rounded-control px-3 py-3 text-left text-[15px] font-medium text-[#9a3324] hover:bg-steel-100"
            >
                Log out
            </Link>
        </nav>
    </Teleport>
</template>
