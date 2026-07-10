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
    phone: '',
    display_name: '',
    consent_method: 'manual_verbal',
    consent_expires_at: '',
    notes: '',
});

const submit = () => {
    form.post('/whatsapp-presence', {
        onSuccess: () => form.reset(),
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
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">WA Account (instance)</label>
                            <select v-model="form.social_account_id" required
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-green-500 focus:ring-green-500">
                                <option v-for="a in whatsappAccounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Phone Number (format: 628xxx)</label>
                            <input v-model="form.phone" type="text" required
                                placeholder="6281234567890"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-green-500 focus:ring-green-500" />
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Display Name (opsional)</label>
                            <input v-model="form.display_name" type="text"
                                placeholder="Budi Customer Looyal"
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
                    <button type="submit" :disabled="form.processing || whatsappAccounts.length === 0"
                        class="mt-3 px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 disabled:opacity-50">
                        {{ form.processing ? 'Adding...' : '+ Add Consent & Check Now' }}
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
