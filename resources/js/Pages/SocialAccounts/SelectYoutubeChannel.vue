<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    channels: Array,
});

const form = useForm({
    channel_id: '',
    upload_mode: 'video', // 'video' or 'short'
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
                    Select the channel you want to connect, and choose whether videos
                    uploaded through this connection should be published as regular
                    videos or as Shorts.
                </p>
            </div>

            <form @submit.prevent="submit" class="bg-white p-6 rounded-lg shadow space-y-6">
                <!-- Channel picker -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">1. Pick a channel</label>
                    <div v-if="channels.length === 0" class="text-center py-8 text-gray-500">
                        No YouTube channels found for this Google account.
                    </div>

                    <div v-else class="space-y-2">
                        <label v-for="ch in channels" :key="ch.id"
                            class="flex items-center p-4 border rounded-md cursor-pointer hover:bg-gray-50 transition"
                            :class="form.channel_id === ch.id ? 'border-brand-400 bg-brand-50' : 'border-gray-200'">
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
                    </div>
                </div>

                <!-- Upload mode picker -->
                <div v-if="form.channel_id">
                    <label class="block text-sm font-medium text-gray-700 mb-2">2. Choose upload mode</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <!-- Video -->
                        <label class="flex items-start p-4 border rounded-md cursor-pointer hover:bg-gray-50 transition"
                            :class="form.upload_mode === 'video' ? 'border-brand-400 bg-brand-50' : 'border-gray-200'">
                            <input type="radio" v-model="form.upload_mode" value="video"
                                class="mt-1 text-brand-600 focus:ring-brand-500" />
                            <div class="ml-3 flex-1">
                                <div class="flex items-center gap-2">
                                    <svg class="w-5 h-5 text-gray-700" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M2 4a2 2 0 012-2h12a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V4z"/>
                                        <path fill="#fff" d="M8 6l6 4-6 4V6z"/>
                                    </svg>
                                    <p class="text-sm font-medium text-gray-900">Regular Video</p>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">
                                    Horizontal (16:9) videos. Auto-applies People &amp; Blogs category. Best for vlogs, tutorials, long-form content.
                                </p>
                            </div>
                        </label>

                        <!-- Shorts -->
                        <label class="flex items-start p-4 border rounded-md cursor-pointer hover:bg-gray-50 transition"
                            :class="form.upload_mode === 'short' ? 'border-red-400 bg-red-50' : 'border-gray-200'">
                            <input type="radio" v-model="form.upload_mode" value="short"
                                class="mt-1 text-red-600 focus:ring-red-500" />
                            <div class="ml-3 flex-1">
                                <div class="flex items-center gap-2">
                                    <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                        <rect x="5" y="2" width="10" height="16" rx="2" fill="currentColor"/>
                                        <circle cx="10" cy="10" r="3" fill="#fff"/>
                                    </svg>
                                    <p class="text-sm font-medium text-gray-900">YouTube Shorts</p>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">
                                    Vertical (9:16) videos, max 60s. Auto-appends <code class="px-1 bg-red-100 rounded">#shorts</code> hashtag to description. Best for quick clips.
                                </p>
                            </div>
                        </label>
                    </div>
                </div>

                <div v-if="form.errors.channel_id" class="text-sm text-red-600">{{ form.errors.channel_id }}</div>
                <div v-if="form.errors.upload_mode" class="text-sm text-red-600">{{ form.errors.upload_mode }}</div>

                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <Link href="/social-accounts"
                        class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="form.processing || !form.channel_id || !form.upload_mode"
                        class="px-4 py-2 text-sm font-medium text-white rounded-md disabled:opacity-50 disabled:cursor-not-allowed"
                        :class="form.upload_mode === 'short' ? 'bg-red-600 hover:bg-red-700' : 'bg-brand-600 hover:bg-brand-700'">
                        {{ form.processing ? 'Connecting...' : `Connect as ${form.upload_mode === 'short' ? 'Shorts Channel' : 'Video Channel'}` }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
