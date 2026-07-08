<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    accounts: Array,
});

const providers = [
    { id: 'twitter', name: 'Twitter/X', color: 'bg-black', icon: '𝕏' },
    { id: 'facebook', name: 'Facebook Page', color: 'bg-blue-600', icon: 'f' },
    { id: 'linkedin', name: 'LinkedIn', color: 'bg-blue-700', icon: 'in' },
];

const findInstagram = (accounts) => accounts.find(a => a.provider === 'instagram');
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
                    No accounts connected yet. Connect one below to start scheduling posts.
                </div>
                <ul v-else class="divide-y divide-gray-100">
                    <li v-for="account in accounts" :key="account.id" class="flex items-center justify-between p-5">
                        <div class="flex items-center">
                            <div v-if="account.avatar" class="w-10 h-10 rounded-full overflow-hidden bg-gray-100">
                                <img :src="account.avatar" :alt="account.name" class="w-full h-full object-cover" />
                            </div>
                            <div v-else class="flex items-center justify-center w-10 h-10 rounded-full text-white text-sm font-bold"
                                :class="account.provider === 'twitter' ? 'bg-black' : account.provider === 'facebook' ? 'bg-blue-600' : account.provider === 'linkedin' ? 'bg-blue-700' : 'bg-pink-500'">
                                {{ account.provider.charAt(0).toUpperCase() }}
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
                            onclick="return confirm('Disconnect this account? Scheduled posts to this account will fail.')">
                            Disconnect
                        </Link>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Connect new account -->
        <div>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Connect a new account</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
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

            <!-- Instagram (requires Facebook Page first) -->
            <div class="mt-4 bg-white p-6 rounded-lg shadow">
                <div class="flex items-center mb-3">
                    <div class="flex items-center justify-center w-10 h-10 rounded-full text-white text-lg font-bold bg-pink-500">
                        ◎
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900">Instagram Business</p>
                        <p class="text-xs text-gray-500">Requires a connected Facebook Page</p>
                    </div>
                </div>
                <div v-if="!accounts.find(a => a.provider === 'facebook')">
                    <p class="text-xs text-gray-400">Connect a Facebook Page first, then come back here.</p>
                </div>
                <div v-else>
                    <form v-for="fbAccount in accounts.filter(a => a.provider === 'facebook')" :key="fbAccount.id"
                        method="POST" :action="`/social-accounts/connect-instagram`">
                        <input type="hidden" name="_token" :value="$page.props.csrf_token || ''" />
                        <input type="hidden" name="facebook_account_id" :value="fbAccount.id" />
                        <button type="submit"
                            class="w-full py-2 text-xs font-medium text-center text-white bg-pink-500 rounded-md hover:bg-pink-600">
                            Connect Instagram from "{{ fbAccount.name }}"
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Info box -->
        <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-md">
            <p class="text-sm text-blue-800">
                <strong>Setup required:</strong> You need to register API apps at each provider and fill in
                <code class="px-1 py-0.5 bg-blue-100 rounded">TWITTER_CLIENT_ID</code>,
                <code class="px-1 py-0.5 bg-blue-100 rounded">FACEBOOK_CLIENT_ID</code>,
                <code class="px-1 py-0.5 bg-blue-100 rounded">LINKEDIN_CLIENT_ID</code> in your
                <code class="px-1 py-0.5 bg-blue-100 rounded">.env</code> file.
                See the README for links to each provider's developer portal.
            </p>
        </div>
    </AppLayout>
</template>
