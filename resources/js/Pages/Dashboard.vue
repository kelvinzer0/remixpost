<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    stats: Object,
    upcomingPosts: Array,
    accounts: Array,
});

const providerColors = {
    twitter: 'bg-blue-500',
    facebook: 'bg-blue-600',
    linkedin: 'bg-blue-700',
    instagram: 'bg-pink-500',
};
</script>

<template>
    <AppLayout>
        <template #header>Dashboard</template>

        <!-- Stats -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
            <div class="p-5 bg-white rounded-lg shadow">
                <p class="text-sm font-medium text-gray-500">Total Posts</p>
                <p class="mt-1 text-3xl font-bold text-gray-900">{{ stats.total_posts }}</p>
            </div>
            <div class="p-5 bg-white rounded-lg shadow">
                <p class="text-sm font-medium text-gray-500">Scheduled</p>
                <p class="mt-1 text-3xl font-bold text-yellow-600">{{ stats.scheduled_posts }}</p>
            </div>
            <div class="p-5 bg-white rounded-lg shadow">
                <p class="text-sm font-medium text-gray-500">Published</p>
                <p class="mt-1 text-3xl font-bold text-green-600">{{ stats.published_posts }}</p>
            </div>
            <div class="p-5 bg-white rounded-lg shadow">
                <p class="text-sm font-medium text-gray-500">Connected Accounts</p>
                <p class="mt-1 text-3xl font-bold text-brand-600">{{ stats.connected_accounts }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Upcoming posts -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-5 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Upcoming Posts</h2>
                </div>
                <div class="divide-y divide-gray-100">
                    <div v-if="upcomingPosts.length === 0" class="p-5 text-center text-gray-500">
                        No scheduled posts.
                    </div>
                    <div v-for="post in upcomingPosts" :key="post.id" class="p-5">
                        <p class="text-sm text-gray-900 line-clamp-2">{{ post.content }}</p>
                        <p class="mt-2 text-xs text-gray-500">
                            {{ new Date(post.scheduled_at).toLocaleString() }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Connected accounts -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-5 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Connected Accounts</h2>
                </div>
                <div class="divide-y divide-gray-100">
                    <div v-if="accounts.length === 0" class="p-5 text-center text-gray-500">
                        No accounts connected yet.
                    </div>
                    <div v-for="account in accounts" :key="account.id" class="flex items-center p-5">
                        <img v-if="account.avatar" :src="account.avatar" :alt="account.name"
                            class="w-10 h-10 rounded-full" />
                        <div v-else class="flex items-center justify-center w-10 h-10 rounded-full text-white"
                            :class="providerColors[account.provider] || 'bg-gray-500'">
                            {{ account.provider.charAt(0).toUpperCase() }}
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">{{ account.name }}</p>
                            <p class="text-xs text-gray-500 capitalize">{{ account.provider }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
