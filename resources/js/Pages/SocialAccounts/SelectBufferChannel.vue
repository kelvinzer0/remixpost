<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    channels: Array,
    organization: Object,
});

// Track per-channel config: Pinterest board selection + IG post type
// Keyed by channel ID
const channelConfig = ref({});

// Track which Pinterest channels are loading boards
const loadingBoards = ref({});

const form = useForm({
    channel_ids: [],
    channel_configs: {}, // { channelId: { pinterest_board_id: '...', instagram_post_type: 'post' } }
});

// Service color/icon mapping
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

const isPinterest = (channel) => channel.service === 'pinterest';
const isInstagram = (channel) => channel.service === 'instagram';

const selectedCount = computed(() => form.channel_ids.length);

// Get Pinterest channels that are selected
const selectedPinterestChannels = computed(() =>
    props.channels.filter(c => isPinterest(c) && form.channel_ids.includes(c.id))
);

// Get Instagram channels that are selected
const selectedInstagramChannels = computed(() =>
    props.channels.filter(c => isInstagram(c) && form.channel_ids.includes(c.id))
);

// Fetch Pinterest boards for a channel via our backend (proxied to Buffer API)
const fetchPinterestBoards = async (channelId) => {
    loadingBoards.value[channelId] = true;
    try {
        const response = await fetch('/ai/buffer-pinterest-boards', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ channel_id: channelId }),
        });
        const data = await response.json();
        if (data.boards) {
            channelConfig.value[channelId] = {
                ...channelConfig.value[channelId],
                boards: data.boards,
            };
            // Auto-select first board
            if (data.boards.length > 0) {
                form.channel_configs[channelId] = {
                    ...form.channel_configs[channelId],
                    pinterest_board_id: data.boards[0].serviceId,
                };
            }
        }
    } catch (e) {
        console.error('Failed to fetch Pinterest boards:', e);
    } finally {
        loadingBoards.value[channelId] = false;
    }
};

// When a channel is checked, if it's Pinterest → fetch boards
const onChannelToggle = (channelId) => {
    if (form.channel_ids.includes(channelId)) {
        // Just checked — if Pinterest, fetch boards
        const channel = props.channels.find(c => c.id === channelId);
        if (channel && isPinterest(channel)) {
            fetchPinterestBoards(channelId);
        }
        // If Instagram, default to 'post' mode
        if (channel && isInstagram(channel)) {
            form.channel_configs[channelId] = {
                ...form.channel_configs[channelId],
                instagram_post_type: 'post',
            };
        }
    } else {
        // Just unchecked — clean up config
        delete form.channel_configs[channelId];
    }
};

const toggleAll = () => {
    if (selectedCount.value === props.channels.length) {
        form.channel_ids = [];
        form.channel_configs = {};
    } else {
        form.channel_ids = props.channels.map(c => c.id);
        // Fetch boards for all Pinterest channels
        props.channels.filter(isPinterest).forEach(c => fetchPinterestBoards(c.id));
        // Default IG channels to 'post'
        props.channels.filter(isInstagram).forEach(c => {
            form.channel_configs[c.id] = { instagram_post_type: 'post' };
        });
    }
};

// Check if submit is valid (Pinterest channels must have board selected)
const canSubmit = computed(() => {
    if (selectedCount.value === 0) return false;
    // All selected Pinterest channels must have a board selected
    for (const ch of selectedPinterestChannels.value) {
        if (!form.channel_configs[ch.id]?.pinterest_board_id) return false;
    }
    return true;
});

const submit = () => {
    if (!canSubmit.value) return;
    form.post('/integrations/social/select-buffer-channel', {
        onSuccess: () => form.reset(),
    });
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
                    Select all the social accounts you want to connect.
                </p>
                <p v-if="selectedPinterestChannels.length > 0 || selectedInstagramChannels.length > 0"
                    class="text-xs text-blue-600 mt-1">
                    ⚙️ Pinterest channels need board selection · Instagram channels need post type (post/reel/story)
                </p>
            </div>

            <form @submit.prevent="submit" class="bg-white p-6 rounded-lg shadow space-y-4">
                <div v-if="channels.length === 0" class="text-center py-8 text-gray-500">
                    No active channels. Connect social accounts in Buffer first at
                    <a href="https://buffer.com" target="_blank" class="text-brand-600 underline">buffer.com</a>.
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
                            class="block p-3 border rounded-md cursor-pointer hover:bg-gray-50 transition"
                            :class="form.channel_ids.includes(ch.id) ? 'border-brand-400 bg-brand-50' : 'border-gray-200'">
                            <div class="flex items-center">
                                <input type="checkbox" v-model="form.channel_ids" :value="ch.id"
                                    @change="onChannelToggle(ch.id)"
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
                                    </div>
                                    <p class="text-xs text-gray-500">@{{ ch.name }} · {{ ch.service }}</p>
                                </div>
                            </div>

                            <!-- Pinterest board picker (inline, shown when channel is selected) -->
                            <div v-if="isPinterest(ch) && form.channel_ids.includes(ch.id)"
                                class="mt-3 pl-8 border-l-2 border-red-200 ml-3">
                                <label class="block text-xs font-medium text-gray-700 mb-1">
                                    📌 Pinterest Board (wajib dipilih)
                                </label>
                                <div v-if="loadingBoards[ch.id]" class="text-xs text-gray-400">
                                    Loading boards...
                                </div>
                                <select v-else-if="channelConfig[ch.id]?.boards?.length > 0"
                                    v-model="form.channel_configs[ch.id].pinterest_board_id"
                                    class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-red-500 focus:ring-red-500">
                                    <option v-for="board in channelConfig[ch.id]?.boards" :key="board.serviceId"
                                        :value="board.serviceId">
                                        {{ board.name }}
                                    </option>
                                </select>
                                <p v-else class="text-xs text-gray-400">
                                    No boards found. Create boards in Pinterest first.
                                </p>
                            </div>

                            <!-- Instagram post type picker (inline) -->
                            <div v-if="isInstagram(ch) && form.channel_ids.includes(ch.id)"
                                class="mt-3 pl-8 border-l-2 border-pink-200 ml-3">
                                <label class="block text-xs font-medium text-gray-700 mb-1">
                                    📷 Instagram Post Type
                                </label>
                                <div class="flex gap-3">
                                    <label class="inline-flex items-center gap-1 cursor-pointer">
                                        <input type="radio" v-model="form.channel_configs[ch.id].instagram_post_type"
                                            value="post"
                                            class="text-pink-500 focus:ring-pink-400" />
                                        <span class="text-xs text-gray-700">Post (Feed)</span>
                                    </label>
                                    <label class="inline-flex items-center gap-1 cursor-pointer">
                                        <input type="radio" v-model="form.channel_configs[ch.id].instagram_post_type"
                                            value="reel"
                                            class="text-pink-500 focus:ring-pink-400" />
                                        <span class="text-xs text-gray-700">Reel</span>
                                    </label>
                                    <label class="inline-flex items-center gap-1 cursor-pointer">
                                        <input type="radio" v-model="form.channel_configs[ch.id].instagram_post_type"
                                            value="story"
                                            class="text-pink-500 focus:ring-pink-400" />
                                        <span class="text-xs text-gray-700">Story</span>
                                    </label>
                                </div>
                                <p class="text-xs text-gray-400 mt-1">
                                    Post = feed image/video · Reel = short video · Story = 24h ephemeral
                                </p>
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
                    <button type="submit" :disabled="form.processing || !canSubmit"
                        class="px-4 py-2 text-sm font-medium text-white bg-blue-900 rounded-md hover:bg-blue-800 disabled:opacity-50 disabled:cursor-not-allowed">
                        {{ form.processing ? 'Connecting...' : `Connect ${selectedCount} channel${selectedCount === 1 ? '' : 's'}` }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
