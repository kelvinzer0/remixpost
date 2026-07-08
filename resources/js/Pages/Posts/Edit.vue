<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    post: Object,
    accounts: Array,
});

const form = useForm({
    content: props.post.content,
    media_urls: props.post.media_urls || [],
    account_ids: props.post.social_accounts?.map(a => a.id) || [],
    scheduled_at: props.post.scheduled_at
        ? new Date(props.post.scheduled_at).toISOString().slice(0, 16)
        : '',
});

const providers = [
    { id: 'twitter', name: 'Twitter/X', color: 'bg-black' },
    { id: 'facebook', name: 'Facebook', color: 'bg-blue-600' },
    { id: 'linkedin', name: 'LinkedIn', color: 'bg-blue-700' },
    { id: 'instagram', name: 'Instagram', color: 'bg-pink-500' },
    { id: 'youtube', name: 'YouTube', color: 'bg-red-600' },
    { id: 'tiktok', name: 'TikTok', color: 'bg-black' },
    { id: 'pinterest', name: 'Pinterest', color: 'bg-red-700' },
    { id: 'mastodon', name: 'Mastodon', color: 'bg-purple-600' },
    { id: 'telegram', name: 'Telegram', color: 'bg-blue-500' },
    { id: 'email', name: 'Email', color: 'bg-gray-600' },
];

const submit = () => {
    form.put(`/posts/${props.post.id}`, {
        onSuccess: () => form.reset(),
    });
};

const minDate = () => {
    const d = new Date(Date.now() + 5 * 60 * 1000);
    return d.toISOString().slice(0, 16);
};
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
                            class="flex items-center p-3 border border-gray-200 rounded-md cursor-pointer hover:bg-gray-50">
                            <input type="checkbox" v-model="form.account_ids" :value="account.id"
                                class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
                            <div class="flex items-center justify-center w-8 h-8 rounded-full text-white text-xs ml-3"
                                :class="providers.find(p => p.id === account.provider)?.color || 'bg-gray-500'">
                                {{ account.provider.charAt(0).toUpperCase() }}
                            </div>
                            <div class="ml-2">
                                <p class="text-sm font-medium text-gray-900">{{ account.name }}</p>
                                <p class="text-xs text-gray-500 capitalize">{{ account.provider }}</p>
                            </div>
                        </label>
                    </div>
                    <p v-if="form.errors.account_ids" class="mt-1 text-sm text-red-600">{{ form.errors.account_ids }}</p>
                </div>

                <!-- Schedule -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Schedule for</label>
                    <input v-model="form.scheduled_at" type="datetime-local" required :min="minDate()"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500" />
                    <p v-if="form.errors.scheduled_at" class="mt-1 text-sm text-red-600">{{ form.errors.scheduled_at }}</p>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end space-x-3">
                    <Link :href="`/posts/${post.id}`"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="form.processing"
                        class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-md hover:bg-brand-700 disabled:opacity-50">
                        Update Post
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
