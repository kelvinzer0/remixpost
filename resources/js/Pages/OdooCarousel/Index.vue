<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    slides: Array,
    connected: Boolean,
    error: String,
});

const deletingId = ref(null);

const deleteSlide = (slideId) => {
    if (!confirm(`Delete carousel slide #${slideId}? This will remove it from the website.`)) return;
    deletingId.value = slideId;
    useForm({}).delete(`/odoo-carousel/${slideId}`, {
        onFinish: () => {
            deletingId.value = null;
        },
    });
};

const toggleSlide = (slideId) => {
    useForm({}).post(`/odoo-carousel/${slideId}/toggle`, {
        preserveScroll: true,
    });
};

const formatDate = (dateStr) => {
    if (!dateStr) return '?';
    const d = new Date(dateStr);
    return d.toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
};
</script>

<template>
    <AppLayout>
        <template #header>Odoo Carousel Manager</template>
        <Head title="Odoo Carousel Manager" />

        <div class="mb-6 flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">
                    Kelola carousel slides di
                    <a href="https://warunglakku.com" target="_blank" class="text-brand-600 underline">warunglakku.com</a>
                </p>
            </div>
            <Link href="/posts/create"
                class="px-4 py-2 text-sm font-medium text-white bg-orange-600 rounded-md hover:bg-orange-700">
                + New Slide (via Post)
            </Link>
        </div>

        <!-- Not connected warning -->
        <div v-if="!connected" class="mb-6 p-6 bg-orange-50 border border-orange-200 rounded-lg">
            <div class="flex items-start gap-3">
                <span class="text-2xl">🎠</span>
                <div>
                    <p class="text-sm font-medium text-orange-900">Odoo Carousel belum terhubung</p>
                    <p class="text-xs text-orange-700 mt-1">
                        Connect dulu di
                        <Link href="/social-accounts" class="underline font-medium">Social Accounts</Link>
                        page, lalu kembali ke sini untuk manage slides.
                    </p>
                </div>
            </div>
        </div>

        <!-- Error -->
        <div v-if="error" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm text-red-800">{{ error }}</p>
        </div>

        <!-- Slides list -->
        <div v-if="connected && !error" class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200">
                <p class="text-sm font-medium text-gray-900">
                    Slides ({{ slides.length }})
                </p>
            </div>

            <div v-if="slides.length === 0" class="text-center py-12 text-gray-500">
                No carousel slides yet. Create a post with an image and select Odoo Carousel account.
            </div>

            <div v-else class="divide-y divide-gray-200">
                <div v-for="slide in slides" :key="slide.id"
                    class="p-4 flex items-center gap-4 hover:bg-gray-50"
                    :class="{ 'opacity-50': !slide.active }">

                    <!-- Desktop thumbnail -->
                    <div class="flex gap-2 flex-shrink-0">
                        <div class="relative">
                            <img v-if="slide.image_desktop_url"
                                :src="slide.image_desktop_url"
                                :alt="slide.title"
                                class="w-24 h-12 object-cover rounded border border-gray-200"
                                @error="$event.target.style.display='none'" />
                            <div v-else class="w-24 h-12 bg-gray-100 rounded border border-gray-200 flex items-center justify-center">
                                <span class="text-[8px] text-gray-400">desktop</span>
                            </div>
                            <span class="absolute -top-1 -left-1 px-1 text-[7px] font-bold text-white bg-gray-700 rounded">2:1</span>
                        </div>
                        <div class="relative">
                            <img v-if="slide.image_mobile_url"
                                :src="slide.image_mobile_url"
                                :alt="slide.title"
                                class="w-16 h-20 object-cover rounded border border-gray-200"
                                @error="$event.target.style.display='none'" />
                            <div v-else class="w-16 h-20 bg-gray-100 rounded border border-gray-200 flex items-center justify-center">
                                <span class="text-[8px] text-gray-400">mobile</span>
                            </div>
                            <span class="absolute -top-1 -left-1 px-1 text-[7px] font-bold text-white bg-gray-700 rounded">4:5</span>
                        </div>
                    </div>

                    <!-- Slide info -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ slide.title }}</p>
                            <span v-if="!slide.active"
                                class="px-1.5 py-0.5 text-[9px] font-semibold text-gray-600 bg-gray-100 rounded">INACTIVE</span>
                            <span v-else
                                class="px-1.5 py-0.5 text-[9px] font-semibold text-green-700 bg-green-100 rounded">ACTIVE</span>
                        </div>
                        <div class="flex items-center gap-3 mt-1 text-xs text-gray-500">
                            <span>#{{ slide.id }}</span>
                            <span>Seq: {{ slide.sequence }}</span>
                            <span v-if="slide.link_url">🔗 {{ slide.link_url }}</span>
                            <span v-if="slide.desktop_media_type" class="text-gray-400">
                                {{ slide.desktop_media_type }}/{{ slide.mobile_media_type }}
                            </span>
                            <span class="text-gray-400">{{ formatDate(slide.write_date || slide.create_date) }}</span>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <!-- Toggle active/inactive -->
                        <button @click="toggleSlide(slide.id)"
                            :title="slide.active ? 'Deactivate slide' : 'Activate slide'"
                            class="p-2 rounded-md hover:bg-gray-200 transition"
                            :class="slide.active ? 'text-green-600' : 'text-gray-400'">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path v-if="slide.active" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                            </svg>
                        </button>

                        <!-- Delete -->
                        <button @click="deleteSlide(slide.id)"
                            :disabled="deletingId === slide.id"
                            title="Delete slide"
                            class="p-2 rounded-md hover:bg-red-100 text-red-600 transition disabled:opacity-50">
                            <svg v-if="deletingId === slide.id" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h22" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Help -->
        <div v-if="connected && slides.length > 0" class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
            <p class="text-xs text-blue-900">
                💡 <strong>Tip:</strong> Untuk tambah slide baru, create post dengan image dan pilih
                "Odoo Carousel" account. Image akan auto-crop ke desktop (2:1) + mobile (4:5).
                Toggle ✅ untuk activate/deactivate, 🗑️ untuk delete.
            </p>
        </div>
    </AppLayout>
</template>
