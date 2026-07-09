<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    accountSummaries: Array,
    posts: Array,
    totals: Object,
});

const formatNumber = (n) => {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
    return n.toString();
};

const providerColors = {
    twitter: 'bg-black', facebook: 'bg-blue-600', linkedin: 'bg-blue-700',
    youtube: 'bg-red-600', tiktok: 'bg-black', pinterest: 'bg-red-700',
    instagram: 'bg-pink-500', telegram: 'bg-blue-500', email: 'bg-gray-600',
    mastodon: 'bg-purple-600', discord: 'bg-indigo-600', buffer: 'bg-blue-900',
};

const getProviderColor = (provider) => providerColors[provider] || 'bg-gray-500';
const hasMetrics = (post) => post.metrics && post.metrics.length > 0;
</script>

<template>
    <AppLayout>
        <template #header>Analytics</template>
        <Head title="Analytics" />

        <!-- Overall Summary -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-900">{{ totals.posts }}</p>
                <p class="text-xs text-gray-500 mt-1">Posts Published</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ formatNumber(totals.views) }}</p>
                <p class="text-xs text-gray-500 mt-1">Views</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ formatNumber(totals.likes) }}</p>
                <p class="text-xs text-gray-500 mt-1">Likes</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-purple-600">{{ formatNumber(totals.comments) }}</p>
                <p class="text-xs text-gray-500 mt-1">Comments</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-orange-600">{{ formatNumber(totals.shares) }}</p>
                <p class="text-xs text-gray-500 mt-1">Shares</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-indigo-600">{{ formatNumber(totals.engagement) }}</p>
                <p class="text-xs text-gray-500 mt-1">Total Engagement</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-red-600">{{ formatNumber(totals.impressions) }}</p>
                <p class="text-xs text-gray-500 mt-1">Impressions</p>
            </div>
        </div>

        <!-- Refresh + header -->
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-900">Per-Account Summary</h2>
            <Link href="/analytics/refresh" method="post" as="button"
                class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-md hover:bg-brand-700">
                🔄 Refresh Metrics
            </Link>
        </div>

        <!-- Per-Account Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
            <div v-for="summary in accountSummaries" :key="summary.account.id"
                class="bg-white rounded-lg shadow p-5">
                <div class="flex items-center mb-4">
                    <img v-if="summary.account.avatar" :src="summary.account.avatar" class="w-10 h-10 rounded-full" />
                    <div v-else class="flex items-center justify-center w-10 h-10 rounded-full text-white text-sm font-bold"
                        :class="getProviderColor(summary.account.provider)">
                        {{ summary.account.provider.charAt(0).toUpperCase() }}
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900 truncate max-w-[180px]">{{ summary.account.name }}</p>
                        <p class="text-xs text-gray-500 capitalize">{{ summary.account.provider }}</p>
                    </div>
                </div>
                <div v-if="summary.posts_count > 0" class="grid grid-cols-3 gap-2 text-center">
                    <div><p class="text-lg font-bold text-gray-900">{{ summary.posts_count }}</p><p class="text-[10px] text-gray-500">Posts</p></div>
                    <div><p class="text-lg font-bold text-blue-600">{{ formatNumber(summary.total_views) }}</p><p class="text-[10px] text-gray-500">Views</p></div>
                    <div><p class="text-lg font-bold text-green-600">{{ formatNumber(summary.total_likes) }}</p><p class="text-[10px] text-gray-500">Likes</p></div>
                    <div><p class="text-lg font-bold text-purple-600">{{ formatNumber(summary.total_comments) }}</p><p class="text-[10px] text-gray-500">Comments</p></div>
                    <div><p class="text-lg font-bold text-orange-600">{{ formatNumber(summary.total_shares) }}</p><p class="text-[10px] text-gray-500">Shares</p></div>
                    <div><p class="text-lg font-bold text-indigo-600">{{ summary.avg_engagement_rate }}%</p><p class="text-[10px] text-gray-500">Eng. Rate</p></div>
                </div>
                <div v-else class="text-center py-4">
                    <p class="text-xs text-gray-400">No metrics yet. Click Refresh.</p>
                </div>
                <p v-if="summary.last_fetched" class="mt-3 text-[10px] text-gray-400 text-center">
                    Last updated: {{ summary.last_fetched }}
                </p>
            </div>
        </div>

        <!-- Per-Post Breakdown -->
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Per-Post Breakdown</h2>
        <div class="bg-white rounded-lg shadow overflow-hidden overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Post</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Published</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Views</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Likes</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Comments</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Shares</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Eng. Rate</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Updated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-if="posts.length === 0">
                        <td colspan="9" class="px-4 py-8 text-center text-gray-500">No published posts yet.</td>
                    </tr>
                    <template v-for="post in posts" :key="post.id">
                        <tr v-if="hasMetrics(post)" v-for="metric in post.metrics" :key="metric.account_id" class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-900 max-w-[200px] truncate">
                                <Link :href="`/posts/${post.id}`" class="hover:underline">{{ post.content }}</Link>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ post.published_at }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1.5">
                                    <div class="flex items-center justify-center w-5 h-5 rounded-full text-white text-[8px] font-bold"
                                        :class="getProviderColor(metric.account_provider)">
                                        {{ metric.account_provider.charAt(0).toUpperCase() }}
                                    </div>
                                    <span class="text-xs text-gray-600 truncate max-w-[100px]">{{ metric.account_name }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center text-sm text-gray-700">{{ formatNumber(metric.views) }}</td>
                            <td class="px-4 py-3 text-center text-sm text-green-600 font-medium">{{ formatNumber(metric.likes) }}</td>
                            <td class="px-4 py-3 text-center text-sm text-purple-600">{{ formatNumber(metric.comments) }}</td>
                            <td class="px-4 py-3 text-center text-sm text-orange-600">{{ formatNumber(metric.shares) }}</td>
                            <td class="px-4 py-3 text-center text-sm">
                                <span class="px-1.5 py-0.5 text-xs font-medium rounded-full"
                                    :class="metric.engagement_rate > 5 ? 'bg-green-100 text-green-800' : metric.engagement_rate > 1 ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-600'">
                                    {{ metric.engagement_rate }}%
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center text-xs text-gray-400">{{ metric.fetched_at || '—' }}</td>
                        </tr>
                        <tr v-else>
                            <td class="px-4 py-3 text-sm text-gray-900 max-w-[200px] truncate">
                                <Link :href="`/posts/${post.id}`" class="hover:underline">{{ post.content }}</Link>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ post.published_at }}</td>
                            <td class="px-4 py-3 text-xs text-gray-400" colspan="6">No metrics — click Refresh</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Info -->
        <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-md">
            <p class="text-sm text-blue-800">
                <strong>📊 Analytics</strong> — Metrics di-fetch dari API masing-masing platform
                (LinkedIn, Facebook, YouTube, Buffer). Telegram, Discord, Email, Mastodon tidak
                punya analytics API per-post. Klik <strong>Refresh Metrics</strong> untuk update.
            </p>
        </div>
    </AppLayout>
</template>
