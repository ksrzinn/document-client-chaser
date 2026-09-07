<script setup>
import { onMounted, onUnmounted } from 'vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        required: true,
    },
    body: {
        type: String,
        required: true,
    },
    confirmLabel: {
        type: String,
        default: 'Confirm',
    },
    processing: {
        type: Boolean,
        default: false,
    },
    danger: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['confirm', 'cancel']);

function onKeydown(event) {
    if (event.key === 'Escape' && props.show) {
        emit('cancel');
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="show"
            class="fixed inset-0 z-50 flex items-center justify-center bg-[#1d2d3d]/55 p-4"
            @click.self="emit('cancel')"
        >
            <Card
                role="dialog"
                aria-modal="true"
                aria-labelledby="confirm-dialog-title"
                class="w-full max-w-[440px] p-5 shadow-lg"
            >
                <h2 id="confirm-dialog-title" class="font-heading text-xl text-ink">{{ title }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-steel-700">{{ body }}</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="inline-flex min-h-[44px] items-center rounded-control border border-divider bg-white px-4 py-2 text-sm font-semibold text-ink hover:bg-steel-100"
                        @click="emit('cancel')"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="inline-flex min-h-[44px] items-center rounded-control px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-45"
                        :class="danger ? 'bg-[#9a3324] hover:bg-[#7d2a1d]' : 'bg-accent hover:bg-accent-600'"
                        :disabled="processing"
                        @click="emit('confirm')"
                    >
                        {{ confirmLabel }}
                    </button>
                </div>
            </Card>
        </div>
    </Teleport>
</template>
