<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    media: Object,
    currentFolder: String,
    folderTree: Array,
});

const uploading = ref(false);
const uploadError = ref('');
const showNewFolderModal = ref(false);
const newFolderName = ref('');
const showMoveModal = ref(false);
const moveTargetId = ref(null);
const moveTargetFolder = ref('');
const expandedFolders = ref({});
const showFolderSidebar = ref(false); // mobile toggle

const formatSize = (bytes) => {
    if (!bytes) return '?';
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
    formData.append('folder_path', props.currentFolder || '');

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
const isVideo = (mimeType) => mimeType?.startsWith('video/');
const isPdf = (mimeType) => mimeType === 'application/pdf';

// Folder tree rendering
const toggleFolder = (path) => {
    expandedFolders.value[path] = !expandedFolders.value[path];
};

const isExpanded = (path) => expandedFolders.value[path] ?? false;

// Breadcrumb
const breadcrumbs = computed(() => {
    if (!props.currentFolder) return [];
    const parts = props.currentFolder.split('/');
    const crumbs = [];
    let path = '';
    for (const part of parts) {
        path = path ? `${path}/${part}` : part;
        crumbs.push({ name: part, path });
    }
    return crumbs;
});

const createFolder = () => {
    if (!newFolderName.value.trim()) return;
    useForm({
        name: newFolderName.value.trim(),
        parent: props.currentFolder || '',
    }).post('/media/folder/create', {
        onSuccess: () => {
            newFolderName.value = '';
            showNewFolderModal.value = false;
        },
    });
};

const deleteFolder = (folderPath) => {
    if (!confirm(`Delete folder '${folderPath}'? All media in it will be moved to Root.`)) return;
    useForm({ folder_path: folderPath }).post('/media/folder/delete');
};

const moveMedia = (id) => {
    moveTargetId.value = id;
    moveTargetFolder.value = props.currentFolder || '';
    showMoveModal.value = true;
};

const doMove = () => {
    useForm({ folder_path: moveTargetFolder.value }).post(`/media/${moveTargetId.value}/move`, {
        onSuccess: () => {
            showMoveModal.value = false;
        },
    });
};

// Flatten folder tree for move dropdown
const flatFolders = computed(() => {
    const result = [{ path: '', name: 'Root' }];
    const traverse = (nodes, prefix = '') => {
        for (const node of nodes) {
            result.push({ path: node.path, name: node.path });
            if (node.children?.length) {
                traverse(node.children, node.path);
            }
        }
    };
    traverse(props.folderTree);
    return result;
});

// Total folder count for the mobile toggle badge
const totalFolderCount = computed(() => {
    let count = 0;
    const traverse = (nodes) => {
        for (const node of nodes) {
            count += node.count || 0;
            if (node.children?.length) traverse(node.children);
        }
    };
    traverse(props.folderTree || []);
    return count;
});
</script>

<template>
    <AppLayout>
        <template #header>Media Manager</template>
        <Head title="Media Manager" />

        <!-- Mobile folder toggle button -->
        <div class="lg:hidden mb-4">
            <button @click="showFolderSidebar = !showFolderSidebar"
                class="w-full flex items-center justify-between px-4 py-2.5 bg-white rounded-lg shadow-sm border border-gray-200 text-sm font-medium text-gray-700">
                <span class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-brand-600" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M2 6a2 2 0 012-2h4l2 2h6a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                    </svg>
                    Folders
                    <span v-if="currentFolder" class="text-xs text-gray-500 truncate max-w-[140px]">· {{ currentFolder }}</span>
                </span>
                <svg class="w-5 h-5 text-gray-400 transition-transform" :class="showFolderSidebar ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
        </div>

        <div class="flex flex-col lg:flex-row lg:gap-6">
            <!-- Folder sidebar -->
            <div class="lg:w-64 lg:flex-shrink-0 mb-4 lg:mb-0" :class="showFolderSidebar ? 'block' : 'hidden lg:block'">
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-gray-900">Folders</h3>
                        <button @click="showNewFolderModal = true"
                            class="px-2 py-1 text-xs text-brand-600 hover:bg-brand-50 rounded">
                            + New
                        </button>
                    </div>

                    <!-- Root -->
                    <Link href="/media"
                        class="flex items-center gap-2 px-2 py-1.5 rounded text-sm cursor-pointer"
                        :class="!currentFolder ? 'bg-brand-100 text-brand-700 font-medium' : 'text-gray-700 hover:bg-gray-100'">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                        </svg>
                        Root
                    </Link>

                    <!-- Folder tree (recursive) -->
                    <div v-for="folder in folderTree" :key="folder.path" class="ml-2 group">
                        <div class="flex items-center">
                            <button v-if="folder.children?.length" @click="toggleFolder(folder.path)"
                                class="text-gray-400 hover:text-gray-600 p-0.5">
                                <svg class="w-3 h-3 transition-transform" :class="isExpanded(folder.path) ? 'rotate-90' : ''" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M6 4l8 6-8 6V4z"/>
                                </svg>
                            </button>
                            <div v-else class="w-4"></div>
                            <Link :href="`/media?folder=${encodeURIComponent(folder.path)}`"
                                class="flex items-center gap-1.5 px-2 py-1 rounded text-sm flex-1 cursor-pointer"
                                :class="currentFolder === folder.path ? 'bg-brand-100 text-brand-700 font-medium' : 'text-gray-700 hover:bg-gray-100'">
                                <svg class="w-4 h-4 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M2 6a2 2 0 012-2h4l2 2h6a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                                </svg>
                                <span class="truncate flex-1">{{ folder.name }}</span>
                                <span class="text-[10px] text-gray-400">{{ folder.count }}</span>
                            </Link>
                            <button @click="deleteFolder(folder.path)"
                                class="text-gray-300 hover:text-red-500 p-0.5 lg:opacity-0 lg:group-hover:opacity-100">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/>
                                </svg>
                            </button>
                        </div>
                        <!-- Children -->
                        <div v-if="folder.children?.length && isExpanded(folder.path)" class="ml-4 border-l border-gray-200 pl-2">
                            <div v-for="child in folder.children" :key="child.path" class="flex items-center group">
                                <Link :href="`/media?folder=${encodeURIComponent(child.path)}`"
                                    class="flex items-center gap-1.5 px-2 py-1 rounded text-sm flex-1 cursor-pointer"
                                    :class="currentFolder === child.path ? 'bg-brand-100 text-brand-700 font-medium' : 'text-gray-700 hover:bg-gray-100'">
                                    <svg class="w-4 h-4 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M2 6a2 2 0 012-2h4l2 2h6a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                                    </svg>
                                    <span class="truncate flex-1">{{ child.name }}</span>
                                    <span class="text-[10px] text-gray-400">{{ child.count }}</span>
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main content -->
            <div class="flex-1 min-w-0">
                <!-- Breadcrumb -->
                <div v-if="currentFolder" class="mb-4 flex items-center gap-2 text-sm overflow-x-auto whitespace-nowrap pb-1">
                    <Link href="/media" class="text-brand-600 hover:underline flex-shrink-0">Root</Link>
                    <template v-for="crumb in breadcrumbs" :key="crumb.path">
                        <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                        <Link :href="`/media?folder=${encodeURIComponent(crumb.path)}`" class="text-brand-600 hover:underline flex-shrink-0">{{ crumb.name }}</Link>
                    </template>
                </div>

                <!-- Upload area -->
                <div class="mb-6">
                    <label class="block">
                        <div class="flex flex-col items-center justify-center p-4 sm:p-6 border-2 border-dashed border-gray-300 rounded-lg cursor-pointer hover:border-brand-400 hover:bg-brand-50 transition">
                            <svg v-if="!uploading" class="w-10 h-10 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                            </svg>
                            <svg v-else class="animate-spin w-8 h-8 text-brand-600 mb-2" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <p class="text-sm font-medium text-gray-700 text-center">
                                {{ uploading ? 'Uploading...' : 'Click to upload' }}
                                <span v-if="currentFolder"> to {{ currentFolder }}</span>
                            </p>
                            <p class="text-xs text-gray-500 mt-1 text-center">PNG · JPG · GIF · WebP · MP4 · MOV · PDF — up to 100MB</p>
                        </div>
                        <input type="file" class="hidden" @change="uploadFile" accept="image/*,video/*,.pdf" :disabled="uploading" />
                    </label>
                    <p v-if="uploadError" class="mt-2 text-sm text-red-600">{{ uploadError }}</p>
                </div>

                <!-- Media grid -->
                <div class="bg-white rounded-lg shadow p-3 sm:p-6">
                    <div v-if="media.data.length === 0" class="text-center py-12 text-gray-500">
                        No media in {{ currentFolder || 'Root' }}.
                    </div>
                    <div v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 sm:gap-4">
                        <div v-for="item in media.data" :key="item.id"
                            class="group relative border border-gray-200 rounded-lg overflow-hidden bg-white">
                            <div class="aspect-square bg-gray-100 flex items-center justify-center">
                                <img v-if="isImage(item.mime_type)" :src="item.url" :alt="item.original_name"
                                    class="w-full h-full object-cover" />
                                <div v-else-if="isVideo(item.mime_type)" class="flex flex-col items-center justify-center">
                                    <svg class="w-8 h-8 sm:w-10 sm:h-10 text-gray-700" fill="currentColor" viewBox="0 0 20 20"><path d="M2 4a2 2 0 012-2h12a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V4z"/><path fill="#fff" d="M8 6l6 4-6 4V6z"/></svg>
                                    <span class="text-[10px] text-gray-500 mt-1 font-semibold">VIDEO</span>
                                </div>
                                <div v-else-if="isPdf(item.mime_type)" class="flex flex-col items-center justify-center">
                                    <svg class="w-8 h-8 sm:w-10 sm:h-10 text-rose-600" fill="currentColor" viewBox="0 0 20 20"><path d="M4 4a2 2 0 012-2h6l4 4v10a2 2 0 01-2 2H6a2 2 0 01-2-2V4z"/></svg>
                                    <span class="text-[10px] text-rose-700 mt-1 font-semibold">PDF</span>
                                </div>
                                <div v-else class="flex items-center justify-center">
                                    <svg class="w-8 h-8 sm:w-10 sm:h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </div>
                            </div>
                            <div class="p-2">
                                <p class="text-xs font-medium text-gray-900 truncate" :title="item.original_name">{{ item.original_name }}</p>
                                <p class="text-xs text-gray-500">{{ formatSize(item.size) }}</p>
                            </div>
                            <!-- Actions overlay (desktop hover) -->
                            <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition hidden sm:flex flex-col items-center justify-center space-y-1.5">
                                <button @click="copyUrl(item.url)"
                                    class="px-3 py-1 text-xs text-white bg-brand-600 rounded hover:bg-brand-700">Copy URL</button>
                                <button @click="moveMedia(item.id)"
                                    class="px-3 py-1 text-xs text-white bg-blue-600 rounded hover:bg-blue-700">Move</button>
                                <Link :href="`/media/${item.id}`" method="delete" as="button"
                                    class="px-3 py-1 text-xs text-white bg-red-600 rounded hover:bg-red-700"
                                    onclick="return confirm('Delete this media?')">Delete</Link>
                            </div>
                            <!-- Actions bar (mobile, always visible) -->
                            <div class="sm:hidden absolute bottom-0 left-0 right-0 bg-black/70 flex justify-around py-1.5">
                                <button @click="copyUrl(item.url)" title="Copy URL"
                                    class="p-1.5 text-white hover:text-brand-300">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                    </svg>
                                </button>
                                <button @click="moveMedia(item.id)" title="Move"
                                    class="p-1.5 text-white hover:text-blue-300">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                    </svg>
                                </button>
                                <Link :href="`/media/${item.id}`" method="delete" as="button" title="Delete"
                                    class="p-1.5 text-white hover:text-red-300"
                                    onclick="return confirm('Delete this media?')">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/>
                                    </svg>
                                </Link>
                            </div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div v-if="media.last_page > 1" class="mt-6 flex justify-center">
                        <nav class="flex space-x-1 overflow-x-auto max-w-full">
                            <Link v-for="link in media.links" :key="link.label" :href="link.url || '#'"
                                class="px-3 py-2 text-sm rounded-md whitespace-nowrap flex-shrink-0"
                                :class="link.active ? 'bg-brand-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                                v-html="link.label"></Link>
                        </nav>
                    </div>
                </div>
            </div>
        </div>

        <!-- New folder modal -->
        <div v-if="showNewFolderModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showNewFolderModal = false">
            <div class="bg-white rounded-lg p-6 w-full max-w-md">
                <h3 class="text-lg font-semibold mb-4">Create New Folder</h3>
                <p v-if="currentFolder" class="text-xs text-gray-500 mb-2">Parent: {{ currentFolder }}</p>
                <input v-model="newFolderName" type="text" placeholder="Folder name"
                    class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-brand-500 focus:ring-brand-500"
                    @keydown.enter="createFolder" />
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="showNewFolderModal = false" class="px-3 py-1.5 text-sm text-gray-600">Cancel</button>
                    <button @click="createFolder" class="px-3 py-1.5 text-sm text-white bg-brand-600 rounded">Create</button>
                </div>
            </div>
        </div>

        <!-- Move modal -->
        <div v-if="showMoveModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showMoveModal = false">
            <div class="bg-white rounded-lg p-6 w-full max-w-md">
                <h3 class="text-lg font-semibold mb-4">Move Media</h3>
                <select v-model="moveTargetFolder"
                    class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Root</option>
                    <option v-for="f in flatFolders.filter(f => f.path)" :key="f.path" :value="f.path">{{ f.name }}</option>
                </select>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="showMoveModal = false" class="px-3 py-1.5 text-sm text-gray-600">Cancel</button>
                    <button @click="doMove" class="px-3 py-1.5 text-sm text-white bg-brand-600 rounded">Move</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
