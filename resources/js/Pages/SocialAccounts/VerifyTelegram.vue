<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    channel_title: String,
    channel_username: String,
    verification_code: String,
    bot_username: String,
    bot_deep_link: String,
    expires_at: String,
    waiting: Boolean,
    message: String,
});

const form = useForm({});

const verify = () => {
    form.post('/integrations/social/verify-telegram', {
        preserveScroll: true,
    });
};

// Countdown timer (computed once on render)
const expiryDate = computed(() => new Date(props.expires_at));
const expiryLabel = computed(() => {
    const d = expiryDate.value;
    return d.toLocaleString();
});
</script>

<template>
    <AppLayout>
        <template #header>Verify Telegram Channel</template>
        <Head title="Verify Telegram Channel" />

        <div class="max-w-xl mx-auto">
            <div class="bg-white p-6 rounded-lg shadow space-y-5">
                <!-- Header -->
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center w-12 h-12 rounded-full bg-blue-500 text-white text-xl font-bold">✈</div>
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ channel_title }}</h2>
                        <p class="text-xs text-gray-500">{{ channel_username }}</p>
                    </div>
                </div>

                <!-- Step indicator -->
                <div class="flex items-center justify-between text-xs">
                    <div class="flex-1 text-center">
                        <div class="w-7 h-7 mx-auto rounded-full bg-green-500 text-white flex items-center justify-center font-bold">✓</div>
                        <p class="mt-1 text-gray-700">1. Bot access verified</p>
                    </div>
                    <div class="w-8 h-px bg-gray-300"></div>
                    <div class="flex-1 text-center">
                        <div class="w-7 h-7 mx-auto rounded-full bg-blue-500 text-white flex items-center justify-center font-bold">2</div>
                        <p class="mt-1 text-gray-700 font-medium">2. Prove you're an admin</p>
                    </div>
                    <div class="w-8 h-px bg-gray-300"></div>
                    <div class="flex-1 text-center">
                        <div class="w-7 h-7 mx-auto rounded-full bg-gray-200 text-gray-500 flex items-center justify-center font-bold">3</div>
                        <p class="mt-1 text-gray-500">3. Connected</p>
                    </div>
                </div>

                <!-- Already verified at step 1 -->
                <div class="p-3 bg-green-50 border border-green-200 rounded-md">
                    <p class="text-sm text-green-800 flex items-start gap-2">
                        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        <span>
                            <strong>Bot is admin of this channel.</strong>
                            A verification code was posted and auto-deleted — proving post+delete permissions.
                        </span>
                    </p>
                </div>

                <!-- Verification code -->
                <div class="text-center">
                    <p class="text-sm text-gray-600 mb-2">Your verification code (valid until {{ expiryLabel }}):</p>
                    <div class="inline-block px-6 py-3 bg-gray-900 text-white text-2xl font-mono font-bold tracking-widest rounded-md select-all">
                        {{ verification_code }}
                    </div>
                </div>

                <!-- Instructions -->
                <div class="p-4 bg-blue-50 border border-blue-200 rounded-md">
                    <p class="text-sm font-medium text-blue-900 mb-2">How to complete verification:</p>
                    <ol class="text-xs text-blue-800 list-decimal ml-5 space-y-1">
                        <li>Open a <strong>private chat</strong> with our bot: <a :href="bot_deep_link" target="_blank" class="underline font-medium">@{{ bot_username }}</a></li>
                        <li>Send the code <code class="px-1 py-0.5 bg-blue-100 rounded font-mono">{{ verification_code }}</code> to the bot in that private chat (NOT in the channel).</li>
                        <li>Come back here and click <strong>"I've sent the code"</strong> below.</li>
                    </ol>
                    <p class="text-xs text-blue-700 mt-2">
                        We'll verify you're a channel admin by matching your Telegram user ID against the channel's admin list.
                    </p>
                </div>

                <!-- Waiting message -->
                <div v-if="waiting" class="p-3 bg-yellow-50 border border-yellow-200 rounded-md">
                    <p class="text-sm text-yellow-800 flex items-start gap-2">
                        <svg class="w-4 h-4 mt-0.5 flex-shrink-0 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="3" opacity="0.25"/><path d="M12 2a10 10 0 0110 10" stroke-width="3"/></svg>
                        <span>{{ message }}</span>
                    </p>
                </div>

                <!-- Error/flash message -->
                <div v-if="$page.props.flash.error" class="p-3 bg-red-50 border border-red-200 rounded-md">
                    <p class="text-sm text-red-700">{{ $page.props.flash.error }}</p>
                </div>

                <!-- Deep link button -->
                <a :href="bot_deep_link" target="_blank"
                    class="block w-full py-3 text-sm font-medium text-center text-white bg-blue-500 rounded-md hover:bg-blue-600">
                    Open @{{ bot_username }} in Telegram →
                </a>

                <!-- Verify button -->
                <form @submit.prevent="verify" class="space-y-3">
                    <button type="submit" :disabled="form.processing"
                        class="w-full py-3 text-sm font-medium text-center text-white bg-brand-600 rounded-md hover:bg-brand-700 disabled:opacity-50">
                        {{ form.processing ? 'Verifying...' : "I've sent the code — verify now" }}
                    </button>
                </form>

                <!-- Cancel -->
                <div class="text-center">
                    <Link href="/social-accounts" class="text-xs text-gray-500 underline">Cancel and go back</Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
