<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    accounts: Array,
});

const providers = [
    { id: 'twitter', name: 'Twitter/X', color: 'bg-black', icon: '𝕏', oauth: true },
    { id: 'facebook', name: 'Facebook Page', color: 'bg-blue-600', icon: 'f', oauth: true },
    { id: 'linkedin', name: 'LinkedIn', color: 'bg-blue-700', icon: 'in', oauth: true },
    { id: 'youtube', name: 'YouTube', color: 'bg-red-600', icon: '▶', oauth: true, note: 'Video only' },
    { id: 'tiktok', name: 'TikTok', color: 'bg-black', icon: '♪', oauth: true, note: 'Video only' },
    { id: 'pinterest', name: 'Pinterest', color: 'bg-red-700', icon: 'P', oauth: true, note: 'Image/Video' },
    { id: 'telegram', name: 'Telegram Channel', color: 'bg-blue-500', icon: '✈', oauth: false },
    { id: 'email', name: 'Email Newsletter', color: 'bg-gray-600', icon: '✉', oauth: false },
    { id: 'discord', name: 'Discord Channel', color: 'bg-indigo-600', icon: '🎮', oauth: false },
    { id: 'buffer', name: 'Buffer (Aggregator)', color: 'bg-blue-900', icon: 'B', oauth: false },
];

// Telegram manual connect form
const telegramForm = useForm({
    channel_username: '', // e.g. @mychannel or -1001234567890
});

const connectTelegram = () => {
    telegramForm.post('/integrations/social/connect-telegram', {
        onSuccess: () => telegramForm.reset(),
    });
};

// Email manual connect form
const emailForm = useForm({
    recipient_email: '',
    name: '',
});

const connectEmail = () => {
    emailForm.post('/integrations/social/connect-email', {
        onSuccess: () => emailForm.reset(),
    });
};

// Mastodon connect form — user provides instance URL, app auto-registers
const mastodonForm = useForm({
    instance_url: 'https://mastodon.social',
});

const connectMastodon = () => {
    mastodonForm.post('/integrations/social/connect-mastodon', {
        onSuccess: () => mastodonForm.reset(),
    });
};

// Discord connect form — user provides webhook URL
const discordForm = useForm({
    webhook_url: '',
    display_name: '',
});

const connectDiscord = () => {
    discordForm.post('/integrations/social/connect-discord', {
        onSuccess: () => discordForm.reset(),
    });
};

// Buffer connect — triggers OAuth+PKCE flow, no form fields needed
const bufferForm = useForm({});

const connectBuffer = () => {
    bufferForm.post('/integrations/social/connect-buffer', {
        onSuccess: () => bufferForm.reset(),
    });
};

const oauthProviders = providers.filter(p => p.oauth);
const manualProviders = providers.filter(p => !p.oauth);
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
                                    'bg-indigo-600': account.provider === 'discord',
                                    'bg-blue-900': account.provider === 'buffer',
                                }">
                                {{ account.provider.charAt(0).toUpperCase() }}
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-900">{{ account.name || account.username }}</p>
                                <p class="text-xs text-gray-500 capitalize">{{ account.provider }}</p>
                            </div>
                            <!-- Upload mode badge for YouTube -->
                            <span v-if="account.provider === 'youtube' && account.metadata?.upload_mode === 'short'"
                                class="ml-3 px-2 py-0.5 text-xs font-medium text-red-700 bg-red-100 rounded-full">
                                Shorts
                            </span>
                            <span v-else-if="account.provider === 'youtube' && account.metadata?.upload_mode === 'video'"
                                class="ml-3 px-2 py-0.5 text-xs font-medium text-blue-700 bg-blue-100 rounded-full">
                                Video
                            </span>
                            <!-- Reconnect required badge for YouTube without refresh_token -->
                            <span v-if="account.provider === 'youtube' && !account.has_refresh_token"
                                class="ml-3 px-2 py-0.5 text-xs font-medium text-orange-700 bg-orange-100 rounded-full border border-orange-200">
                                ⚠ Reconnect required
                            </span>
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

        <!-- OAuth providers (Twitter, FB, LinkedIn, YouTube, TikTok, Pinterest, Mastodon) -->
        <div class="mb-8">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Connect via OAuth</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div v-for="provider in oauthProviders" :key="provider.id"
                    class="bg-white p-6 rounded-lg shadow text-center">
                    <div class="flex items-center justify-center w-12 h-12 mx-auto rounded-full text-white text-xl font-bold"
                        :class="provider.color">
                        {{ provider.icon }}
                    </div>
                    <p class="mt-3 text-sm font-medium text-gray-900">{{ provider.name }}</p>
                    <p v-if="provider.note" class="text-xs text-gray-400">{{ provider.note }}</p>
                    <a :href="`/social-accounts/connect/${provider.id}`"
                        class="mt-3 block w-full py-2 text-xs font-medium text-center text-white bg-brand-600 rounded-md hover:bg-brand-700">
                        Connect
                    </a>
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
                    <Link v-for="fbAccount in accounts.filter(a => a.provider === 'facebook')" :key="fbAccount.id"
                        :href="`/integrations/social/connect-instagram`"
                        method="post"
                        :data="{ facebook_account_id: fbAccount.id }"
                        as="button"
                        class="w-full py-2 text-xs font-medium text-center text-white bg-pink-500 rounded-md hover:bg-pink-600">
                        Connect Instagram from "{{ fbAccount.name }}"
                    </Link>
                </div>
            </div>
        </div>

        <!-- Manual providers (Telegram, Email) -->
        <div>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Connect manually</h2>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Telegram -->
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex items-center mb-4">
                        <div class="flex items-center justify-center w-10 h-10 rounded-full text-white text-lg font-bold bg-blue-500">
                            ✈
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Telegram Channel</p>
                            <p class="text-xs text-gray-500">Bot must be admin of the channel</p>
                        </div>
                    </div>
                    <div class="mb-3 p-2 bg-blue-50 border border-blue-200 rounded text-xs text-blue-800">
                        <p class="font-medium mb-1">Setup steps:</p>
                        <ol class="list-decimal ml-4 space-y-0.5">
                            <li>Add the bot as <strong>administrator</strong> to your channel</li>
                            <li>Give it <strong>Post Messages</strong> + <strong>Delete Messages</strong> permissions</li>
                            <li>Enter the channel @username below</li>
                            <li>You'll receive a verification code to confirm via private chat with the bot</li>
                        </ol>
                    </div>
                    <form @submit.prevent="connectTelegram" class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Channel username (public channels) or chat ID (private)</label>
                            <input v-model="telegramForm.channel_username" type="text" required
                                placeholder="@warunglakku or -1001234567890"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm" />
                            <p v-if="telegramForm.errors.channel_username" class="mt-1 text-xs text-red-600">{{ telegramForm.errors.channel_username }}</p>
                        </div>
                        <button type="submit" :disabled="telegramForm.processing"
                            class="w-full py-2 text-xs font-medium text-center text-white bg-blue-500 rounded-md hover:bg-blue-600 disabled:opacity-50">
                            {{ telegramForm.processing ? 'Verifying bot access...' : 'Start Verification' }}
                        </button>
                    </form>
                </div>

                <!-- Email -->
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex items-center mb-4">
                        <div class="flex items-center justify-center w-10 h-10 rounded-full text-white text-lg font-bold bg-gray-600">
                            ✉
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Email Newsletter</p>
                            <p class="text-xs text-gray-500">Send posts as HTML email</p>
                        </div>
                    </div>
                    <form @submit.prevent="connectEmail" class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Recipient email</label>
                            <input v-model="emailForm.recipient_email" type="email" required
                                placeholder="newsletter@yourdomain.com"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm" />
                            <p v-if="emailForm.errors.recipient_email" class="mt-1 text-xs text-red-600">{{ emailForm.errors.recipient_email }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Label (optional)</label>
                            <input v-model="emailForm.name" type="text"
                                placeholder="My Newsletter List"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm" />
                        </div>
                        <button type="submit" :disabled="emailForm.processing"
                            class="w-full py-2 text-xs font-medium text-center text-white bg-gray-600 rounded-md hover:bg-gray-700 disabled:opacity-50">
                            Connect Email Recipient
                        </button>
                    </form>
                </div>

                <!-- Discord -->
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex items-center mb-4">
                        <div class="flex items-center justify-center w-10 h-10 rounded-full text-white text-lg font-bold bg-indigo-600">
                            🎮
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Discord Channel</p>
                            <p class="text-xs text-gray-500">Posts via Webhook (no bot setup)</p>
                        </div>
                    </div>
                    <div class="mb-3 p-2 bg-indigo-50 border border-indigo-200 rounded text-xs text-indigo-800">
                        <p class="font-medium mb-1">Setup steps:</p>
                        <ol class="list-decimal ml-4 space-y-0.5">
                            <li>In Discord: Channel Settings → <strong>Integrations</strong> → <strong>Webhooks</strong></li>
                            <li>Click <strong>New Webhook</strong>, customize name + avatar</li>
                            <li>Click <strong>Copy Webhook URL</strong></li>
                            <li>Paste URL below</li>
                        </ol>
                    </div>
                    <form @submit.prevent="connectDiscord" class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Webhook URL</label>
                            <input v-model="discordForm.webhook_url" type="url" required
                                placeholder="https://discord.com/api/webhooks/..."
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm font-mono" />
                            <p v-if="discordForm.errors.webhook_url" class="mt-1 text-xs text-red-600">{{ discordForm.errors.webhook_url }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Display name (optional)</label>
                            <input v-model="discordForm.display_name" type="text"
                                placeholder="My Discord Server #general"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm" />
                        </div>
                        <button type="submit" :disabled="discordForm.processing"
                            class="w-full py-2 text-xs font-medium text-center text-white bg-indigo-600 rounded-md hover:bg-indigo-700 disabled:opacity-50">
                            {{ discordForm.processing ? 'Validating webhook...' : 'Connect Discord Webhook' }}
                        </button>
                    </form>
                </div>

                <!-- Buffer -->
                <div class="bg-white p-6 rounded-lg shadow">
                    <div class="flex items-center mb-4">
                        <div class="flex items-center justify-center w-10 h-10 rounded-full text-white text-lg font-bold bg-blue-900">
                            B
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Buffer (Aggregator)</p>
                            <p class="text-xs text-gray-500">FB · IG · X · Pinterest · LinkedIn · TikTok · more</p>
                        </div>
                    </div>
                    <div class="mb-3 p-2 bg-blue-50 border border-blue-200 rounded text-xs text-blue-800">
                        <p class="font-medium mb-1">Why Buffer?</p>
                        <p class="mb-1">Bypass strict app review for Meta (FB/IG), Pinterest, X, and TikTok. Buffer already has approved apps — connect once, publish to all.</p>
                        <p class="font-medium mt-2 mb-1">Setup:</p>
                        <ol class="list-decimal ml-4 space-y-0.5">
                            <li>Register client at <a href="https://buffer.com/clients/manage" target="_blank" class="underline">buffer.com/clients</a> (Public client + PKCE)</li>
                            <li>Set <code class="px-0.5 bg-blue-100 rounded">BUFFER_CLIENT_ID</code> in .env</li>
                            <li>Click button below → pick channels</li>
                        </ol>
                    </div>
                    <form @submit.prevent="connectBuffer" class="space-y-3">
                        <button type="submit" :disabled="bufferForm.processing"
                            class="w-full py-2 text-xs font-medium text-center text-white bg-blue-900 rounded-md hover:bg-blue-800 disabled:opacity-50">
                            {{ bufferForm.processing ? 'Connecting...' : 'Connect via Buffer' }}
                        </button>
                    </form>
                </div>
            </div>

            <!-- Mastodon (full-width) -->
            <div class="mt-4 bg-white p-6 rounded-lg shadow">
                <div class="flex items-center mb-4">
                    <div class="flex items-center justify-center w-10 h-10 rounded-full text-white text-lg font-bold bg-purple-600">
                        M
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900">Mastodon</p>
                        <p class="text-xs text-gray-500">Federated — works with any Mastodon instance</p>
                    </div>
                </div>
                <div class="mb-3 p-2 bg-blue-50 border border-blue-200 rounded text-xs text-blue-800">
                    <p class="font-medium mb-1">How it works:</p>
                    <ol class="list-decimal ml-4 space-y-0.5">
                        <li>Enter your instance URL (e.g. <code>https://mastodon.social</code>)</li>
                        <li>We auto-register an OAuth app on the instance</li>
                        <li>You authorize on the instance — no manual app creation needed</li>
                        <li>Token stored, ready to post toots</li>
                    </ol>
                </div>
                <form @submit.prevent="connectMastodon" class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Instance URL</label>
                        <input v-model="mastodonForm.instance_url" type="url" required
                            placeholder="https://mastodon.social"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm" />
                        <p v-if="mastodonForm.errors.instance_url" class="mt-1 text-xs text-red-600">{{ mastodonForm.errors.instance_url }}</p>
                    </div>
                    <button type="submit" :disabled="mastodonForm.processing"
                        class="w-full py-2 text-xs font-medium text-center text-white bg-purple-600 rounded-md hover:bg-purple-700 disabled:opacity-50">
                        {{ mastodonForm.processing ? 'Registering app on instance...' : 'Connect Mastodon Instance' }}
                    </button>
                </form>
            </div>
        </div>

        <!-- Info box -->
        <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-md">
            <p class="text-sm text-blue-800">
                <strong>Setup required:</strong> Register API apps at each provider and fill in
                <code class="px-1 py-0.5 bg-blue-100 rounded">TWITTER_CLIENT_ID</code>,
                <code class="px-1 py-0.5 bg-blue-100 rounded">FACEBOOK_CLIENT_ID</code>,
                <code class="px-1 py-0.5 bg-blue-100 rounded">LINKEDIN_CLIENT_ID</code>,
                <code class="px-1 py-0.5 bg-blue-100 rounded">YOUTUBE_CLIENT_ID</code>,
                <code class="px-1 py-0.5 bg-blue-100 rounded">TIKTOK_CLIENT_ID</code>,
                <code class="px-1 py-0.5 bg-blue-100 rounded">PINTEREST_CLIENT_ID</code>,
                <code class="px-1 py-0.5 bg-blue-100 rounded">TELEGRAM_TOKEN</code> in your
                <code class="px-1 py-0.5 bg-blue-100 rounded">.env</code> file.
                <strong>Mastodon doesn't require .env config</strong> — OAuth app is auto-registered
                on the instance when you connect.
                See the README for links to each provider's developer portal.
            </p>
        </div>
    </AppLayout>
</template>
