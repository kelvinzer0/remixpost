<script setup>
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const showingNavigationDropdown = ref(false);

const navigation = [
    { name: 'Dashboard', href: '/dashboard', icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
    { name: 'Posts', href: '/posts', icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z' },
    { name: 'Calendar', href: '/calendar', icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z' },
    { name: 'Analytics', href: '/analytics', icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z' },
    { name: 'Media', href: '/media', icon: 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z' },
    { name: 'Accounts', href: '/social-accounts', icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z' },
];
</script>

<template>
    <div class="min-h-screen bg-gray-100">
        <!-- Sidebar (desktop) -->
        <aside class="hidden md:flex md:flex-col md:fixed md:inset-y-0 md:w-64 bg-white border-r border-gray-200">
            <div class="flex items-center h-16 px-6 border-b border-gray-200">
                <Link href="/dashboard" class="text-xl font-bold text-brand-600">remixpost</Link>
            </div>
            <nav class="flex-1 px-3 py-4 space-y-1">
                <Link v-for="item in navigation" :key="item.name" :href="item.href"
                    class="flex items-center px-3 py-2 text-sm font-medium text-gray-700 rounded-md hover:bg-gray-100 hover:text-brand-600 transition"
                    :class="{ 'bg-brand-50 text-brand-700': $page.url.startsWith(item.href) }">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icon" />
                    </svg>
                    {{ item.name }}
                </Link>
            </nav>
            <div class="p-3 border-t border-gray-200">
                <Link href="/posts/create"
                    class="block w-full px-4 py-2 text-sm font-medium text-center text-white bg-brand-600 rounded-md hover:bg-brand-700">
                    + New Post
                </Link>
            </div>
        </aside>

        <!-- Mobile header -->
        <div class="md:hidden">
            <div class="flex items-center justify-between h-16 px-4 bg-white border-b border-gray-200">
                <Link href="/dashboard" class="text-xl font-bold text-brand-600">remixpost</Link>
                <button @click="showingNavigationDropdown = !showingNavigationDropdown"
                    class="p-2 text-gray-500 rounded-md hover:bg-gray-100">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            :d="showingNavigationDropdown ? 'M6 18L18 6M6 6l12 12' : 'M4 6h16M4 12h16M4 18h16'" />
                    </svg>
                </button>
            </div>
            <div v-if="showingNavigationDropdown" class="px-2 pt-2 pb-3 space-y-1 bg-white border-b border-gray-200">
                <Link v-for="item in navigation" :key="item.name" :href="item.href"
                    class="block px-3 py-2 text-sm font-medium text-gray-700 rounded-md hover:bg-gray-100"
                    @click="showingNavigationDropdown = false">
                    {{ item.name }}
                </Link>
            </div>
        </div>

        <!-- Main content -->
        <div class="md:pl-64">
            <header class="hidden md:flex items-center justify-between h-16 px-6 bg-white border-b border-gray-200">
                <div class="flex-1">
                    <h1 class="text-lg font-semibold text-gray-900">
                        <slot name="header" />
                    </h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-600">{{ $page.props.auth.user?.name }}</span>
                    <Link href="/posts/create"
                        class="px-3 py-1.5 text-sm font-medium text-white bg-brand-600 rounded-md hover:bg-brand-700">
                        + New Post
                    </Link>
                </div>
            </header>
            <main class="p-4 md:p-6">
                <!-- Flash messages -->
                <div v-if="$page.props.flash.message" class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
                    <p class="text-sm text-green-800">{{ $page.props.flash.message }}</p>
                </div>
                <div v-if="$page.props.flash.error" class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
                    <p class="text-sm text-red-800">{{ $page.props.flash.error }}</p>
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
