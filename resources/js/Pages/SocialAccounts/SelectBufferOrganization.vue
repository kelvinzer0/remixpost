<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    organizations: Array,
    account: Object,
});

const form = useForm({
    organization_id: '',
});

const submit = () => {
    form.post('/integrations/social/select-buffer-organization', {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <AppLayout>
        <template #header>Select Buffer Organization</template>
        <Head title="Select Buffer Organization" />

        <div class="max-w-2xl mx-auto">
            <div class="bg-blue-50 border border-blue-200 rounded-md p-4 mb-6">
                <p class="text-sm text-blue-800">
                    Buffer organizes your connected social accounts into "organizations"
                    (workspaces). Select which organization contains the channels you want
                    to connect.
                </p>
            </div>

            <form @submit.prevent="submit" class="bg-white p-6 rounded-lg shadow space-y-4">
                <div v-if="organizations.length === 0" class="text-center py-8 text-gray-500">
                    No Buffer organizations found.
                </div>

                <label v-for="org in organizations" :key="org.id"
                    class="flex items-center p-4 border rounded-md cursor-pointer hover:bg-gray-50 transition"
                    :class="form.organization_id === org.id ? 'border-brand-400 bg-brand-50' : 'border-gray-200'">
                    <input type="radio" v-model="form.organization_id" :value="org.id"
                        class="text-brand-600 focus:ring-brand-500" />
                    <div class="ml-3 flex items-center">
                        <div class="flex items-center justify-center w-10 h-10 rounded-full text-white text-lg font-bold bg-blue-900">
                            B
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">{{ org.name }}</p>
                            <p class="text-xs text-gray-500">Organization ID: {{ org.id }}</p>
                        </div>
                    </div>
                </label>

                <div v-if="form.errors.organization_id" class="text-sm text-red-600">{{ form.errors.organization_id }}</div>

                <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                    <Link href="/social-accounts"
                        class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="form.processing || !form.organization_id"
                        class="px-4 py-2 text-sm font-medium text-white bg-blue-900 rounded-md hover:bg-blue-800 disabled:opacity-50">
                        {{ form.processing ? 'Loading channels...' : 'Continue' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
