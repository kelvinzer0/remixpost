<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    accounts: Array,
});

const providers = [
    { id: 'twitter', name: 'Twitter/X', color: 'bg-black', icon: '𝕏' },
    { id: 'facebook', name: 'Facebook', color: 'bg-blue-600', icon: 'f' },
    { id: 'linkedin', name: 'LinkedIn', color: 'bg-blue-700', icon: 'in' },
    { id: 'instagram', name: 'Instagram', color: 'bg-pink-500', icon: '◎' },
];
</script>

<template>
    <AppLayout>
        <template #header>Social Accounts</template>
        <Head title="Social Accounts" />

        <!-- Connected accounts -->
        <div class="mb-8">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Connected Accounts</h2>
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div v-if="accounts.length === 0" class="p-8 text-center text-gray-500">
                    No accounts connected yet. Connect one below.
                </div>
                <ul v-else class="divide-y divide-gray-100">
                    <li v-for="account in accounts" :key="account.id" class="flex items-center justify-between p-5">
                        <div class="flex items-center">
                            <div class="flex items-center justify-center w-10 h-10 rounded-full text-white"
                                :class="providers.find(p => p.id === account.provider)?.color || 'bg-gray-500'">
                                {{ providers.find(p => p.id === account.provider)?.icon || '?' }}
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-900">{{ account.name || account.username }}</p>
                                <p class="text-xs text-gray-500 capitalize">{{ account.provider }}</p>
                            </div>
                            <span v-if="account.is_active"
                                class="ml-3 px-2 py-0.5 text-xs font-medium text-green-700 bg-green-100 rounded-full">
                                Active
                            </span>
                        </div>
                        <Link :href="`/social-accounts/${account.id}`" method="delete" as="button"
                            class="px-3 py-1.5 text-sm text-red-600 hover:text-red-700"
                            onclick="return confirm('Disconnect this account?')">
                            Disconnect
                        </Link>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Connect new account -->
        <div>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Connect a new account</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div v-for="provider in providers" :key="provider.id"
                    class="bg-white p-6 rounded-lg shadow text-center">
                    <div class="flex items-center justify-center w-12 h-12 mx-auto rounded-full text-white text-xl font-bold"
                        :class="provider.color">
                        {{ provider.icon }}
                    </div>
                    <p class="mt-3 text-sm font-medium text-gray-900">{{ provider.name }}</p>
                    <Link :href="`/social-accounts/connect/${provider.id}`"
                        class="mt-3 block w-full py-2 text-xs font-medium text-center text-white bg-brand-600 rounded-md hover:bg-brand-700">
                        Connect
                    </Link>
                </div>
            </div>
            <p class="mt-4 text-sm text-gray-500">
                Note: OAuth integration will be wired in the next iteration. Clicking "Connect" will be a no-op for now.
            </p>
        </div>
    </AppLayout>
</template>
