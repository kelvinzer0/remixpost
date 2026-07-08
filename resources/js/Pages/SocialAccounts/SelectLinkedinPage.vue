<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    pages: Array,
    personal: Object,
});

const form = useForm({
    account_type: '',
    page_id: '',
});

const submit = () => {
    if (form.account_type === 'page' && !form.page_id) {
        form.errors.page_id = 'Please select a page';
        return;
    }
    form.post('/integrations/social/select-linkedin-page', {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <AppLayout>
        <template #header>Select LinkedIn Account</template>
        <Head title="Select LinkedIn Account" />

        <div class="max-w-2xl mx-auto">
            <div class="bg-blue-50 border border-blue-200 rounded-md p-4 mb-6">
                <p class="text-sm text-blue-800">
                    Choose where you want to post — your personal LinkedIn feed or a Company Page
                    where you are an admin.
                </p>
            </div>

            <form @submit.prevent="submit" class="bg-white p-6 rounded-lg shadow space-y-4">
                <!-- Personal account -->
                <label class="flex items-center p-4 border border-gray-200 rounded-md cursor-pointer hover:bg-gray-50"
                    :class="{ 'border-blue-500 bg-blue-50': form.account_type === 'personal' }">
                    <input type="radio" v-model="form.account_type" value="personal"
                        class="text-blue-600 focus:ring-blue-500" />
                    <img v-if="personal.picture" :src="personal.picture" :alt="personal.name"
                        class="w-12 h-12 rounded-full ml-3" />
                    <div v-else class="flex items-center justify-center w-12 h-12 rounded-full bg-blue-700 text-white text-lg font-bold ml-3">
                        in
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900">{{ personal.name }}</p>
                        <p class="text-xs text-gray-500">Personal feed</p>
                    </div>
                </label>

                <!-- Separator -->
                <div v-if="pages.length > 0" class="flex items-center gap-3 py-2">
                    <div class="flex-1 border-t border-gray-200"></div>
                    <span class="text-xs text-gray-400">Company Pages</span>
                    <div class="flex-1 border-t border-gray-200"></div>
                </div>

                <!-- Company pages -->
                <label v-for="page in pages" :key="page.id"
                    class="flex items-center p-4 border border-gray-200 rounded-md cursor-pointer hover:bg-gray-50"
                    :class="{ 'border-blue-500 bg-blue-50': form.page_id === page.id }">
                    <input type="radio" v-model="form.page_id" :value="page.id"
                        @change="form.account_type = 'page'"
                        class="text-blue-600 focus:ring-blue-500" />
                    <img v-if="page.logo" :src="page.logo" :alt="page.name"
                        class="w-12 h-12 rounded-full ml-3" />
                    <div v-else class="flex items-center justify-center w-12 h-12 rounded-full bg-blue-700 text-white text-lg font-bold ml-3">
                        {{ page.name.charAt(0) }}
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900">{{ page.name }}</p>
                        <p class="text-xs text-gray-500">{{ page.vanityName || 'Company Page' }}</p>
                    </div>
                </label>

                <div v-if="form.errors.page_id" class="text-sm text-red-600">{{ form.errors.page_id }}</div>

                <div class="flex items-center justify-between pt-4">
                    <Link href="/social-accounts"
                        class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="form.processing || !form.account_type"
                        class="px-4 py-2 text-sm font-medium text-white bg-blue-700 rounded-md hover:bg-blue-800 disabled:opacity-50">
                        Connect Account
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
