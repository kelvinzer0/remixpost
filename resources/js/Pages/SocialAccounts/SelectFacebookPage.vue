<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    pages: Array,
});

const form = useForm({
    page_id: '',
});

const submit = () => {
    form.post('/social-accounts/select-facebook-page', {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <AppLayout>
        <template #header>Select Facebook Page</template>
        <Head title="Select Facebook Page" />

        <div class="max-w-2xl mx-auto">
            <div class="bg-blue-50 border border-blue-200 rounded-md p-4 mb-6">
                <p class="text-sm text-blue-800">
                    Select the Facebook Page you want to post as. You must be an admin of the Page.
                    remixpost will store the Page's access token (not your personal token).
                </p>
            </div>

            <form @submit.prevent="submit" class="bg-white p-6 rounded-lg shadow space-y-4">
                <div v-if="pages.length === 0" class="text-center py-8 text-gray-500">
                    No Facebook Pages found. You must be an admin of at least one Page.
                </div>

                <label v-for="page in pages" :key="page.id"
                    class="flex items-center p-4 border border-gray-200 rounded-md cursor-pointer hover:bg-gray-50">
                    <input type="radio" v-model="form.page_id" :value="page.id"
                        class="text-brand-600 focus:ring-brand-500" />
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900">{{ page.name }}</p>
                        <p class="text-xs text-gray-500">Page ID: {{ page.id }}</p>
                    </div>
                </label>

                <div v-if="form.errors.page_id" class="text-sm text-red-600">{{ form.errors.page_id }}</div>

                <div class="flex items-center justify-between pt-4">
                    <Link href="/social-accounts"
                        class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="form.processing || !form.page_id"
                        class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-md hover:bg-brand-700 disabled:opacity-50">
                        Connect Page
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
