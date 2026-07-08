<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    media: Object,
});

const uploading = ref(false);
const uploadError = ref('');

const formatSize = (bytes) => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
};

const uploadFile = async (event) => {
    const file = event.target.files[0];
    if (!file) return;

    uploading.value = true;
    uploadError.value = '';

    const formData = new FormData();
    formData.append('file', file);

    try {
        const response = await fetch('/media', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
            },
            body: formData,
        });

        if (!response.ok) {
            const err = await response.json();
            throw new Error(err.message || 'Upload failed');
        }

        // Reload page to show new media
        window.location.reload();
    } catch (e) {
        uploadError.value = e.message;
    } finally {
        uploading.value = false;
        event.target.value = '';
    }
};

const copyUrl = (url) => {
    navigator.clipboard.writeText(url);
};

const isImage = (mimeType) => mimeType?.startsWith('image/');
</script>

<template>
    <AppLayout>
        <template #header>Media Manager</template>
        <Head title="Media Manager" />

        <!-- Upload area -->
        <div class="mb-6">
            <label class="block">
                <div class="flex flex-col items-center justify-center p-8 border-2 border-dashed border-gray-300 rounded-lg cursor-pointer hover:border-brand-400 hover:bg-brand-50 transition">
                    <svg v-if="!uploading" class="w-12 h-12 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                    </svg>
                    <svg v-else class="animate-spin w-8 h-8 text-brand-600 mb-3" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-sm font-medium text-gray-700">
                        {{ uploading ? 'Uploading...' : 'Click to upload media' }}
                    </p>
                    <p class="text-xs text-gray-500 mt-1">PNG, JPG, GIF, MP4 up to 10MB</p>
                </div>
                <input type="file" class="hidden" @change="uploadFile" accept="image/*,video/*" :disabled="uploading" />
            </label>
            <p v-if="uploadError" class="mt-2 text-sm text-red-600">{{ uploadError }}</p>
        </div>

        <!-- Media grid -->
        <div class="bg-white rounded-lg shadow p-6">
            <div v-if="media.data.length === 0" class="text-center py-12 text-gray-500">
                No media uploaded yet.
            </div>
            <div v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                <div v-for="item in media.data" :key="item.id"
                    class="group relative border border-gray-200 rounded-lg overflow-hidden">
                    <!-- Preview -->
                    <div class="aspect-square bg-gray-100 flex items-center justify-center">
                        <img v-if="isImage(item.mime_type)" :src="item.url" :alt="item.original_name"
                            class="w-full h-full object-cover" />
                        <div v-else class="text-gray-400 text-xs p-4 text-center">
                            {{ item.mime_type }}
                        </div>
                    </div>
                    <!-- Info -->
                    <div class="p-2">
                        <p class="text-xs font-medium text-gray-900 truncate" :title="item.original_name">
                            {{ item.original_name }}
                        </p>
                        <p class="text-xs text-gray-500">{{ formatSize(item.size) }}</p>
                    </div>
                    <!-- Actions overlay -->
                    <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition flex flex-col items-center justify-center space-y-2">
                        <button @click="copyUrl(item.url)"
                            class="px-3 py-1 text-xs text-white bg-brand-600 rounded hover:bg-brand-700">
                            Copy URL
                        </button>
                        <Link :href="`/media/${item.id}`" method="delete" as="button"
                            class="px-3 py-1 text-xs text-white bg-red-600 rounded hover:bg-red-700"
                            onclick="return confirm('Delete this media?')">
                            Delete
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="media.last_page > 1" class="mt-6 flex justify-center">
                <nav class="flex space-x-1">
                    <Link v-for="link in media.links" :key="link.label" :href="link.url || '#'"
                        class="px-3 py-2 text-sm rounded-md"
                        :class="link.active ? 'bg-brand-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                        v-html="link.label"></Link>
                </nav>
            </div>
        </div>
    </AppLayout>
</template>
