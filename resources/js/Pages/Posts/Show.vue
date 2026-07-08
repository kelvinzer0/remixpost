<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    post: Object,
});

const statusColors = {
    draft: 'bg-gray-100 text-gray-700',
    scheduled: 'bg-yellow-100 text-yellow-700',
    publishing: 'bg-blue-100 text-blue-700',
    published: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
    canceled: 'bg-gray-100 text-gray-700',
};

const formatDate = (date) => {
    if (!date) return '—';
    return new Date(date).toLocaleString('id-ID', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta'
    });
};
</script>

<template>
    <AppLayout>
        <template #header>Post Details</template>
        <Head title="Post Details" />

        <div class="max-w-3xl mx-auto space-y-6">
            <!-- Post info -->
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-900">Content</h2>
                    <span class="px-2 py-1 text-xs font-medium rounded-full capitalize"
                        :class="statusColors[post.status]">
                        {{ post.status }}
                    </span>
                </div>
                <p class="text-sm text-gray-900 whitespace-pre-wrap">{{ post.content }}</p>

                <!-- Media -->
                <div v-if="post.media_urls && post.media_urls.length > 0" class="mt-4">
                    <p class="text-xs font-medium text-gray-500 mb-2">Media ({{ post.media_urls.length }})</p>
                    <div class="grid grid-cols-2 gap-2">
                        <a v-for="(url, i) in post.media_urls" :key="i" :href="url" target="_blank"
                            class="block p-2 bg-gray-50 border border-gray-200 rounded text-xs text-blue-600 hover:bg-blue-50 truncate">
                            {{ url }}
                        </a>
                    </div>
                </div>
            </div>

            <!-- Schedule info -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Schedule</h2>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">Scheduled at</dt>
                        <dd class="text-gray-900">{{ formatDate(post.scheduled_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Published at</dt>
                        <dd class="text-gray-900">{{ formatDate(post.published_at) }}</dd>
                    </div>
                </dl>
                <p v-if="post.failure_reason" class="mt-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-800">
                    <strong>Failure reason:</strong> {{ post.failure_reason }}
                </p>
            </div>

            <!-- Accounts -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Posted to</h2>
                <ul class="divide-y divide-gray-100">
                    <li v-for="account in post.social_accounts" :key="account.id" class="flex items-center justify-between py-3">
                        <div class="flex items-center">
                            <div class="flex items-center justify-center w-8 h-8 rounded-full text-white text-xs font-bold"
                                :class="{
                                    'bg-black': account.provider === 'twitter' || account.provider === 'tiktok',
                                    'bg-blue-600': account.provider === 'facebook',
                                    'bg-blue-700': account.provider === 'linkedin',
                                    'bg-red-600': account.provider === 'youtube',
                                    'bg-red-700': account.provider === 'pinterest',
                                    'bg-purple-600': account.provider === 'mastodon',
                                    'bg-blue-500': account.provider === 'telegram',
                                    'bg-gray-600': account.provider === 'email',
                                    'bg-pink-500': account.provider === 'instagram',
                                }">
                                {{ account.provider.charAt(0).toUpperCase() }}
                            </div>
                            <span class="ml-3 text-sm text-gray-900">{{ account.name || account.username }}</span>
                            <span class="ml-2 text-xs text-gray-500 capitalize">{{ account.provider }}</span>
                        </div>
                        <div class="text-right">
                            <span v-if="account.pivot.published_at" class="text-xs text-green-600">
                                ✓ Published
                            </span>
                            <span v-else-if="account.pivot.failure_reason" class="text-xs text-red-600">
                                ✗ Failed
                            </span>
                            <span v-else class="text-xs text-gray-400">Pending</span>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-between">
                <Link href="/posts"
                    class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    ← Back to Posts
                </Link>
                <div v-if="['draft', 'scheduled', 'failed'].includes(post.status)" class="flex space-x-2">
                    <Link :href="`/posts/${post.id}/edit`"
                        class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-md hover:bg-brand-700">
                        Edit
                    </Link>
                    <Link :href="`/posts/${post.id}`" method="delete" as="button"
                        class="px-4 py-2 text-sm font-medium text-red-600 bg-white border border-red-300 rounded-md hover:bg-red-50"
                        onclick="return confirm('Delete this post?')">
                        Delete
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
