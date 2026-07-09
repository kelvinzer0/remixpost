<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    channels: Array,
    organization: Object,
});

const form = useForm({
    channel_ids: [],
});

// Service color/icon mapping for nicer UI
const serviceMeta = {
    instagram: { label: 'Instagram', color: 'bg-pink-500', icon: '◎' },
    facebook: { label: 'Facebook', color: 'bg-blue-600', icon: 'f' },
    twitter: { label: 'X (Twitter)', color: 'bg-black', icon: '𝕏' },
    linkedin: { label: 'LinkedIn', color: 'bg-blue-700', icon: 'in' },
    pinterest: { label: 'Pinterest', color: 'bg-red-700', icon: 'P' },
    tiktok: { label: 'TikTok', color: 'bg-black', icon: '♪' },
    youtube: { label: 'YouTube', color: 'bg-red-600', icon: '▶' },
    mastodon: { label: 'Mastodon', color: 'bg-purple-600', icon: 'M' },
    threads: { label: 'Threads', color: 'bg-black', icon: '@' },
    bluesky: { label: 'Bluesky', color: 'bg-blue-500', icon: '☁' },
    googlebusiness: { label: 'Google Business', color: 'bg-green-600', icon: 'G' },
    startPage: { label: 'StartPage', color: 'bg-gray-600', icon: 'S' },
};

const getMeta = (service) => serviceMeta[service] || { label: service, color: 'bg-gray-500', icon: '?' };

const submit = () => {
    form.post('/integrations/social/select-buffer-channel', {
        onSuccess: () => form.reset(),
    });
};

const selectedCount = computed(() => form.channel_ids.length);

const toggleAll = () => {
    if (selectedCount.value === props.channels.length) {
        form.channel_ids = [];
    } else {
        form.channel_ids = props.channels.map(c => c.id);
    }
};
</script>

<template>
    <AppLayout>
        <template #header>Select Buffer Channels</template>
        <Head title="Select Buffer Channels" />

        <div class="max-w-3xl mx-auto">
            <div class="bg-blue-50 border border-blue-200 rounded-md p-4 mb-6">
                <p class="text-sm text-blue-800">
                    <strong>{{ organization.name }}</strong> has {{ channels.length }} channel(s).
                    Select all the social accounts you want to connect to Remixpost — each
                    becomes a separate publish target. You can pick multiple (e.g. Facebook +
                    Instagram + X + LinkedIn) and schedule posts to them simultaneously.
                </p>
            </div>

            <form @submit.prevent="submit" class="bg-white p-6 rounded-lg shadow space-y-4">
                <div v-if="channels.length === 0" class="text-center py-8 text-gray-500">
                    No active channels in this organization. Connect social accounts in Buffer first
                    at <a href="https://buffer.com" target="_blank" class="text-brand-600 underline">buffer.com</a>.
                </div>

                <div v-else>
                    <div class="flex items-center justify-between mb-3 pb-3 border-b border-gray-100">
                        <p class="text-sm font-medium text-gray-700">
                            {{ selectedCount }} of {{ channels.length }} selected
                        </p>
                        <button type="button" @click="toggleAll"
                            class="text-xs text-brand-600 hover:underline">
                            {{ selectedCount === channels.length ? 'Deselect all' : 'Select all' }}
                        </button>
                    </div>

                    <div class="space-y-2 max-h-96 overflow-y-auto">
                        <label v-for="ch in channels" :key="ch.id"
                            class="flex items-center p-3 border rounded-md cursor-pointer hover:bg-gray-50 transition"
                            :class="form.channel_ids.includes(ch.id) ? 'border-brand-400 bg-brand-50' : 'border-gray-200'">
                            <input type="checkbox" v-model="form.channel_ids" :value="ch.id"
                                class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
                            <img v-if="ch.avatar" :src="ch.avatar" :alt="ch.displayName"
                                class="w-10 h-10 rounded-full ml-3" />
                            <div v-else class="flex items-center justify-center w-10 h-10 rounded-full text-white text-sm font-bold ml-3"
                                :class="getMeta(ch.service).color">
                                {{ getMeta(ch.service).icon }}
                            </div>
                            <div class="ml-3 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm font-medium text-gray-900">{{ ch.displayName || ch.name }}</p>
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold rounded text-white"
                                        :class="getMeta(ch.service).color">
                                        {{ getMeta(ch.service).label }}
                                    </span>
                                    <span v-if="ch.isQueuePaused"
                                        class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-yellow-100 text-yellow-800 border border-yellow-200">
                                        PAUSED
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500">@{{ ch.name }} · {{ ch.service }}</p>
                            </div>
                        </label>
                    </div>
                </div>

                <div v-if="form.errors.channel_ids" class="text-sm text-red-600">{{ form.errors.channel_ids }}</div>

                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <Link href="/social-accounts"
                        class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="form.processing || selectedCount === 0"
                        class="px-4 py-2 text-sm font-medium text-white bg-blue-900 rounded-md hover:bg-blue-800 disabled:opacity-50">
                        {{ form.processing ? 'Connecting...' : `Connect ${selectedCount} channel${selectedCount === 1 ? '' : 's'}` }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
