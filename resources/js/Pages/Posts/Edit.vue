<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    post: Object,
    accounts: Array,
    media: Array,
});

const page = usePage();
const platformRequirements = computed(() => page.props.platformRequirements || {});

const form = useForm({
    content: props.post.content,
    media_urls: props.post.media_urls || [],
    account_ids: props.post.social_accounts?.map(a => a.id) || [],
    scheduled_at: props.post.scheduled_at
        ? new Date(props.post.scheduled_at).toISOString().slice(0, 16)
        : '',
});

const providerFallback = {
    twitter: { label: 'Twitter/X', color: 'bg-black', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
    facebook: { label: 'Facebook', color: 'bg-blue-600', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
    linkedin: { label: 'LinkedIn', color: 'bg-blue-700', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
    instagram: { label: 'Instagram', color: 'bg-pink-500', supports_image: true, supports_video: true, requires_media: true, allows_text_only: false },
    youtube: { label: 'YouTube', color: 'bg-red-600', supports_image: false, supports_video: true, requires_media: true, media_type: 'video', allows_text_only: false },
    tiktok: { label: 'TikTok', color: 'bg-black', supports_image: false, supports_video: true, requires_media: true, media_type: 'video', allows_text_only: false },
    pinterest: { label: 'Pinterest', color: 'bg-red-700', supports_image: true, supports_video: true, requires_media: true, allows_text_only: false },
    mastodon: { label: 'Mastodon', color: 'bg-purple-600', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
    telegram: { label: 'Telegram', color: 'bg-blue-500', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
    email: { label: 'Email', color: 'bg-gray-600', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
};

const getReq = (provider) => platformRequirements.value[provider] || providerFallback[provider] || { label: provider, color: 'bg-gray-500' };

// --- Live per-platform validation ---------------------------------------------
const isImageUrl = (url) => {
    const ext = url.split('.').pop()?.toLowerCase().split('?')[0];
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(ext);
};
const isVideoUrl = (url) => {
    const ext = url.split('.').pop()?.toLowerCase().split('?')[0];
    return ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'].includes(ext);
};

const checkProvider = (provider) => {
    const req = getReq(provider);
    if (!req) return { ok: true, message: null };

    const hasMedia = form.media_urls.length > 0;
    const hasImage = form.media_urls.some(isImageUrl);
    const hasVideo = form.media_urls.some(isVideoUrl);
    const hasContent = form.content.trim().length > 0;

    if (req.requires_media) {
        if (!hasMedia) {
            const typeLabel = req.media_type ? req.media_type : 'image or video';
            return { ok: false, message: `Requires ${typeLabel}` };
        }
        if (req.media_type === 'image' && !hasImage) {
            return { ok: false, message: 'Requires an image file' };
        }
        if (req.media_type === 'video' && !hasVideo) {
            return { ok: false, message: 'Requires a video file' };
        }
    }

    // Supported-type check (when media present but type unsupported)
    if (hasMedia) {
        if (!req.supports_image && hasImage && !hasVideo) {
            return { ok: false, message: 'Does not support image-only posts. Add a video file.' };
        }
        if (!req.supports_video && hasVideo && !hasImage) {
            return { ok: false, message: 'Does not support video posts. Add an image file.' };
        }
    }

    if (!req.allows_text_only && !hasContent) {
        return { ok: false, message: 'Requires caption text' };
    }

    if (req.max_content_length && form.content.length > req.max_content_length) {
        return { ok: false, message: `Exceeds ${req.max_content_length} chars (now ${form.content.length})` };
    }

    return { ok: true, message: null };
};

const accountCheck = (account) => checkProvider(account.provider);

const selectedAccounts = computed(() =>
    props.accounts.filter(a => form.account_ids.includes(a.id))
);

const selectedProviders = computed(() => {
    const seen = new Set();
    const list = [];
    for (const a of selectedAccounts.value) {
        if (!seen.has(a.provider)) {
            seen.add(a.provider);
            list.push(a.provider);
        }
    }
    return list;
});

const validationIssues = computed(() => {
    const issues = {};
    for (const provider of selectedProviders.value) {
        const check = checkProvider(provider);
        if (!check.ok) issues[provider] = check.message;
    }
    return issues;
});

const hasValidationIssues = computed(() => Object.keys(validationIssues.value).length > 0);

const canSubmit = computed(() =>
    !form.processing &&
    form.content.trim().length > 0 &&
    form.account_ids.length > 0 &&
    form.scheduled_at !== '' &&
    !hasValidationIssues.value
);

const submit = () => {
    if (!canSubmit.value) return;
    form.put(`/posts/${props.post.id}`, {
        onSuccess: () => form.reset(),
    });
};

const minDate = () => {
    const d = new Date(Date.now() + 5 * 60 * 1000);
    return d.toISOString().slice(0, 16);
};

// Media picker (optional — Edit uses media_urls directly from post)
const removeMedia = (i) => form.media_urls.splice(i, 1);
</script>

<template>
    <AppLayout>
        <template #header>Edit Post</template>
        <Head title="Edit Post" />

        <div class="max-w-2xl mx-auto">
            <form @submit.prevent="submit" class="space-y-6 bg-white p-6 rounded-lg shadow">
                <!-- Content -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Content</label>
                    <textarea v-model="form.content" rows="6" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500"></textarea>
                    <p class="mt-1 text-xs text-gray-500">{{ form.content.length }} / 5000 characters</p>
                    <p v-if="form.errors.content" class="mt-1 text-sm text-red-600">{{ form.errors.content }}</p>
                </div>

                <!-- Account selection -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Post to</label>
                    <div v-if="accounts.length === 0" class="mt-2 p-4 bg-yellow-50 border border-yellow-200 rounded-md">
                        <p class="text-sm text-yellow-800">
                            No accounts connected.
                            <Link href="/social-accounts" class="font-medium underline">Connect one first</Link>.
                        </p>
                    </div>
                    <div v-else class="mt-2 space-y-2">
                        <label v-for="account in accounts" :key="account.id"
                            class="flex items-start p-3 border rounded-md cursor-pointer hover:bg-gray-50 transition"
                            :class="form.account_ids.includes(account.id) && !accountCheck(account).ok
                                ? 'border-red-300 bg-red-50'
                                : form.account_ids.includes(account.id)
                                    ? 'border-brand-400 bg-brand-50'
                                    : 'border-gray-200'">
                            <input type="checkbox" v-model="form.account_ids" :value="account.id"
                                class="mt-1 rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
                            <div class="flex items-center justify-center w-8 h-8 rounded-full text-white text-xs ml-3"
                                :class="getReq(account.provider).color || 'bg-gray-500'">
                                {{ account.provider.charAt(0).toUpperCase() }}
                            </div>
                            <div class="ml-2 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm font-medium text-gray-900">{{ account.name }}</p>
                                    <span class="text-xs text-gray-500 capitalize">{{ getReq(account.provider).label }}</span>
                                    <span v-if="getReq(account.provider).requires_media && getReq(account.provider).media_type === 'image'"
                                        class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-amber-100 text-amber-800 border border-amber-200">
                                        IMAGE REQUIRED
                                    </span>
                                    <span v-else-if="getReq(account.provider).requires_media && getReq(account.provider).media_type === 'video'"
                                        class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-amber-100 text-amber-800 border border-amber-200">
                                        VIDEO REQUIRED
                                    </span>
                                    <span v-else-if="getReq(account.provider).requires_media"
                                        class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-amber-100 text-amber-800 border border-amber-200">
                                        IMAGE/VIDEO REQUIRED
                                    </span>
                                    <template v-else>
                                        <span v-if="getReq(account.provider).supports_image"
                                            class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-green-100 text-green-800 border border-green-200">
                                            IMAGE OK
                                        </span>
                                        <span v-if="getReq(account.provider).supports_video"
                                            class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-green-100 text-green-800 border border-green-200">
                                            VIDEO OK
                                        </span>
                                        <span v-if="!getReq(account.provider).supports_image && !getReq(account.provider).supports_video"
                                            class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-gray-100 text-gray-600 border border-gray-200">
                                            TEXT ONLY
                                        </span>
                                    </template>
                                    <span v-if="getReq(account.provider).max_content_length"
                                        class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-blue-100 text-blue-800 border border-blue-200">
                                        MAX {{ getReq(account.provider).max_content_length }} CHARS
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500">{{ account.username }}</p>
                                <p v-if="form.account_ids.includes(account.id) && !accountCheck(account).ok"
                                    class="mt-1 text-xs text-red-600 flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                                    {{ accountCheck(account).message }}
                                </p>
                                <p v-else-if="form.account_ids.includes(account.id) && getReq(account.provider).notes"
                                    class="mt-1 text-xs text-gray-400">
                                    {{ getReq(account.provider).notes }}
                                </p>
                            </div>
                        </label>
                    </div>
                    <p v-if="form.errors.account_ids" class="mt-1 text-sm text-red-600">{{ form.errors.account_ids }}</p>
                </div>

                <!-- Media preview (read-only; for edit we show what was attached) -->
                <div v-if="form.media_urls.length > 0">
                    <label class="block text-sm font-medium text-gray-700">Attached media</label>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <div v-for="(url, i) in form.media_urls" :key="i" class="relative group">
                            <img v-if="isImageUrl(url)" :src="url" class="w-20 h-20 object-cover rounded-md border border-gray-200" />
                            <div v-else-if="isVideoUrl(url)" class="w-20 h-20 flex flex-col items-center justify-center bg-gray-900 rounded-md border border-gray-200">
                                <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M2 4a2 2 0 012-2h12a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V4z"/><path fill="#fff" d="M8 6l6 4-6 4V6z"/></svg>
                                <span class="text-[9px] text-white mt-0.5">VIDEO</span>
                            </div>
                            <div v-else class="w-20 h-20 flex items-center justify-center bg-gray-100 rounded-md border border-gray-200">
                                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <button type="button" @click="removeMedia(i)"
                                class="absolute -top-2 -right-2 w-5 h-5 bg-red-600 text-white rounded-full text-xs opacity-0 group-hover:opacity-100">
                                ×
                            </button>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">To add new media, use the Create page. Here you can only remove attachments.</p>
                </div>

                <!-- Schedule -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Schedule for</label>
                    <input v-model="form.scheduled_at" type="datetime-local" required :min="minDate()"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500" />
                    <p v-if="form.errors.scheduled_at" class="mt-1 text-sm text-red-600">{{ form.errors.scheduled_at }}</p>
                </div>

                <!-- Validation summary banner -->
                <div v-if="hasValidationIssues" class="p-3 bg-red-50 border border-red-200 rounded-md">
                    <p class="text-sm font-medium text-red-800 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                        Cannot update: {{ Object.keys(validationIssues).length }} requirement(s) not met
                    </p>
                    <ul class="mt-1 ml-6 list-disc text-xs text-red-700">
                        <li v-for="(msg, provider) in validationIssues" :key="provider">
                            <strong>{{ getReq(provider).label }}:</strong> {{ msg }}
                        </li>
                    </ul>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end space-x-3">
                    <Link :href="`/posts/${post.id}`"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="!canSubmit"
                        class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-md hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        Update Post
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
