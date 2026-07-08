<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    boards: Array,
});

const form = useForm({
    board_id: '',
});

const submit = () => {
    form.post('/integrations/social/select-pinterest-board', {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <AppLayout>
        <template #header>Select Pinterest Board</template>
        <Head title="Select Pinterest Board" />

        <div class="max-w-2xl mx-auto">
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center mb-4">
                    <div class="flex items-center justify-center w-10 h-10 rounded-full text-white text-lg font-bold bg-red-700">
                        P
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900">Select a board</p>
                        <p class="text-xs text-gray-500">Pins will be created on this board</p>
                    </div>
                </div>

                <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-md">
                    <p class="text-xs text-blue-800">
                        Pinterest pins must be attached to a board. Each connected account maps to one board.
                        To post to multiple boards, disconnect and re-connect Pinterest multiple times, picking a different board each time.
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div v-if="boards.length === 0" class="p-4 bg-yellow-50 border border-yellow-200 rounded-md">
                        <p class="text-sm text-yellow-800">
                            No boards found. Create at least one board on Pinterest first, then come back.
                        </p>
                    </div>

                    <div v-else class="space-y-2 max-h-96 overflow-y-auto">
                        <label v-for="board in boards" :key="board.id"
                            class="flex items-start p-3 border rounded-md cursor-pointer hover:bg-gray-50 transition"
                            :class="form.board_id === board.id ? 'border-brand-400 bg-brand-50' : 'border-gray-200'">
                            <input type="radio" v-model="form.board_id" :value="board.id"
                                class="mt-1 border-gray-300 text-brand-600 focus:ring-brand-500" />
                            <div class="ml-3 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm font-medium text-gray-900">{{ board.name }}</p>
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold rounded"
                                        :class="board.privacy === 'PUBLIC' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'">
                                        {{ board.privacy }}
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    Board ID: {{ board.id }}
                                </p>
                                <p v-if="board.description" class="text-xs text-gray-400 mt-1 line-clamp-2">
                                    {{ board.description }}
                                </p>
                            </div>
                        </label>
                    </div>

                    <p v-if="form.errors.board_id" class="text-sm text-red-600">{{ form.errors.board_id }}</p>

                    <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-100">
                        <Link href="/social-accounts"
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                            Cancel
                        </Link>
                        <button type="submit" :disabled="form.processing || !form.board_id"
                            class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-md hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            {{ form.processing ? 'Connecting...' : 'Connect Board' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
