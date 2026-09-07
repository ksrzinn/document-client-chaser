<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const page = usePage();
const userMenuOpen = ref(false);

function initials(name) {
    return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}

const navLinkClass = (active) => [
    'flex items-center gap-2.5 rounded-control px-3 py-2.5 text-sm font-medium transition',
    active ? 'bg-accent-100 text-accent-800' : 'text-steel-700 hover:bg-steel-100',
];
</script>

<template>
    <aside class="flex w-[250px] flex-none flex-col gap-1.5 border-r border-divider bg-white px-3.5 py-5">
        <Link :href="route('dashboard')" class="mb-4 flex items-center gap-2.5 px-2">
            <span class="grid h-7 w-7 place-items-center rounded-control border border-accent text-accent">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /><path d="M8.5 14l2 2 4-4.5" /></svg>
            </span>
            <span class="font-heading text-base uppercase tracking-wide text-ink">Document Chaser</span>
        </Link>

        <Link :href="route('dashboard')" :class="navLinkClass(route().current('dashboard'))">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" /><rect x="14" y="3" width="7" height="7" /><rect x="14" y="14" width="7" height="7" /><rect x="3" y="14" width="7" height="7" /></svg>
            Dashboard
        </Link>
        <Link :href="route('clients.index')" :class="navLinkClass(route().current('clients.*'))">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3" /><path d="M3 20c0-3.2 2.9-5 6-5s6 1.8 6 5" /><path d="M17 5a3 3 0 0 1 0 6" /><path d="M19.5 19.6c-.2-1.9-1.2-3.3-2.7-4.1" /></svg>
            Clients
        </Link>
        <Link :href="route('document-requests.index')" :class="navLinkClass(route().current('document-requests.*'))">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /><path d="M9 13h6M9 17h4" /></svg>
            Document Requests
        </Link>

        <div class="relative mt-auto border-t border-divider pt-3">
            <button
                type="button"
                :aria-expanded="userMenuOpen"
                class="flex w-full items-center gap-2.5 rounded-control p-2 text-left hover:bg-steel-100"
                @click="userMenuOpen = !userMenuOpen"
            >
                <span class="grid h-[34px] w-[34px] flex-none place-items-center rounded-control bg-accent-100 font-heading text-sm text-accent-800">
                    {{ initials(page.props.auth.user.name) }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-ink">{{ page.props.auth.user.name }}</span>
                    <span class="block truncate text-xs text-steel-600">{{ page.props.auth.user.email }}</span>
                </span>
            </button>
            <div
                v-if="userMenuOpen"
                class="absolute inset-x-2 bottom-[58px] z-10 rounded-card border border-divider bg-white p-1.5 shadow-md"
            >
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="block w-full rounded px-2.5 py-2 text-left text-sm text-[#9a3324] hover:bg-steel-100"
                >
                    Log out
                </Link>
            </div>
        </div>
    </aside>
</template>
