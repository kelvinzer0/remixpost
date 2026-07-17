<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    keys: Array,
});

const page = usePage();
const newToken = computed(() => page.props.flash?.new_token || null);
const showToken = ref(false);

const form = useForm({
    name: '',
});

const createKey = () => {
    if (!form.name.trim()) return;
    form.post('/settings/api-keys', {
        onSuccess: () => {
            form.reset();
            showToken.value = true;
        },
    });
};

const deleteKey = (id, name) => {
    if (!confirm(`Revoke API key '${name}'? Any service using this key will stop working.`)) return;
    useForm({}).delete(`/settings/api-keys/${id}`);
};

const copyToken = () => {
    if (newToken.value) {
        navigator.clipboard.writeText(newToken.value);
        alert('API key copied to clipboard!');
    }
};

const formatDate = (dateStr) => {
    if (!dateStr) return 'Never';
    const d = new Date(dateStr);
    return d.toLocaleDateString('id-ID', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};
</script>

<template>
    <AppLayout>
        <template #header>API Keys</template>
        <Head title="API Keys" />

        <div class="mb-6">
            <p class="text-sm text-gray-600">
                Generate API keys untuk platform lain (n8n, Zapier, custom scripts) mengakses
                Media Manager API. Lihat
                <a href="/api/openapi.json" target="_blank" class="text-brand-600 underline">OpenAPI spec</a>
                untuk dokumentasi lengkap.
            </p>
        </div>

        <!-- New token display (one-time) -->
        <div v-if="showToken && newToken" class="mb-6 p-4 bg-green-50 border border-green-300 rounded-lg">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <p class="text-sm font-semibold text-green-900">✅ API Key Created!</p>
                    <p class="text-xs text-green-700 mt-1">Copy token ini sekarang — tidak akan ditampilkan lagi!</p>
                    <div class="mt-2 p-2 bg-white border border-green-200 rounded font-mono text-xs break-all">
                        {{ newToken }}
                    </div>
                </div>
                <button @click="copyToken"
                    class="ml-3 px-3 py-1.5 text-xs font-medium text-white bg-green-600 rounded hover:bg-green-700 flex-shrink-0">
                    Copy
                </button>
            </div>
            <button @click="showToken = false; newToken = null"
                class="mt-2 text-xs text-green-700 underline">Tutup</button>
        </div>

        <!-- Create new key -->
        <div class="mb-6 bg-white p-6 rounded-lg shadow">
            <h3 class="text-sm font-medium text-gray-900 mb-3">Generate New API Key</h3>
            <form @submit.prevent="createKey" class="flex gap-3">
                <input v-model="form.name" type="text" required
                    placeholder="Label (mis. n8n, Zapier, Custom Script)"
                    class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm" />
                <button type="submit" :disabled="form.processing"
                    class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-md hover:bg-brand-700 disabled:opacity-50">
                    Generate
                </button>
            </form>
            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
        </div>

        <!-- Existing keys -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200">
                <p class="text-sm font-medium text-gray-900">Active API Keys ({{ keys.length }})</p>
            </div>

            <div v-if="keys.length === 0" class="text-center py-8 text-gray-500 text-sm">
                No API keys yet. Generate one above.
            </div>

            <div v-else class="divide-y divide-gray-200">
                <div v-for="key in keys" :key="key.id"
                    class="p-4 flex items-center justify-between hover:bg-gray-50">
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ key.name }}</p>
                        <div class="flex items-center gap-3 mt-0.5 text-xs text-gray-500">
                            <span>Created: {{ formatDate(key.created_at) }}</span>
                            <span>Last used: {{ formatDate(key.last_used_at) }}</span>
                        </div>
                    </div>
                    <button @click="deleteKey(key.id, key.name)"
                        class="p-2 rounded-md hover:bg-red-100 text-red-600 transition"
                        title="Revoke key">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- API usage example -->
        <div class="mt-6 p-4 bg-gray-900 rounded-lg">
            <p class="text-xs font-semibold text-gray-300 mb-2">📋 Quick Start — Upload media via API:</p>
            <pre class="text-xs text-green-400 overflow-x-auto"><code>curl -X POST https://automate.warunglakku.com/api/v1/media \
  -H "Authorization: Bearer rk_YOUR_API_KEY" \
  -F "file=@photo.jpg" \
  -F "folder_path=promotions"</code></pre>
            <p class="text-xs text-gray-500 mt-2">
                Full docs: <a href="/api/openapi.json" target="_blank" class="text-blue-400 underline">/api/openapi.json</a>
            </p>
        </div>
    </AppLayout>
</template>
