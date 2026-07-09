<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    posts: Object,
    filters: Object,
});

const formatDate = (date) => {
    if (!date) return '—';
    return new Date(date).toLocaleString('id-ID', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta'
    });
};

const formatDateShort = (date) => {
    if (!date) return '—';
    return new Date(date).toLocaleString('id-ID', {
        day: '2-digit', month: 'short',
        hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta'
    });
};

const statusColors = {
    draft: 'bg-gray-100 text-gray-700',
    scheduled: 'bg-yellow-100 text-yellow-700',
    publishing: 'bg-blue-100 text-blue-700',
    published: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
    canceled: 'bg-gray-100 text-gray-700',
};

// Provider color for avatar circles
const providerColors = {
    twitter: 'bg-black', facebook: 'bg-blue-600', linkedin: 'bg-blue-700',
    youtube: 'bg-red-600', tiktok: 'bg-black', pinterest: 'bg-red-700',
    instagram: 'bg-pink-500', telegram: 'bg-blue-500', email: 'bg-gray-600',
    mastodon: 'bg-purple-600', discord: 'bg-indigo-600', buffer: 'bg-blue-900',
};

const getProviderColor = (provider) => providerColors[provider] || 'bg-gray-500';
</script>

<template>
    <AppLayout>
        <template #header>Posts</template>
        <Head title="Posts" />

        <div class="flex items-center justify-between mb-4 md:mb-6 gap-2">
            <!-- Filter tabs — scrollable on mobile -->
            <div class="flex space-x-1 md:space-x-2 overflow-x-auto flex-1">
                <Link href="/posts"
                    class="px-2 md:px-3 py-1.5 text-xs md:text-sm rounded-md whitespace-nowrap"
                    :class="!filters.status ? 'bg-brand-600 text-white' : 'bg-white text-gray-700 border border-gray-300'">
                    All
                </Link>
                <Link v-for="status in ['scheduled', 'published', 'failed']" :key="status"
                    :href="`/posts?status=${status}`"
                    class="px-2 md:px-3 py-1.5 text-xs md:text-sm rounded-md capitalize whitespace-nowrap"
                    :class="filters.status === status ? 'bg-brand-600 text-white' : 'bg-white text-gray-700 border border-gray-300'">
                    {{ status }}
                </Link>
            </div>
            <Link href="/posts/create"
                class="px-3 md:px-4 py-2 text-xs md:text-sm font-medium text-white bg-brand-600 rounded-md hover:bg-brand-700 whitespace-nowrap flex-shrink-0">
                + New Post
            </Link>
        </div>

        <!-- DESKTOP: table (md+) -->
        <div class="hidden md:block bg-white rounded-lg shadow overflow-hidden">
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
                                    class="inline-flex items-center justify-center w-6 h-6 rounded-full text-white text-xs border-2 border-white"
                                    :class="getProviderColor(account.provider)"
                                    :title="account.name + ' (' + account.provider + ')'">
                                    {{ account.provider.charAt(0).toUpperCase() }}
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            {{ post.scheduled_at ? formatDate(post.scheduled_at) : '—' }}
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 text-xs font-medium rounded-full capitalize"
                                :class="statusColors[post.status]">
                                {{ post.status }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right text-sm space-x-3">
                            <Link :href="`/posts/${post.id}`"
                                class="text-brand-600 hover:text-brand-900">View</Link>
                            <Link v-if="['draft', 'scheduled', 'failed'].includes(post.status)"
                                :href="`/posts/${post.id}/edit`"
                                class="text-indigo-600 hover:text-indigo-900">Edit</Link>
                            <Link :href="`/posts/${post.id}/duplicate`" method="post" as="button"
                                class="text-green-600 hover:text-green-900">Duplicate</Link>
                            <Link :href="`/posts/${post.id}`" method="delete" as="button"
                                class="text-red-600 hover:text-red-900"
                                onclick="return confirm('Delete this post?')">Delete</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- MOBILE: card layout (below md) -->
        <div class="md:hidden space-y-3">
            <div v-if="posts.data.length === 0" class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
                No posts found.
            </div>
            <div v-for="post in posts.data" :key="post.id"
                class="bg-white rounded-lg shadow p-3 border border-gray-100">
                <!-- Top row: status + date -->
                <div class="flex items-center justify-between mb-2">
                    <span class="px-2 py-0.5 text-[10px] font-medium rounded-full capitalize"
                        :class="statusColors[post.status]">
                        {{ post.status }}
                    </span>
                    <span class="text-xs text-gray-500">
                        📅 {{ post.scheduled_at ? formatDateShort(post.scheduled_at) : '—' }}
                    </span>
                </div>

                <!-- Content preview -->
                <Link :href="`/posts/${post.id}`"
                    class="block text-sm text-gray-900 mb-2 line-clamp-2 leading-snug">
                    {{ post.content }}
                </Link>

                <!-- Bottom row: accounts + actions -->
                <div class="flex items-center justify-between">
                    <!-- Account avatars -->
                    <div class="flex -space-x-1.5">
                        <span v-for="account in post.social_accounts.slice(0, 5)" :key="account.id"
                            class="inline-flex items-center justify-center w-5 h-5 rounded-full text-white text-[9px] border border-white"
                            :class="getProviderColor(account.provider)"
                            :title="account.name + ' (' + account.provider + ')'">
                            {{ account.provider.charAt(0).toUpperCase() }}
                        </span>
                        <span v-if="post.social_accounts.length > 5"
                            class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-gray-200 text-gray-600 text-[9px] border border-white">
                            +{{ post.social_accounts.length - 5 }}
                        </span>
                    </div>

                    <!-- Quick actions -->
                    <div class="flex items-center gap-3 text-xs">
                        <Link :href="`/posts/${post.id}`"
                            class="text-brand-600 font-medium">View</Link>
                        <Link v-if="['draft', 'scheduled', 'failed'].includes(post.status)"
                            :href="`/posts/${post.id}/edit`"
                            class="text-indigo-600 font-medium">Edit</Link>
                        <Link :href="`/posts/${post.id}/duplicate`" method="post" as="button"
                            class="text-green-600 font-medium">Clone</Link>
                        <Link :href="`/posts/${post.id}`" method="delete" as="button"
                            class="text-red-600 font-medium"
                            onclick="return confirm('Delete this post?')">Del</Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="posts.last_page > 1" class="mt-4 flex justify-center">
            <nav class="flex space-x-1 flex-wrap justify-center">
                <Link v-for="link in posts.links" :key="link.label" :href="link.url || '#'"
                    class="px-2 md:px-3 py-2 text-xs md:text-sm rounded-md"
                    :class="link.active ? 'bg-brand-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                    v-html="link.label"></Link>
            </nav>
        </div>
    </AppLayout>
</template>
