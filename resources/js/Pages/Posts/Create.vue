<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    accounts: Array,
    media: Array,
});

const page = usePage();
const platformRequirements = computed(() => page.props.platformRequirements || {});

const form = useForm({
    content: '',
    media_urls: [],
    watermark_settings: {
        enabled: false,
        text: '@warunglakku',
        position: 'bottom-right',
        font_size: 24,
        opacity: 60,
        applied_to: [], // array of media URLs that have watermark enabled
    },
    tags: [],
    tagInput: '',
    first_comment: '',
    alt_text: '',
    account_overrides: {},
    account_ids: [],
    scheduled_at: '',
});

// ===== Watermark =====
const WATERMARK_POSITIONS = [
    { value: 'top-left', label: '↖ Top Left' },
    { value: 'top-center', label: '↑ Top Center' },
    { value: 'top-right', label: '↗ Top Right' },
    { value: 'middle-left', label: '← Middle Left' },
    { value: 'middle-center', label: '● Middle Center' },
    { value: 'middle-right', label: '→ Middle Right' },
    { value: 'bottom-left', label: '↙ Bottom Left' },
    { value: 'bottom-center', label: '↓ Bottom Center' },
    { value: 'bottom-right', label: '↘ Bottom Right' },
];

// Media URLs eligible for watermark (image or video only — not PDF/docs)
const watermarkableMedia = computed(() => {
    return form.media_urls.filter(url => isImageUrl(url) || isVideoUrl(url));
});

// Check if a specific media URL has watermark enabled
const isWatermarkApplied = (url) => {
    return (form.watermark_settings.applied_to || []).includes(url);
};

// Toggle watermark for a specific media URL
const toggleWatermarkApplied = (url) => {
    if (!form.watermark_settings.applied_to) {
        form.watermark_settings.applied_to = [];
    }
    const idx = form.watermark_settings.applied_to.indexOf(url);
    if (idx >= 0) {
        form.watermark_settings.applied_to.splice(idx, 1);
    } else {
        form.watermark_settings.applied_to.push(url);
    }
};

// Apply watermark to ALL watermarkable media
const applyWatermarkToAll = () => {
    form.watermark_settings.applied_to = [...watermarkableMedia.value];
};

// Clear watermark from all media
const clearWatermarkApplied = () => {
    form.watermark_settings.applied_to = [];
};

// Clean up applied_to when media is removed (remove stale URLs)
watch(() => form.media_urls, (newUrls) => {
    if (!form.watermark_settings.applied_to) return;
    form.watermark_settings.applied_to = form.watermark_settings.applied_to.filter(
        url => newUrls.includes(url)
    );
}, { deep: true });

// CSS positioning for watermark preview overlay
// Maps watermark_settings.position to CSS classes
const watermarkPositionClass = (position) => {
    const map = {
        'top-left': 'top-2 left-2',
        'top-center': 'top-2 left-1/2 -translate-x-1/2',
        'top-right': 'top-2 right-2',
        'middle-left': 'top-1/2 left-2 -translate-y-1/2',
        'middle-center': 'top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2',
        'middle-right': 'top-1/2 right-2 -translate-y-1/2',
        'bottom-left': 'bottom-2 left-2',
        'bottom-center': 'bottom-2 left-1/2 -translate-x-1/2',
        'bottom-right': 'bottom-2 right-2',
    };
    return map[position] || map['bottom-right'];
};

// Show watermark panel (collapsible)
const showWatermarkPanel = ref(false);

// ===== Buffer per-account overrides (Pinterest board + IG mode) =====
const bufferBoards = ref({}); // { accountId: [{ serviceId, name }] }
const loadingBoardsFor = ref({}); // { accountId: true/false }

const isBufferPinterest = (account) =>
    account.provider === 'buffer' && account.metadata?.channel_service === 'pinterest';

const isBufferInstagram = (account) =>
    account.provider === 'buffer' && account.metadata?.channel_service === 'instagram';

const isWhatsApp = (account) => account.provider === 'whatsapp';

// ===== WhatsApp per-account overrides (target_type + target) =====
const waTargets = ref({}); // { accountId: [{ id, name, picture, description, phone? }] }
const loadingWaTargetsFor = ref({});
const waSearch = ref({}); // { accountId: 'search text' }
const waTargetError = ref({}); // { accountId: 'error message' }

const fetchWaTargets = async (accountId, targetType) => {
    if (!targetType || targetType === 'story') return;
    loadingWaTargetsFor.value[accountId] = true;
    try {
        const response = await fetch('/integrations/social/whatsapp-targets', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ account_id: accountId, target_type: targetType }),
        });
        const data = await response.json();
        if (data.targets) {
            waTargets.value[accountId] = data.targets;
        } else if (data.error) {
            console.error('Failed to fetch WA targets:', data.error);
            waTargetError.value[accountId] = data.error;
            waTargets.value[accountId] = [];
        }
    } catch (e) {
        console.error('Failed to fetch WA targets:', e);
        waTargetError.value[accountId] = 'Gagal fetch: ' + e.message;
        waTargets.value[accountId] = [];
    } finally {
        loadingWaTargetsFor.value[accountId] = false;
    }
};

const setWaTarget = (accountId, targetType, target) => {
    if (!form.account_overrides[accountId]) {
        form.account_overrides[accountId] = {};
    }
    form.account_overrides[accountId].target_type = targetType;
    form.account_overrides[accountId].target = target;
};

const filteredWaTargets = (accountId) => {
    const all = waTargets.value[accountId] || [];
    const q = (waSearch.value[accountId] || '').toLowerCase().trim();
    if (!q) return all;
    return all.filter(t =>
        (t.name || '').toLowerCase().includes(q) ||
        (t.description || '').toLowerCase().includes(q) ||
        (t.phone || '').toLowerCase().includes(q)
    );
};

const fetchBoardsForAccount = async (accountId) => {
    loadingBoardsFor.value[accountId] = true;
    try {
        const response = await fetch('/ai/buffer-account-boards', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ account_id: accountId }),
        });
        const data = await response.json();
        if (data.boards) {
            bufferBoards.value[accountId] = data.boards;
            // Auto-select default board (from account metadata or first)
            const account = props.accounts.find(a => a.id === accountId);
            const defaultBoard = account?.metadata?.pinterest_board_id;
            if (defaultBoard || data.boards.length > 0) {
                setOverride(accountId, 'pinterest_board_id', defaultBoard || data.boards[0]?.serviceId);
            }
        }
    } catch (e) {
        console.error('Failed to fetch boards:', e);
    } finally {
        loadingBoardsFor.value[accountId] = false;
    }
};

const setOverride = (accountId, key, value) => {
    if (!form.account_overrides[accountId]) {
        form.account_overrides[accountId] = {};
    }
    form.account_overrides[accountId][key] = value;
};

const onAccountToggle = (accountId) => {
    const account = props.accounts.find(a => a.id === accountId);
    if (!account) return;

    if (form.account_ids.includes(accountId)) {
        // Just checked — if Buffer Pinterest, fetch boards
        if (isBufferPinterest(account)) {
            fetchBoardsForAccount(accountId);
        }
        // If Buffer Instagram, default to metadata value or 'post'
        if (isBufferInstagram(account)) {
            setOverride(accountId, 'instagram_post_type', account.metadata?.instagram_post_type || 'post');
        }
        // If WhatsApp, default to story target (no fetch needed)
        if (isWhatsApp(account) && !form.account_overrides[accountId]?.target_type) {
            setWaTarget(accountId, 'story', '');
        }
    } else {
        // Just unchecked — clean up override
        delete form.account_overrides[accountId];
    }
};

// ===== AI Caption Generator =====
const showAIModal = ref(false);
const aiGenerating = ref(false);
const aiError = ref('');
const aiCaptions = ref([]);
const aiTone = ref('casual');
const aiCount = ref(3);
const aiContextPreview = ref('');

// ===== WhatsApp presence recommendations =====
const waRecommendations = ref([]);
const waRecommendLoading = ref(false);
const waRecommendError = ref('');

const fetchWARecommendations = async () => {
    waRecommendLoading.value = true;
    waRecommendError.value = '';
    try {
        const response = await fetch('/whatsapp-presence/recommend', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json();
        if (data.recommendations) {
            waRecommendations.value = data.recommendations;
        } else if (data.reason) {
            waRecommendError.value = data.reason;
        }
    } catch (e) {
        waRecommendError.value = 'Gagal fetch rekomendasi presence.';
    } finally {
        waRecommendLoading.value = false;
    }
};

const applyRecommendation = (hour) => {
    // Set scheduled_at to next occurrence of that hour, +5 min buffer
    const now = new Date();
    const target = new Date(now);
    target.setHours(hour, 5, 0, 0); // HH:05:00 to ensure future
    if (target <= now) {
        target.setDate(target.getDate() + 1); // tomorrow if hour already passed
    }
    // Format as datetime-local input value (YYYY-MM-DDTHH:MM)
    const tzOffset = target.getTimezoneOffset() * 60000;
    form.scheduled_at = new Date(target - tzOffset).toISOString().slice(0, 16);
};

// Fetch on mount (non-blocking)
onMounted(() => {
    fetchWARecommendations();
});

// ===== Auto-save (Draft) =====
const autoSaveStatus = ref(''); // '', 'saving...', 'saved at HH:MM:SS', 'error'
const autoSavePostId = ref(null); // once created, track post ID for updates
const autoSaveTimer = ref(null);
const isSubmitting = ref(false); // prevent auto-save when manually submitting

const triggerAutoSave = () => {
    if (isSubmitting.value) return;
    if (autoSaveTimer.value) clearTimeout(autoSaveTimer.value);
    autoSaveTimer.value = setTimeout(doAutoSave, 3000); // 3s debounce
};

const doAutoSave = async () => {
    // Don't auto-save if form is empty (no content, no accounts, no media)
    if (!form.content.trim() && form.account_ids.length === 0 && form.media_urls.length === 0) {
        return;
    }

    autoSaveStatus.value = 'saving...';

    try {
        const data = {
            content: form.content,
            media_urls: form.media_urls,
            tags: form.tags,
            first_comment: form.first_comment,
            alt_text: form.alt_text,
            account_overrides: form.account_overrides,
            account_ids: form.account_ids,
            scheduled_at: form.scheduled_at || null,
        };

        const url = autoSavePostId.value
            ? `/posts/${autoSavePostId.value}/auto-save`
            : '/posts/auto-save';

        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(data),
        });

        const result = await response.json();

        if (result.success) {
            autoSaveStatus.value = `Draft tersimpan · ${result.saved_at}`;
            // If this was a new draft (no post ID yet), switch URL to edit page
            if (!autoSavePostId.value && result.post_id) {
                autoSavePostId.value = result.post_id;
                // Update browser URL without reload
                window.history.replaceState({}, '', `/posts/${result.post_id}/edit`);
            }
        } else {
            autoSaveStatus.value = 'Gagal auto-save';
        }
    } catch (e) {
        autoSaveStatus.value = 'Gagal auto-save';
    }
};

// Watch form fields for changes → trigger auto-save
watch([
    () => form.content,
    () => form.media_urls,
    () => form.tags,
    () => form.first_comment,
    () => form.account_ids,
    () => form.scheduled_at,
], triggerAutoSave, { deep: true });

onUnmounted(() => {
    if (autoSaveTimer.value) clearTimeout(autoSaveTimer.value);
});

const tones = [
    { value: 'casual', label: '😊 Santai (Casual)' },
    { value: 'professional', label: '💼 Profesional' },
    { value: 'promotional', label: '📣 Promosi (Sales)' },
    { value: 'storytelling', label: '📖 Bercerita (Story)' },
    { value: 'humorous', label: '😄 Lucu (Humor)' },
    { value: 'inspirational', label: '✨ Inspiratif' },
    { value: 'informative', label: '📚 Informatif' },
];

const generateAICaption = async () => {
    aiGenerating.value = true;
    aiError.value = '';
    aiCaptions.value = [];

    try {
        const response = await fetch('/ai/caption', {
            method: 'POST',
            credentials: 'same-origin',  // ensure session cookie sent
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                prompt: form.content,
                tone: aiTone.value,
                platforms: selectedProviders.value,
                target_date: form.scheduled_at || null,
                count: aiCount.value,
            }),
        });

        if (!response.ok) {
            // Handle non-2xx responses (CSRF 419, auth 401, server 500, etc.)
            const text = await response.text();
            let errMsg = `HTTP ${response.status}`;
            try {
                const errJson = JSON.parse(text);
                errMsg = errJson.message || errJson.error || errMsg;
            } catch (_) {
                errMsg = text.substring(0, 200) || errMsg;
            }
            aiError.value = `Gagal (${response.status}): ${errMsg}`;
            return;
        }

        const data = await response.json();
        if (data.error) {
            aiError.value = data.error;
        } else if (!data.captions || data.captions.length === 0) {
            aiError.value = 'AI tidak menghasilkan caption. Coba lagi atau ubah tone.';
        } else {
            aiCaptions.value = data.captions;
            aiContextPreview.value = data.context_used || '';
        }
    } catch (e) {
        aiError.value = 'Gagal memanggil AI: ' + e.message;
        console.error('AI caption fetch error:', e);
    } finally {
        aiGenerating.value = false;
    }
};

const applyCaption = (caption) => {
    form.content = caption;
    showAIModal.value = false;
};

const openAIModal = () => {
    showAIModal.value = true;
    aiCaptions.value = [];
    aiError.value = '';
};

// ===== Tags =====
const addTag = () => {
    const tag = form.tagInput.trim().replace(/^#/, '');
    if (tag && !form.tags.includes(tag) && form.tags.length < 30) {
        form.tags.push(tag);
    }
    form.tagInput = '';
};

const removeTag = (index) => {
    form.tags.splice(index, 1);
};

const addTagOnEnter = (e) => {
    if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        addTag();
    }
};

// Check if any selected provider supports first comment
const supportsFirstComment = computed(() => {
    return selectedProviders.value.some(p => {
        const req = getReq(p);
        return req.supports_first_comment;
    });
});

const supportsTags = computed(() => {
    return selectedProviders.value.some(p => {
        const req = getReq(p);
        return req.supports_tags;
    });
});

const showMediaPicker = ref(false);

// ===== Media picker with folder support =====
const pickerMedia = ref([]);
const pickerFolders = ref([]);
const pickerCurrentFolder = ref('');
const pickerLoading = ref(false);
const pickerExpandedFolders = ref({});

const fetchPickerMedia = async (folder = '') => {
    pickerLoading.value = true;
    try {
        const params = new URLSearchParams();
        if (folder) params.set('folder', folder);
        // Fetch media (page 1, 24 items)
        const res = await fetch(`/media?${params.toString()}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        // Inertia returns HTML, not JSON. We need a dedicated API endpoint.
        // Fallback: use the props.media if available, filter client-side
        if (props.media) {
            pickerMedia.value = props.media.filter(m => {
                const mFolder = m.folder_path || '';
                return mFolder === folder;
            });
        }
        // Fetch folders via the media page Inertia props (passed from controller)
        // folderTree is not passed to Create page, so we fetch it
        if (pickerFolders.value.length === 0) {
            const folderRes = await fetch('/media?folder=', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
        }
    } catch (e) {
        // Fallback to props.media
        pickerMedia.value = props.media || [];
    } finally {
        pickerLoading.value = false;
    }
};

// Simple client-side folder filtering from props.media
const mediaFolders = computed(() => {
    if (!props.media) return [];
    const folders = new Set();
    for (const m of props.media) {
        if (m.folder_path) {
            folders.add(m.folder_path);
        }
    }
    return Array.from(folders).sort();
});

const filteredPickerMedia = computed(() => {
    if (!props.media) return [];
    return props.media.filter(m => {
        const mFolder = m.folder_path || '';
        return mFolder === pickerCurrentFolder.value;
    });
});

const openMediaPicker = () => {
    showMediaPicker.value = !showMediaPicker.value;
    pickerCurrentFolder.value = '';
};

const selectMedia = (url) => {
    form.media_urls.push(url);
    showMediaPicker.value = false;
};

// Provider meta fallback (in case platformRequirements is not loaded)
const providerFallback = {
    twitter: { label: 'Twitter/X', color: 'bg-black', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
    facebook: { label: 'Facebook', color: 'bg-blue-600', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
    linkedin: { label: 'LinkedIn', color: 'bg-blue-700', supports_image: true, supports_video: true, supports_pdf: true, requires_media: false, allows_text_only: true },
    instagram: { label: 'Instagram', color: 'bg-pink-500', supports_image: true, supports_video: true, requires_media: true, allows_text_only: false },
    youtube: { label: 'YouTube', color: 'bg-red-600', supports_image: false, supports_video: true, requires_media: true, media_type: 'video', allows_text_only: false },
    tiktok: { label: 'TikTok', color: 'bg-black', supports_image: false, supports_video: true, requires_media: true, media_type: 'video', allows_text_only: false },
    pinterest: { label: 'Pinterest', color: 'bg-red-700', supports_image: true, supports_video: true, requires_media: true, allows_text_only: false },
    mastodon: { label: 'Mastodon', color: 'bg-purple-600', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
    telegram: { label: 'Telegram', color: 'bg-blue-500', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
    email: { label: 'Email', color: 'bg-gray-600', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
    discord: { label: 'Discord', color: 'bg-indigo-600', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
    buffer: { label: 'Buffer', color: 'bg-blue-900', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
    whatsapp: { label: 'WhatsApp', color: 'bg-green-600', supports_image: true, supports_video: true, requires_media: false, allows_text_only: true },
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
const isPdfUrl = (url) => {
    const ext = url.split('.').pop()?.toLowerCase().split('?')[0];
    return ext === 'pdf';
};
const isImageMime = (mime) => mime?.startsWith('image/');
const isVideoMime = (mime) => mime?.startsWith('video/');
const isPdfMime = (mime) => mime === 'application/pdf';

/**
 * Returns { ok: bool, message: string|null } for a given provider + current form state.
 */
const checkProvider = (provider) => {
    const req = getReq(provider);
    if (!req) return { ok: true, message: null };

    const hasMedia = form.media_urls.length > 0;
    const hasImage = form.media_urls.some(isImageUrl);
    const hasVideo = form.media_urls.some(isVideoUrl);
    const hasContent = form.content.trim().length > 0;

    // Media required
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

    // Text requirement (only if media is not required)
    if (!req.allows_text_only && !hasContent) {
        return { ok: false, message: 'Requires caption text' };
    }

    // Content length
    if (req.max_content_length && form.content.length > req.max_content_length) {
        return { ok: false, message: `Exceeds ${req.max_content_length} chars (now ${form.content.length})` };
    }

    return { ok: true, message: null };
};

// Per-account check (using provider key)
const accountCheck = (account) => checkProvider(account.provider);

// Selected accounts (full object) — derived from form.account_ids
const selectedAccounts = computed(() =>
    props.accounts.filter(a => form.account_ids.includes(a.id))
);

// Selected provider list (unique) — for summary
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

// Total validation status
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
    isSubmitting.value = true;
    if (autoSaveTimer.value) clearTimeout(autoSaveTimer.value);
    const data = form.data();
    delete data.tagInput;
    // Set watermark enabled flag based on applied_to content
    if (data.watermark_settings) {
        data.watermark_settings.enabled = (data.watermark_settings.applied_to || []).length > 0;
        // If no media has watermark applied, omit watermark_settings entirely
        if (!data.watermark_settings.enabled) {
            delete data.watermark_settings;
        }
    }
    // Clean up empty overrides
    if (data.account_overrides) {
        Object.keys(data.account_overrides).forEach(key => {
            if (!data.account_overrides[key] || Object.keys(data.account_overrides[key]).length === 0) {
                delete data.account_overrides[key];
            }
        });
        if (Object.keys(data.account_overrides).length === 0) {
            delete data.account_overrides;
        }
    }
    // If auto-saved draft exists, update it instead of creating new
    if (autoSavePostId.value) {
        form.transform(() => data).put(`/posts/${autoSavePostId.value}`, {
            onSuccess: () => form.reset(),
        });
    } else {
        form.transform(() => data).post('/posts', {
            onSuccess: () => form.reset(),
        });
    }
};

const minDate = () => {
    const d = new Date(Date.now() + 5 * 60 * 1000);
    return d.toISOString().slice(0, 16);
};
</script>

<template>
    <AppLayout>
        <template #header>Create Post</template>
        <Head title="Create Post" />

        <div class="max-w-2xl mx-auto">
            <form @submit.prevent="submit" class="space-y-6 bg-white p-6 rounded-lg shadow">
                <!-- Content -->
                <div>
                    <div class="flex items-center justify-between">
                        <label class="block text-sm font-medium text-gray-700">Content</label>
                        <button type="button" @click="openAIModal"
                            class="text-xs font-medium text-white bg-gradient-to-r from-purple-600 to-indigo-600 px-2.5 py-1 rounded-md hover:from-purple-700 hover:to-indigo-700 inline-flex items-center gap-1">
                            <span>✨</span>
                            <span>Generate dengan AI</span>
                        </button>
                    </div>
                    <textarea v-model="form.content" rows="6" required
                        placeholder="What do you want to share?"
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
                                @change="onAccountToggle(account.id)"
                                class="mt-1 rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
                            <img v-if="account.avatar" :src="account.avatar" :alt="account.name"
                                class="w-8 h-8 rounded-full ml-3" />
                            <div v-else class="flex items-center justify-center w-8 h-8 rounded-full text-white text-xs ml-3"
                                :class="getReq(account.provider).color || 'bg-gray-500'">
                                {{ account.provider.charAt(0).toUpperCase() }}
                            </div>
                            <div class="ml-2 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm font-medium text-gray-900">{{ account.name }}</p>
                                    <span class="text-xs text-gray-500 capitalize">{{ getReq(account.provider).label }}</span>
                                    <!-- Required badges (when media is mandatory) -->
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
                                    <!-- Supported badges (when media is optional) -->
                                    <template v-else>
                                        <span v-if="getReq(account.provider).supports_image"
                                            class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-green-100 text-green-800 border border-green-200">
                                            IMAGE OK
                                        </span>
                                        <span v-if="getReq(account.provider).supports_video"
                                            class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-green-100 text-green-800 border border-green-200">
                                            VIDEO OK
                                        </span>
                                        <span v-if="getReq(account.provider).supports_pdf"
                                            class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-rose-100 text-rose-800 border border-rose-200">
                                            PDF OK
                                        </span>
                                        <span v-if="!getReq(account.provider).supports_image && !getReq(account.provider).supports_video && !getReq(account.provider).supports_pdf"
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
                                <!-- Live per-account error -->
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

                            <!-- Buffer Pinterest board picker + title + link (inline) -->
                            <div v-if="isBufferPinterest(account) && form.account_ids.includes(account.id)"
                                class="mt-2 ml-8 pl-3 border-l-2 border-red-200 space-y-2">
                                <!-- Video → GIF info: Buffer API doesn't support video, will auto-convert to animated GIF -->
                                <div v-if="form.media_urls.some(isVideoUrl)"
                                    class="p-2 bg-blue-50 border border-blue-200 rounded text-xs text-blue-800">
                                    ℹ️ <strong>Video akan di-convert ke GIF animasi.</strong>
                                    Buffer API tidak support video, jadi video otomatis di-convert jadi
                                    GIF animasi (3 detik pertama) untuk Pinterest. Pin akan tampil
                                    sebagai animated GIF yang playable, bukan static image.
                                    Untuk publish video pin asli (full duration), hubungkan Pinterest
                                    langsung di <a href="/social-accounts" class="underline font-medium">Social Accounts</a>.
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">📌 Pinterest Board</label>
                                    <div v-if="loadingBoardsFor[account.id]" class="text-xs text-gray-400">Loading boards…</div>
                                    <select v-else-if="bufferBoards[account.id]?.length > 0"
                                        :value="form.account_overrides[account.id]?.pinterest_board_id || ''"
                                        @change="setOverride(account.id, 'pinterest_board_id', $event.target.value)"
                                        class="block w-full rounded-md border-gray-300 shadow-sm text-xs focus:border-red-500 focus:ring-red-500">
                                        <option v-for="board in bufferBoards[account.id]" :key="board.serviceId" :value="board.serviceId">
                                            {{ board.name }}
                                        </option>
                                    </select>
                                    <p v-else class="text-xs text-gray-400">No boards found on this Pinterest account.</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">📝 Pin Title (max 100 chars)</label>
                                    <input type="text" maxlength="100"
                                        :value="form.account_overrides[account.id]?.pinterest_title || ''"
                                        @input="setOverride(account.id, 'pinterest_title', $event.target.value)"
                                        placeholder="Judul pin yang menarik…"
                                        class="block w-full rounded-md border-gray-300 shadow-sm text-xs focus:border-red-500 focus:ring-red-500" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">🔗 Destination Link (URL tujuan pin)</label>
                                    <input type="url"
                                        :value="form.account_overrides[account.id]?.pinterest_link || ''"
                                        @input="setOverride(account.id, 'pinterest_link', $event.target.value)"
                                        placeholder="https://warunglakku.com/promo"
                                        class="block w-full rounded-md border-gray-300 shadow-sm text-xs focus:border-red-500 focus:ring-red-500" />
                                </div>
                            </div>

                            <!-- Buffer Instagram mode picker (inline) -->
                            <div v-if="isBufferInstagram(account) && form.account_ids.includes(account.id)"
                                class="mt-2 ml-8 pl-3 border-l-2 border-pink-200">
                                <label class="block text-xs font-medium text-gray-600 mb-1">📷 IG Post Type</label>
                                <div class="flex gap-3">
                                    <label class="inline-flex items-center gap-1 cursor-pointer">
                                        <input type="radio" :name="'ig-mode-' + account.id"
                                            :checked="form.account_overrides[account.id]?.instagram_post_type === 'post'"
                                            @change="setOverride(account.id, 'instagram_post_type', 'post')"
                                            class="text-pink-500 focus:ring-pink-400" />
                                        <span class="text-xs text-gray-700">Feed Post</span>
                                    </label>
                                    <label class="inline-flex items-center gap-1" :class="!form.media_urls.some(isVideoUrl) ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'">
                                        <input type="radio" :name="'ig-mode-' + account.id"
                                            :checked="form.account_overrides[account.id]?.instagram_post_type === 'reel'"
                                            @change="form.media_urls.some(isVideoUrl) ? setOverride(account.id, 'instagram_post_type', 'reel') : null"
                                            :disabled="!form.media_urls.some(isVideoUrl)"
                                            class="text-pink-500 focus:ring-pink-400" />
                                        <span class="text-xs text-gray-700">Reel</span>
                                        <span v-if="!form.media_urls.some(isVideoUrl)" class="text-[9px] text-red-500">⚠ butuh video</span>
                                    </label>
                                    <label class="inline-flex items-center gap-1 cursor-pointer">
                                        <input type="radio" :name="'ig-mode-' + account.id"
                                            :checked="form.account_overrides[account.id]?.instagram_post_type === 'story'"
                                            @change="setOverride(account.id, 'instagram_post_type', 'story')"
                                            class="text-pink-500 focus:ring-pink-400" />
                                        <span class="text-xs text-gray-700">Story</span>
                                    </label>
                                </div>
                            </div>

                            <!-- WhatsApp target picker (inline) — User / Group / Channel / Story -->
                            <div v-if="isWhatsApp(account) && form.account_ids.includes(account.id)"
                                class="mt-2 ml-8 pl-3 border-l-2 border-green-200 space-y-2">
                                <label class="block text-xs font-medium text-gray-600 mb-1">🎯 Target WhatsApp</label>
                                <div class="flex flex-wrap gap-1.5">
                                    <button type="button"
                                        @click="setWaTarget(account.id, 'story', '')"
                                        class="px-2 py-1 rounded border text-xs cursor-pointer"
                                        :class="form.account_overrides[account.id]?.target_type === 'story' ? 'border-green-500 bg-green-100 text-green-800 font-semibold' : 'border-gray-300 text-gray-700 hover:bg-gray-50'">
                                        📖 Story
                                    </button>
                                    <button type="button"
                                        @click="setWaTarget(account.id, 'channel', ''); fetchWaTargets(account.id, 'channel')"
                                        class="px-2 py-1 rounded border text-xs cursor-pointer"
                                        :class="form.account_overrides[account.id]?.target_type === 'channel' ? 'border-green-500 bg-green-100 text-green-800 font-semibold' : 'border-gray-300 text-gray-700 hover:bg-gray-50'">
                                        📢 Channel
                                    </button>
                                    <button type="button"
                                        @click="setWaTarget(account.id, 'group', ''); fetchWaTargets(account.id, 'group')"
                                        class="px-2 py-1 rounded border text-xs cursor-pointer"
                                        :class="form.account_overrides[account.id]?.target_type === 'group' ? 'border-green-500 bg-green-100 text-green-800 font-semibold' : 'border-gray-300 text-gray-700 hover:bg-gray-50'">
                                        👥 Group
                                    </button>
                                    <button type="button"
                                        @click="setWaTarget(account.id, 'user', ''); fetchWaTargets(account.id, 'user')"
                                        class="px-2 py-1 rounded border text-xs cursor-pointer"
                                        :class="form.account_overrides[account.id]?.target_type === 'user' ? 'border-green-500 bg-green-100 text-green-800 font-semibold' : 'border-gray-300 text-gray-700 hover:bg-gray-50'">
                                        👤 User
                                    </button>
                                </div>

                                <!-- Story info -->
                                <div v-if="form.account_overrides[account.id]?.target_type === 'story'"
                                    class="text-xs text-gray-500 italic p-2 bg-green-50 rounded">
                                    📖 Story akan diposting ke status WhatsApp kamu.
                                    <span v-if="form.media_urls.length > 0">Media: image/video attached.</span>
                                    <span v-else>Text-only story akan menggunakan caption sebagai teks story (background hijau).</span>
                                </div>

                                <!-- Group/Channel/User target list with pictures -->
                                <div v-else-if="['group','channel','user'].includes(form.account_overrides[account.id]?.target_type)"
                                    class="space-y-2">
                                    <!-- Search box -->
                                    <input type="text" v-model="waSearch[account.id]"
                                        placeholder="🔍 Cari nama…"
                                        class="block w-full rounded-md border-gray-300 shadow-sm text-xs focus:border-green-500 focus:ring-green-500" />

                                    <!-- Loading -->
                                    <div v-if="loadingWaTargetsFor[account.id]" class="text-xs text-gray-400 flex items-center gap-2 py-2">
                                        <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                        </svg>
                                        Memuat list dari Evolution API…
                                    </div>

                                    <!-- Error + reload button -->
                                    <div v-else-if="waTargetError[account.id]" class="p-2 bg-red-50 border border-red-200 rounded text-xs">
                                        <p class="text-red-600 mb-1">⚠️ {{ waTargetError[account.id] }}</p>
                                        <button type="button"
                                            @click="fetchWaTargets(account.id, form.account_overrides[account.id]?.target_type)"
                                            class="px-2 py-1 text-xs text-white bg-red-600 rounded hover:bg-red-700">
                                            ↻ Reload
                                        </button>
                                    </div>

                                    <!-- Target list with pictures -->
                                    <div v-else-if="waTargets[account.id]?.length > 0"
                                        class="max-h-56 overflow-y-auto border border-gray-200 rounded-md divide-y divide-gray-100">
                                        <button v-for="t in filteredWaTargets(account.id)" :key="t.id"
                                            type="button"
                                            @click="setWaTarget(account.id, form.account_overrides[account.id]?.target_type, t.id)"
                                            class="w-full flex items-center gap-2 p-2 hover:bg-green-50 text-left"
                                            :class="form.account_overrides[account.id]?.target === t.id ? 'bg-green-100' : ''">
                                            <img v-if="t.picture" :src="t.picture"
                                                class="w-8 h-8 rounded-full object-cover bg-gray-100 flex-shrink-0"
                                                @error="$event.target.style.display='none'" />
                                            <div v-else class="w-8 h-8 rounded-full bg-green-500 text-white flex items-center justify-center text-xs font-bold flex-shrink-0">
                                                {{ (t.name || '?').charAt(0).toUpperCase() }}
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-xs font-medium text-gray-900 truncate">{{ t.name }}</p>
                                                <p v-if="t.description" class="text-[10px] text-gray-500 truncate">{{ t.description }}</p>
                                                <p v-if="t.phone" class="text-[10px] text-gray-400 font-mono truncate">{{ t.phone }}</p>
                                            </div>
                                            <svg v-if="form.account_overrides[account.id]?.target === t.id" class="w-4 h-4 text-green-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                    </div>

                                    <!-- Empty state + reload -->
                                    <div v-else class="p-2 bg-gray-50 border border-gray-200 rounded text-xs text-gray-400">
                                        <p>Tidak ada target ditemukan.</p>
                                        <button type="button"
                                            @click="fetchWaTargets(account.id, form.account_overrides[account.id]?.target_type)"
                                            class="mt-1 px-2 py-1 text-xs text-green-700 bg-green-50 rounded hover:bg-green-100 border border-green-200">
                                            ↻ Reload List
                                        </button>
                                    </div>

                                    <!-- Manual input for User (phone number) -->
                                    <div v-if="form.account_overrides[account.id]?.target_type === 'user'" class="mt-1">
                                        <label class="block text-[10px] font-medium text-gray-600 mb-0.5">Atau ketik nomor manual:</label>
                                        <input type="text"
                                            :value="form.account_overrides[account.id]?.target || ''"
                                            @input="setWaTarget(account.id, 'user', $event.target.value)"
                                            placeholder="6281234567890 (format internasional tanpa +)"
                                            class="block w-full rounded-md border-gray-300 shadow-sm text-xs font-mono focus:border-green-500 focus:ring-green-500" />
                                    </div>

                                    <!-- Manual input for Group/Channel (JID) -->
                                    <div v-if="['group','channel'].includes(form.account_overrides[account.id]?.target_type)" class="mt-1">
                                        <label class="block text-[10px] font-medium text-gray-600 mb-0.5">Atau ketik JID manual:</label>
                                        <input type="text"
                                            :value="form.account_overrides[account.id]?.target || ''"
                                            @input="setWaTarget(account.id, form.account_overrides[account.id]?.target_type, $event.target.value)"
                                            :placeholder="form.account_overrides[account.id]?.target_type === 'group' ? '120363xxx@g.us' : '120363xxx@newsletter'"
                                            class="block w-full rounded-md border-gray-300 shadow-sm text-xs font-mono focus:border-green-500 focus:ring-green-500" />
                                    </div>

                                    <!-- Selected target display -->
                                    <div v-if="form.account_overrides[account.id]?.target" class="text-xs text-green-700 flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                        <span>Target: <code class="font-mono text-[10px] bg-green-50 px-1 rounded">{{ form.account_overrides[account.id]?.target }}</code></span>
                                    </div>
                                </div>
                            </div>
                        </label>
                    </div>
                    <p v-if="form.errors.account_ids" class="mt-1 text-sm text-red-600">{{ form.errors.account_ids }}</p>
                </div>

                <!-- Media selection -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">
                        Media
                        <span v-if="selectedProviders.length > 0" class="ml-1 text-xs font-normal text-gray-500">
                            ({{ selectedProviders.some(p => getReq(p).requires_media) ? 'required for selected accounts' : 'optional' }})
                        </span>
                    </label>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <div v-for="(url, i) in form.media_urls" :key="i" class="relative group">
                            <img v-if="isImageUrl(url)" :src="url" class="w-20 h-20 object-cover rounded-md border border-gray-200" />
                            <div v-else-if="isVideoUrl(url)" class="w-20 h-20 flex flex-col items-center justify-center bg-gray-900 rounded-md border border-gray-200">
                                <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M2 4a2 2 0 012-2h12a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V4z"/><path fill="#fff" d="M8 6l6 4-6 4V6z"/></svg>
                                <span class="text-[9px] text-white mt-0.5">VIDEO</span>
                            </div>
                            <div v-else-if="isPdfUrl(url)" class="w-20 h-20 flex flex-col items-center justify-center bg-rose-50 rounded-md border border-rose-200">
                                <svg class="w-7 h-7 text-rose-600" fill="currentColor" viewBox="0 0 20 20"><path d="M4 4a2 2 0 012-2h6l4 4v10a2 2 0 01-2 2H6a2 2 0 01-2-2V4z"/><text x="10" y="14" text-anchor="middle" fill="#fff" font-size="6" font-weight="bold">PDF</text></svg>
                                <span class="text-[9px] text-rose-700 mt-0.5 font-semibold">PDF</span>
                            </div>
                            <div v-else class="w-20 h-20 flex items-center justify-center bg-gray-100 rounded-md border border-gray-200">
                                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <button type="button" @click="form.media_urls.splice(i, 1)"
                                class="absolute -top-2 -right-2 w-5 h-5 bg-red-600 text-white rounded-full text-xs opacity-0 group-hover:opacity-100">
                                ×
                            </button>
                        </div>
                        <button type="button" @click="openMediaPicker"
                            class="w-20 h-20 border-2 border-dashed border-gray-300 rounded-md flex items-center justify-center text-gray-400 hover:border-brand-400 hover:text-brand-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                        </button>
                    </div>
                    <div v-if="showMediaPicker" class="mt-2 p-4 border border-gray-200 rounded-md max-h-80 overflow-hidden flex gap-3">
                        <!-- Folder sidebar -->
                        <div v-if="mediaFolders.length > 0" class="w-40 flex-shrink-0 border-r border-gray-200 pr-2 overflow-y-auto">
                            <button type="button" @click="pickerCurrentFolder = ''"
                                class="block w-full text-left px-2 py-1 rounded text-xs cursor-pointer"
                                :class="pickerCurrentFolder === '' ? 'bg-brand-100 text-brand-700 font-medium' : 'text-gray-700 hover:bg-gray-100'">
                                📁 Root ({{ props.media?.filter(m => !m.folder_path).length || 0 }})
                            </button>
                            <button v-for="folder in mediaFolders" :key="folder" type="button"
                                @click="pickerCurrentFolder = folder"
                                class="block w-full text-left px-2 py-1 rounded text-xs cursor-pointer truncate"
                                :class="pickerCurrentFolder === folder ? 'bg-brand-100 text-brand-700 font-medium' : 'text-gray-700 hover:bg-gray-100'">
                                📁 {{ folder.split('/').pop() }} ({{ props.media?.filter(m => m.folder_path === folder).length || 0 }})
                            </button>
                        </div>
                        <!-- Media grid -->
                        <div class="flex-1 overflow-y-auto">
                            <div v-if="filteredPickerMedia.length > 0" class="grid grid-cols-4 gap-2">
                                <button v-for="item in filteredPickerMedia" :key="item.id"
                                    type="button"
                                    @click="selectMedia(item.url)"
                                    class="border border-gray-200 rounded-md overflow-hidden hover:border-brand-500">
                                    <img v-if="isImageMime(item.mime_type)" :src="item.url" class="w-full h-16 object-cover" />
                                    <div v-else-if="isVideoMime(item.mime_type)" class="w-full h-16 flex flex-col items-center justify-center bg-gray-900">
                                        <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M2 4a2 2 0 012-2h12a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V4z"/><path fill="#fff" d="M8 6l6 4-6 4V6z"/></svg>
                                    </div>
                                    <div v-else-if="isPdfMime(item.mime_type)" class="w-full h-16 flex flex-col items-center justify-center bg-rose-50">
                                        <svg class="w-5 h-5 text-rose-600" fill="currentColor" viewBox="0 0 20 20"><path d="M4 4a2 2 0 012-2h6l4 4v10a2 2 0 01-2 2H6a2 2 0 01-2-2V4z"/></svg>
                                        <span class="text-[8px] text-rose-700 mt-0.5 font-bold">PDF</span>
                                    </div>
                                    <div v-else class="w-full h-16 flex items-center justify-center bg-gray-100">
                                        <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                        </svg>
                                    </div>
                                    <p class="text-xs text-gray-500 truncate px-1">{{ item.original_name }}</p>
                                </button>
                            </div>
                            <p v-else class="text-xs text-gray-400 text-center py-4">
                                No media in {{ pickerCurrentFolder || 'Root' }}. <Link href="/media" class="text-brand-600 underline">Upload some first</Link>.
                            </p>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Upload media in the <Link href="/media" class="text-brand-600">Media Manager</Link>, then select here.</p>
                </div>

                <!-- Watermark Protection -->
                <div v-if="watermarkableMedia.length > 0">
                    <button type="button" @click="showWatermarkPanel = !showWatermarkPanel"
                        class="flex items-center justify-between w-full text-sm font-medium text-gray-700">
                        <span class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                            Watermark Protection
                            <span v-if="(form.watermark_settings.applied_to || []).length > 0"
                                class="text-xs font-normal text-purple-700 bg-purple-100 px-1.5 py-0.5 rounded">
                                {{ (form.watermark_settings.applied_to || []).length }} protected
                            </span>
                        </span>
                        <svg class="w-4 h-4 transition-transform" :class="showWatermarkPanel ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div v-if="showWatermarkPanel" class="mt-3 p-4 bg-purple-50/50 border border-purple-200 rounded-md space-y-3">
                        <!-- Watermark settings -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Watermark Text</label>
                                <input v-model="form.watermark_settings.text" type="text" maxlength="100"
                                    placeholder="@username atau nama brand"
                                    class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-purple-500 focus:ring-purple-500" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Position</label>
                                <select v-model="form.watermark_settings.position"
                                    class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-purple-500 focus:ring-purple-500">
                                    <option v-for="p in WATERMARK_POSITIONS" :key="p.value" :value="p.value">{{ p.label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">
                                    Font Size: <span class="text-purple-700">{{ form.watermark_settings.font_size }}pt</span>
                                </label>
                                <input v-model.number="form.watermark_settings.font_size" type="range" min="8" max="120" step="2"
                                    class="block w-full accent-purple-600" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">
                                    Opacity: <span class="text-purple-700">{{ form.watermark_settings.opacity }}%</span>
                                </label>
                                <input v-model.number="form.watermark_settings.opacity" type="range" min="10" max="100" step="5"
                                    class="block w-full accent-purple-600" />
                            </div>
                        </div>

                        <!-- Font info -->
                        <p class="text-[10px] text-gray-500">
                            Font: <a href="https://fonts.google.com/specimen/Poppins" target="_blank" class="text-purple-600 underline">Poppins Bold</a> (Google Fonts, OFL) — tebal & jelas
                        </p>

                        <!-- Bulk actions -->
                        <div class="flex items-center gap-2 pt-2 border-t border-purple-200">
                            <button type="button" @click="applyWatermarkToAll"
                                class="px-2 py-1 text-xs font-medium text-white bg-purple-600 hover:bg-purple-700 rounded">
                                Protect All
                            </button>
                            <button type="button" @click="clearWatermarkApplied"
                                class="px-2 py-1 text-xs font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded">
                                Clear All
                            </button>
                            <span class="text-xs text-gray-500">
                                {{ (form.watermark_settings.applied_to || []).length }} / {{ watermarkableMedia.length }} media protected
                            </span>
                        </div>

                        <!-- Per-media checklist with preview -->
                        <div class="space-y-2 pt-2 border-t border-purple-200">
                            <p class="text-xs font-medium text-gray-700">Per-Media Watermark:</p>
                            <div v-for="url in watermarkableMedia" :key="url"
                                class="flex items-center gap-3 p-2 bg-white rounded border border-gray-200">
                                <label class="flex items-center cursor-pointer">
                                    <input type="checkbox" :checked="isWatermarkApplied(url)"
                                        @change="toggleWatermarkApplied(url)"
                                        class="w-4 h-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500" />
                                </label>
                                <!-- Preview thumbnail with watermark overlay -->
                                <div class="relative w-20 h-20 rounded overflow-hidden border border-gray-200 bg-gray-100 flex-shrink-0">
                                    <img v-if="isImageUrl(url)" :src="url" class="w-full h-full object-cover" />
                                    <video v-else-if="isVideoUrl(url)" :src="url" class="w-full h-full object-cover" muted />
                                    <!-- Watermark overlay (visible only if this URL is in applied_to) -->
                                    <div v-if="isWatermarkApplied(url)"
                                        class="absolute pointer-events-none"
                                        :class="watermarkPositionClass(form.watermark_settings.position)">
                                        <span class="poppins-bold block px-1 py-0.5 text-white font-bold whitespace-nowrap"
                                            :style="{
                                                fontSize: Math.max(8, form.watermark_settings.font_size / 3) + 'px',
                                                opacity: form.watermark_settings.opacity / 100,
                                                textShadow: '1px 1px 2px rgba(0,0,0,0.8)'
                                            }">
                                            {{ form.watermark_settings.text }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-medium text-gray-900 truncate">{{ url.split('/').pop() }}</p>
                                    <p class="text-[10px] text-gray-500">
                                        {{ isImageUrl(url) ? 'Image' : 'Video' }}
                                        <span v-if="isWatermarkApplied(url)" class="text-purple-700">· ✓ Protected</span>
                                        <span v-else class="text-gray-400">· Not protected</span>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <p class="text-[10px] text-gray-500 pt-2 border-t border-purple-200">
                            Watermark diterapkan saat publish (bukan ke file asli). Font Poppins Bold, posisi & ukuran bisa diatur.
                            Video watermark memerlukan ffmpeg re-encode (lebih lambat saat publish).
                        </p>
                    </div>
                </div>

                <!-- Tags -->
                <div v-if="supportsTags">
                    <label class="block text-sm font-medium text-gray-700">
                        Tags / Hashtags
                        <span class="ml-1 text-xs font-normal text-gray-500">(untuk platform yang support)</span>
                    </label>
                    <div class="mt-1 flex flex-wrap gap-1.5 mb-2">
                        <span v-for="(tag, i) in form.tags" :key="i"
                            class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium bg-brand-100 text-brand-800 rounded-full">
                            #{{ tag }}
                            <button type="button" @click="removeTag(i)"
                                class="text-brand-600 hover:text-brand-900">×</button>
                        </span>
                    </div>
                    <input v-model="form.tagInput" type="text"
                        @keydown="addTagOnEnter"
                        placeholder="Ketik tag lalu Enter (mis. promosi, viral, diskon)"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm" />
                    <p class="mt-1 text-xs text-gray-500">Tekan Enter atau koma untuk tambah tag. Max 30 tag.</p>
                </div>

                <!-- First Comment -->
                <div v-if="supportsFirstComment">
                    <label class="block text-sm font-medium text-gray-700">
                        First Comment
                        <span class="ml-1 text-xs font-normal text-gray-500">(auto-post sebagai komentar pertama di FB/IG/LinkedIn/YouTube)</span>
                    </label>
                    <textarea v-model="form.first_comment" rows="2"
                        placeholder="Opsional — mis. hashtag tambahan atau CTA"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm"></textarea>
                    <p class="mt-1 text-xs text-gray-500">Posting utama → tunggu 5 detik → komentar ini auto-post. Cocok untuk hashtag IG atau link di komentar FB.</p>
                </div>

                <!-- WhatsApp presence recommendations -->
                <div v-if="waRecommendations.length > 0 || waRecommendLoading || waRecommendError"
                    class="p-3 bg-green-50 border border-green-200 rounded-md">
                    <p class="text-sm font-semibold text-green-800 mb-2 flex items-center gap-2">
                        <span>🎯</span> Rekomendasi Jam Posting (WA Presence)
                    </p>
                    <div v-if="waRecommendLoading" class="text-xs text-gray-500">Loading rekomendasi...</div>
                    <div v-else-if="waRecommendError" class="text-xs text-gray-500 italic">{{ waRecommendError }}</div>
                    <div v-else class="flex flex-wrap gap-2">
                        <button v-for="(rec, i) in waRecommendations" :key="rec.hour" type="button"
                            @click="applyRecommendation(rec.hour)"
                            class="inline-flex items-center gap-1 px-2 py-1 bg-white border border-green-300 rounded text-xs hover:bg-green-100">
                            <span class="text-gray-400">#{{ i + 1 }}</span>
                            <span class="font-semibold text-green-700">{{ String(rec.hour).padStart(2, '0') }}:00</span>
                            <span class="text-gray-500">({{ rec.online_count }} on, {{ rec.recent_count }} recent)</span>
                            <span class="text-gray-400">→ apply</span>
                        </button>
                    </div>
                    <p class="text-[10px] text-gray-500 mt-1">
                        Berdasarkan jam aktif kontak yang sudah consent. <Link href="/whatsapp-presence" class="underline">Kelola consent</Link>.
                    </p>
                </div>

                <!-- Schedule -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Schedule for</label>
                    <input v-model="form.scheduled_at" type="datetime-local" required :min="minDate()"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500" />
                    <p class="mt-1 text-xs text-gray-500">Pick a future date and time (your timezone).</p>
                    <p v-if="form.errors.scheduled_at" class="mt-1 text-sm text-red-600">{{ form.errors.scheduled_at }}</p>
                </div>

                <!-- Validation summary banner -->
                <div v-if="hasValidationIssues" class="p-3 bg-red-50 border border-red-200 rounded-md">
                    <p class="text-sm font-medium text-red-800 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                        Cannot schedule: {{ Object.keys(validationIssues).length }} requirement(s) not met
                    </p>
                    <ul class="mt-1 ml-6 list-disc text-xs text-red-700">
                        <li v-for="(msg, provider) in validationIssues" :key="provider">
                            <strong>{{ getReq(provider).label }}:</strong> {{ msg }}
                        </li>
                    </ul>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-between">
                    <!-- Auto-save indicator -->
                    <p v-if="autoSaveStatus" class="text-xs text-gray-400 flex items-center gap-1">
                        <svg v-if="autoSaveStatus === 'saving...'" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <svg v-else class="w-3 h-3 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                        {{ autoSaveStatus }}
                    </p>
                    <div v-else></div>
                    <div class="flex items-center space-x-3">
                        <Link href="/posts"
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                            Cancel
                        </Link>
                        <button type="submit" :disabled="!canSubmit"
                            class="px-4 py-2 text-sm font-medium text-white bg-brand-600 rounded-md hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            Schedule Post
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- AI Caption Modal -->
        <div v-if="showAIModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
            @click.self="showAIModal = false">
            <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                <!-- Modal header -->
                <div class="sticky top-0 bg-white border-b border-gray-200 p-4 flex items-center justify-between">
                    <h3 class="text-base font-semibold text-gray-900 flex items-center gap-2">
                        <span>✨</span> AI Caption Generator
                    </h3>
                    <button @click="showAIModal = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-4 space-y-4">
                    <!-- Info banner -->
                    <div class="p-3 bg-purple-50 border border-purple-200 rounded-md">
                        <p class="text-xs text-purple-800">
                            📅 AI akan mempertimbangkan konteks waktu: hari ini, kemarin, besok/lusa,
                            hari besar, dan suasana hari. Caption akan dibuat dengan "cara orang berfikir"
                            — natural dan relate ke momen.
                        </p>
                    </div>

                    <!-- Tone selector -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tone / Gaya</label>
                        <select v-model="aiTone"
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500 text-sm">
                            <option v-for="t in tones" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </div>

                    <!-- Count selector -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Jumlah Variasi</label>
                        <select v-model.number="aiCount"
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500 text-sm">
                            <option :value="1">1 caption</option>
                            <option :value="2">2 caption</option>
                            <option :value="3">3 caption (default)</option>
                            <option :value="5">5 caption</option>
                        </select>
                    </div>

                    <!-- Generate button -->
                    <button type="button" @click="generateAICaption" :disabled="aiGenerating"
                        class="w-full py-2.5 text-sm font-medium text-white bg-gradient-to-r from-purple-600 to-indigo-600 rounded-md hover:from-purple-700 hover:to-indigo-700 disabled:opacity-50 inline-flex items-center justify-center gap-2">
                        <svg v-if="aiGenerating" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        {{ aiGenerating ? 'Sedang berfikir...' : '✨ Generate Caption' }}
                    </button>

                    <!-- Error -->
                    <div v-if="aiError" class="p-3 bg-red-50 border border-red-200 rounded-md">
                        <p class="text-sm text-red-700">{{ aiError }}</p>
                    </div>

                    <!-- Results -->
                    <div v-if="aiCaptions.length > 0" class="space-y-3">
                        <p class="text-xs text-gray-500 font-medium">Pilih caption yang paling cocok:</p>
                        <div v-for="(caption, i) in aiCaptions" :key="i"
                            class="p-3 bg-gray-50 border border-gray-200 rounded-md hover:border-purple-300 transition">
                            <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ caption }}</p>
                            <div class="mt-2 flex items-center justify-between">
                                <span class="text-xs text-gray-400">{{ caption.length }} karakter</span>
                                <button type="button" @click="applyCaption(caption)"
                                    class="text-xs font-medium text-white bg-purple-600 px-3 py-1 rounded hover:bg-purple-700">
                                    Pakai Caption Ini
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Context preview (collapsible) -->
                    <details v-if="aiContextPreview" class="mt-3">
                        <summary class="cursor-pointer text-xs text-gray-500 hover:text-gray-700">
                            📋 Lihat konteks yang dipakai AI
                        </summary>
                        <pre class="mt-2 p-3 bg-gray-50 border border-gray-200 rounded text-[10px] text-gray-600 whitespace-pre-wrap max-h-48 overflow-y-auto">{{ aiContextPreview }}</pre>
                    </details>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
