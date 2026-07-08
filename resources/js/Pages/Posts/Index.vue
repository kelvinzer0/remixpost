<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    posts: Object,
    filters: Object,
});

const statusColors = {
    draft: 'bg-gray-100 text-gray-700',
    scheduled: 'bg-yellow-100 text-yellow-700',
    publishing: 'bg-blue-100 text-blue-700',
    published: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
    canceled: 'bg-gray-100 text-gray-700',
};
</script>

<template>
    <AppLayout>
        <template #header>Posts</template>
        <Head title="Posts" />

        <div class="flex items-center justify-between mb-6">
            <div class="flex space-x-2">
                <Link href="/posts"
                    class="px-3 py-1.5 text-sm rounded-md"
                    :class="!filters.status ? 'bg-brand-600 text-white' : 'bg-white text-gray-700 border border-gray-300'">
                    All
                </Link>
                <Link v-for="status in ['scheduled', 'published', 'failed']" :key="status"
                    :href="`/posts?status=${status}`"
                    class="px-3 py-1.5 text-sm rounded-md capitalize"
                    :class="filters.status === status ? 'bg-brand-600 text-white' : 'bg-white text-gray-700 border border-gray-300'">
                    {{ status }}
                </Link>
            </div>
            <Link href="/posts/create"
                class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-md hover:bg-brand-700">
                + New Post
            </Link>
        </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Content</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Accounts</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Scheduled</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-if="posts.data.length === 0">
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">No posts found.</td>
                    </tr>
                    <tr v-for="post in posts.data" :key="post.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm text-gray-900 max-w-md truncate">
                            {{ post.content }}
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            <div class="flex -space-x-2">
                                <span v-for="account in post.social_accounts" :key="account.id"
                                    class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-brand-500 text-white text-xs border-2 border-white">
                                    {{ account.provider.charAt(0).toUpperCase() }}
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            {{ post.scheduled_at ? new Date(post.scheduled_at).toLocaleString() : '—' }}
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 text-xs font-medium rounded-full capitalize"
                                :class="statusColors[post.status]">
                                {{ post.status }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right text-sm">
                            <Link :href="`/posts/${post.id}`"
                                class="text-brand-600 hover:text-brand-900">View</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="posts.last_page > 1" class="mt-4 flex justify-center">
            <nav class="flex space-x-1">
                <Link v-for="link in posts.links" :key="link.label" :href="link.url || '#'"
                    class="px-3 py-2 text-sm rounded-md"
                    :class="link.active ? 'bg-brand-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                    v-html="link.label"></Link>
            </nav>
        </div>
    </AppLayout>
</template>
