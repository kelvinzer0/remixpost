<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    channels: Array,
});

const form = useForm({
    channel_id: '',
});

const submit = () => {
    form.post('/integrations/social/select-youtube-channel', {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <AppLayout>
        <template #header>Select YouTube Channel</template>
        <Head title="Select YouTube Channel" />

        <div class="max-w-2xl mx-auto">
            <div class="bg-blue-50 border border-blue-200 rounded-md p-4 mb-6">
                <p class="text-sm text-blue-800">
                    Multiple YouTube channels detected (personal + brand accounts).
                    Select which channel you want to connect for posting videos.
                </p>
            </div>

            <form @submit.prevent="submit" class="bg-white p-6 rounded-lg shadow space-y-4">
                <div v-if="channels.length === 0" class="text-center py-8 text-gray-500">
                    No YouTube channels found for this Google account.
                </div>

                <label v-for="ch in channels" :key="ch.id"
                    class="flex items-center p-4 border border-gray-200 rounded-md cursor-pointer hover:bg-gray-50">
                    <input type="radio" v-model="form.channel_id" :value="ch.id"
                        class="text-brand-600 focus:ring-brand-500" />
                    <img v-if="ch.thumbnail" :src="ch.thumbnail" :alt="ch.title"
                        class="w-12 h-12 rounded-full ml-3" />
                    <div v-else class="flex items-center justify-center w-12 h-12 rounded-full bg-red-600 text-white text-lg font-bold ml-3">
                        ▶
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900">{{ ch.title }}</p>
                        <p class="text-xs text-gray-500 capitalize">{{ ch.type }} account</p>
                    </div>
                </label>

                <div v-if="form.errors.channel_id" class="text-sm text-red-600">{{ form.errors.channel_id }}</div>

                <div class="flex items-center justify-between pt-4">
                    <Link href="/social-accounts"
                        class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="form.processing || !form.channel_id"
                        class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700 disabled:opacity-50">
                        Connect Channel
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
