<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    consents: Array,
    stats: Object,
    heatmap: Array,
    whatsappAccounts: Array,
});

const form = useForm({
    social_account_id: props.whatsappAccounts[0]?.id || '',
    jid: '',  // selected JID from contact picker
    phone: '', // auto-filled from selected contact
    display_name: '', // auto-filled from selected contact
    consent_method: 'manual_verbal',
    consent_expires_at: '',
    notes: '',
});

// ===== Contact picker (fetch available contacts from Evolution API) =====
const availableContacts = ref([]);
const loadingContacts = ref(false);
const contactSearch = ref('');
const contactError = ref('');
const showContactPicker = ref(false);

const fetchContacts = async () => {
    if (!form.social_account_id) return;
    loadingContacts.value = true;
    contactError.value = '';
    availableContacts.value = [];
    try {
        const response = await fetch('/whatsapp-presence/available-contacts', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ social_account_id: form.social_account_id }),
        });
        const data = await response.json();
        if (data.contacts) {
            availableContacts.value = data.contacts;
            showContactPicker.value = true;
        } else if (data.error) {
            contactError.value = data.error;
        }
    } catch (e) {
        contactError.value = 'Gagal fetch list kontak: ' + e.message;
    } finally {
        loadingContacts.value = false;
    }
};

const filteredContacts = computed(() => {
    const q = contactSearch.value.toLowerCase().trim();
    if (!q) return availableContacts.value;
    return availableContacts.value.filter(c =>
        c.name.toLowerCase().includes(q) ||
        c.phone.includes(q)
    );
});

const selectContact = (contact) => {
    form.jid = contact.jid;
    form.phone = contact.phone;
    form.display_name = contact.name;
    showContactPicker.value = false;
};

const onAccountChange = () => {
    // Reset contact selection when account changes
    form.jid = '';
    form.phone = '';
    form.display_name = '';
    availableContacts.value = [];
    contactSearch.value = '';
    showContactPicker.value = false;
};

const submit = () => {
    if (!form.jid) {
        alert('Pilih kontak dari list dulu. Klik "Load Contacts" untuk fetch list dari WhatsApp.');
        return;
    }
    form.post('/whatsapp-presence', {
        onSuccess: () => {
            form.reset();
            form.social_account_id = props.whatsappAccounts[0]?.id || '';
            availableContacts.value = [];
            showContactPicker.value = false;
        },
    });
};

const revoke = (id) => {
    if (!confirm('Revoke consent? This stops further presence checks but keeps historical samples.')) return;
    useForm({}).delete(`/whatsapp-presence/${id}`);
};

const forceDelete = (id, name) => {
    if (!confirm(`PERMANENTLY DELETE consent for ${name}? This removes all sample data and cannot be undone.`)) return;
    useForm({}).delete(`/whatsapp-presence/${id}/force`);
};

const checkNow = (id) => {
    useForm({}).post(`/whatsapp-presence/${id}/check`);
};

// Heatmap visualization: find max for color intensity scaling
const maxTotal = computed(() => Math.max(1, ...props.heatmap.map(h => h.total)));

const heatColor = (total) => {
    if (total === 0) return 'bg-gray-100';
    const intensity = total / maxTotal.value;
    if (intensity > 0.75) return 'bg-green-600 text-white';
    if (intensity > 0.5) return 'bg-green-500 text-white';
    if (intensity > 0.25) return 'bg-green-400 text-green-900';
    if (intensity > 0.1) return 'bg-green-200 text-green-900';
    return 'bg-green-100 text-green-800';
};

const formatHour = (h) => {
    return `${String(h).padStart(2, '0')}:00`;
};

// Top 3 recommended hours
const top3Hours = computed(() => {
    return [...props.heatmap]
        .map(h => ({ ...h, score: h.online * 1.0 + h.recent * 0.5 }))
        .sort((a, b) => b.score - a.score)
        .slice(0, 3);
});

const totalSamples = computed(() => props.heatmap.reduce((sum, h) => sum + h.total, 0));
const totalOnline = computed(() => props.heatmap.reduce((sum, h) => sum + h.online, 0));
</script>

<template>
    <Head title="WhatsApp Presence Tracker" />

    <AppLayout>
        <template #header>WhatsApp Presence Tracker</template>

        <!-- Privacy notice -->
        <div class="mb-6 p-4 bg-amber-50 border-l-4 border-amber-400 rounded">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 text-amber-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <div class="text-sm text-amber-800">
                    <p class="font-semibold mb-1">Privacy & Consent</p>
                    <p>Hanya tambahkan kontak yang <strong>secara explicit sudah memberi consent</strong> untuk di-track. Setiap consent harus dicatat metode consent-nya (verbal, tertulis, dsb). Menghapus consent = stop tracking segera. Lihat <Link href="/analytics" class="underline">UU PDP Indonesia</Link> untuk panduan compliance.</p>
                </div>
            </div>
        </div>

        <!-- Stats summary -->
        <div class="mb-6 grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-white p-4 rounded-lg shadow">
                <p class="text-xs text-gray-500">Total Consents</p>
                <p class="text-2xl font-bold text-gray-900">{{ consents.length }}</p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow">
                <p class="text-xs text-gray-500">Active Consents</p>
                <p class="text-2xl font-bold text-green-600">{{ consents.filter(c => c.is_active).length }}</p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow">
                <p class="text-xs text-gray-500">Total Samples (30d)</p>
                <p class="text-2xl font-bold text-gray-900">{{ totalSamples }}</p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow">
                <p class="text-xs text-gray-500">Online Detections</p>
                <p class="text-2xl font-bold text-green-600">{{ totalOnline }}</p>
            </div>
        </div>

        <!-- Heatmap -->
        <div class="mb-6 bg-white p-6 rounded-lg shadow">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Activity Heatmap (30 hari terakhir)</h2>
                <div class="flex items-center gap-2 text-xs text-gray-500">
                    <span>Less</span>
                    <div class="w-4 h-4 bg-gray-100 rounded"></div>
                    <div class="w-4 h-4 bg-green-100 rounded"></div>
                    <div class="w-4 h-4 bg-green-200 rounded"></div>
                    <div class="w-4 h-4 bg-green-400 rounded"></div>
                    <div class="w-4 h-4 bg-green-500 rounded"></div>
                    <div class="w-4 h-4 bg-green-600 rounded"></div>
                    <span>More</span>
                </div>
            </div>
            <p class="text-xs text-gray-500 mb-3">Jam berapa kontak consented biasanya aktif di WhatsApp (berdasarkan timestamp pesan terakhir mereka).</p>

            <div class="grid grid-cols-6 md:grid-cols-12 gap-1">
                <div v-for="h in heatmap" :key="h.hour"
                    :class="heatColor(h.total)"
                    class="aspect-square rounded flex flex-col items-center justify-center text-[10px] font-medium p-1">
                    <span class="opacity-70">{{ formatHour(h.hour) }}</span>
                    <span class="font-bold text-sm">{{ h.total }}</span>
                </div>
            </div>

            <!-- Top 3 recommended hours -->
            <div v-if="totalSamples > 0" class="mt-4 p-3 bg-green-50 border border-green-200 rounded">
                <p class="text-sm font-semibold text-green-800 mb-2">🎯 Jam Terbaik untuk Posting (berdasarkan presence data):</p>
                <div class="flex flex-wrap gap-2">
                    <span v-for="(h, i) in top3Hours" :key="h.hour"
                        class="inline-flex items-center gap-1 px-2 py-1 bg-white border border-green-300 rounded text-xs">
                        <span class="text-gray-400">#{{ i + 1 }}</span>
                        <span class="font-semibold text-green-700">{{ formatHour(h.hour) }}</span>
                        <span class="text-gray-500">({{ h.online }} online, {{ h.recent }} recent)</span>
                    </span>
                </div>
            </div>
            <div v-else class="mt-4 p-3 bg-gray-50 rounded text-sm text-gray-500 italic">
                Belum ada data. Tambahkan consent kontak + tunggu beberapa jam untuk sample terkumpul.
            </div>
        </div>

        <!-- Add consent form -->
        <div class="mb-6 bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">➕ Tambah Consent Baru</h2>
            <form @submit.prevent="submit" class="space-y-3">
                <div v-if="whatsappAccounts.length === 0" class="p-3 bg-yellow-50 border border-yellow-200 rounded text-sm text-yellow-800">
                    Belum ada akun WhatsApp terhubung. <Link href="/social-accounts" class="underline">Connect dulu</Link>.
                </div>
                <div v-else>
                    <!-- Step 1: Pick WA Account -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">WA Account (instance)</label>
                            <select v-model="form.social_account_id" required @change="onAccountChange"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-green-500 focus:ring-green-500">
                                <option v-for="a in whatsappAccounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="button" @click="fetchContacts"
                                :disabled="loadingContacts || !form.social_account_id"
                                class="px-3 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 disabled:opacity-50">
                                {{ loadingContacts ? 'Loading...' : (showContactPicker ? '↻ Refresh Contacts' : '📋 Load Contacts') }}
                            </button>
                        </div>
                    </div>

                    <p v-if="contactError" class="mt-2 text-xs text-red-600">{{ contactError }}</p>

                    <!-- Contact picker (modal-like inline list) -->
                    <div v-if="showContactPicker && availableContacts.length > 0" class="mt-3 border border-gray-200 rounded-md">
                        <div class="p-2 bg-gray-50 border-b border-gray-200">
                            <input v-model="contactSearch" type="text"
                                placeholder="🔍 Cari nama / nomor..."
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-green-500 focus:ring-green-500" />
                        </div>
                        <p class="px-3 py-1 text-[10px] text-gray-500 bg-gray-50 border-b border-gray-200">
                            {{ availableContacts.length }} kontak tersedia (sudah exclude yang sudah consent). Klik untuk pilih.
                        </p>
                        <div class="max-h-72 overflow-y-auto divide-y divide-gray-100">
                            <button v-for="c in filteredContacts" :key="c.jid" type="button"
                                @click="selectContact(c)"
                                class="w-full flex items-center gap-2 p-2 hover:bg-green-50 text-left"
                                :class="form.jid === c.jid ? 'bg-green-100' : ''">
                                <img v-if="c.picture" :src="c.picture"
                                    class="w-8 h-8 rounded-full object-cover bg-gray-100 flex-shrink-0"
                                    @error="$event.target.style.display='none'" />
                                <div v-else class="w-8 h-8 rounded-full bg-green-500 text-white flex items-center justify-center text-xs font-bold flex-shrink-0">
                                    {{ (c.name || '?').charAt(0).toUpperCase() }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-medium text-gray-900 truncate">{{ c.name }}</p>
                                    <p class="text-[10px] text-gray-500 font-mono truncate">{{ c.phone }}</p>
                                </div>
                                <svg v-if="form.jid === c.jid" class="w-4 h-4 text-green-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                            <p v-if="filteredContacts.length === 0" class="p-3 text-xs text-gray-400 italic text-center">Tidak ada kontak cocok.</p>
                        </div>
                    </div>
                    <div v-else-if="showContactPicker && availableContacts.length === 0 && !loadingContacts" class="mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded text-xs text-yellow-800">
                        Tidak ada kontak tersedia. Semua kontak sudah di-consent, atau instance WA tidak punya chat 1:1.
                    </div>

                    <!-- Selected contact display -->
                    <div v-if="form.jid" class="mt-3 p-3 bg-green-50 border border-green-200 rounded-md">
                        <p class="text-xs text-gray-600 mb-1">Kontak terpilih:</p>
                        <div class="flex items-center gap-2">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900">{{ form.display_name }}</p>
                                <p class="text-xs text-gray-500 font-mono">{{ form.phone }}</p>
                            </div>
                            <button type="button" @click="onAccountChange"
                                class="px-2 py-1 text-xs text-gray-600 bg-white border border-gray-300 rounded hover:bg-gray-50">
                                Ganti
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Display Name (auto-filled, editable)</label>
                            <input v-model="form.display_name" type="text"
                                placeholder="Akan terisi otomatis dari kontak"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-green-500 focus:ring-green-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Consent Method</label>
                            <select v-model="form.consent_method" required
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-green-500 focus:ring-green-500">
                                <option value="manual_verbal">Verbal (lisan)</option>
                                <option value="written">Tertulis (chat/document)</option>
                                <option value="qr_scan">QR scan (self-signup)</option>
                                <option value="self_signup">Self signup form</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Consent Expires (opsional)</label>
                            <input v-model="form.consent_expires_at" type="datetime-local"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-green-500 focus:ring-green-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Notes (opsional)</label>
                            <input v-model="form.notes" type="text"
                                placeholder="mis. 'customer opt-in via WhatsApp survey 2026-07'"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-green-500 focus:ring-green-500" />
                        </div>
                    </div>
                    <p v-if="form.errors.phone" class="mt-2 text-xs text-red-600">{{ form.errors.phone }}</p>
                    <button type="submit" :disabled="form.processing || whatsappAccounts.length === 0 || !form.jid"
                        class="mt-3 px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 disabled:opacity-50">
                        {{ form.processing ? 'Adding...' : (form.jid ? '+ Add Consent & Check Now' : '↑ Pilih kontak dulu') }}
                    </button>
                </div>
            </form>
        </div>

        <!-- Consents list -->
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Consented Contacts ({{ consents.length }})</h2>
            <div v-if="consents.length === 0" class="text-center py-8 text-gray-500">
                <p>Belum ada consent terdaftar.</p>
                <p class="text-xs mt-1">Tambahkan kontak yang sudah explicit consent di form di atas.</p>
            </div>
            <div v-else class="space-y-3">
                <div v-for="c in consents" :key="c.id"
                    class="border border-gray-200 rounded-lg p-3"
                    :class="{ 'opacity-50': !c.is_active }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="font-medium text-gray-900 truncate">{{ c.display_name || c.jid }}</p>
                                <span v-if="c.is_active"
                                    class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-green-100 text-green-800">ACTIVE</span>
                                <span v-else
                                    class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-red-100 text-red-800">REVOKED</span>
                            </div>
                            <p class="text-xs text-gray-500 font-mono">{{ c.phone }} · {{ c.jid }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">
                                Method: {{ c.consent_method }} · Given: {{ c.conent_given_at || c.consent_given_at }}
                                <span v-if="c.consent_expires_at">· Expires: {{ c.consent_expires_at }}</span>
                            </p>
                            <p v-if="c.notes" class="text-xs text-gray-400 mt-0.5 italic">{{ c.notes }}</p>

                            <!-- Sample stats -->
                            <div v-if="stats[c.id]" class="mt-2 flex flex-wrap gap-3 text-xs">
                                <span class="text-gray-600">Samples: <strong>{{ stats[c.id].sample_count }}</strong></span>
                                <span class="text-green-600">Online: <strong>{{ stats[c.id].online_samples }}</strong></span>
                                <span class="text-yellow-600">Recent: <strong>{{ stats[c.id].recent_samples }}</strong></span>
                                <span v-if="stats[c.id].last_sample" class="text-gray-400">Last: {{ stats[c.id].last_sample }}</span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1 flex-shrink-0">
                            <button v-if="c.is_active" type="button" @click="checkNow(c.id)"
                                class="px-2 py-1 text-xs font-medium text-green-700 bg-green-50 rounded hover:bg-green-100 border border-green-200">
                                Check Now
                            </button>
                            <button v-if="c.is_active" type="button" @click="revoke(c.id)"
                                class="px-2 py-1 text-xs font-medium text-orange-700 bg-orange-50 rounded hover:bg-orange-100 border border-orange-200">
                                Revoke
                            </button>
                            <button type="button" @click="forceDelete(c.id, c.display_name || c.jid)"
                                class="px-2 py-1 text-xs font-medium text-red-700 bg-red-50 rounded hover:bg-red-100 border border-red-200">
                                Delete Data
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
