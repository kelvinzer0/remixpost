<?php

namespace App\Http\Controllers;

use App\Jobs\CheckWhatsAppPresence;
use App\Models\SocialAccount;
use App\Models\WhatsAppPresenceConsent;
use App\Models\WhatsAppPresenceSample;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WhatsAppPresenceController extends Controller
{
    /**
     * List all consents for the current user + show heatmap data.
     */
    public function index(Request $request)
    {
        $consents = WhatsAppPresenceConsent::with('socialAccount')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('is_active')
            ->orderByDesc('consent_given_at')
            ->get();

        // Sample counts + last sample time per consent
        $stats = [];
        foreach ($consents as $c) {
            $stats[$c->id] = [
                'sample_count' => $c->samples()->count(),
                'last_sample' => $c->samples()->latest('sampled_at')->value('sampled_at'),
                'online_samples' => $c->samples()->where('status', 'online')->count(),
                'recent_samples' => $c->samples()->where('status', 'recent')->count(),
            ];
        }

        // Aggregate heatmap: for each hour 0-23, count samples where the
        // contact was last seen in that hour. Uses last_seen_at (the actual
        // activity time, NOT sampled_at which is when we checked).
        $heatmap = $this->buildHeatmap($request->user()->id);

        return Inertia::render('WhatsAppPresence/Index', [
            'consents' => $consents,
            'stats' => $stats,
            'heatmap' => $heatmap,
            'whatsappAccounts' => $request->user()
                ->socialAccounts()
                ->where('provider', 'whatsapp')
                ->where('is_active', true)
                ->get(['id', 'name', 'username']),
        ]);
    }

    /**
     * Add a new consented contact.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'social_account_id' => 'required|exists:social_accounts,id',
            'jid' => 'required|string|max:100|ends_with:@s.whatsapp.net',
            'phone' => 'nullable|string|max:30',
            'display_name' => 'nullable|string|max:200',
            'consent_method' => 'required|string|in:manual_verbal,written,qr_scan,self_signup',
            'consent_expires_at' => 'nullable|date|after:now',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Verify social account belongs to user + is WhatsApp
        $account = SocialAccount::findOrFail($validated['social_account_id']);
        if ($account->user_id !== $request->user()->id || $account->provider !== 'whatsapp') {
            return back()->with('error', 'Invalid WhatsApp account.');
        }

        $jid = $validated['jid'];
        // Extract phone from JID if not provided
        $phone = $validated['phone'] ?? str_replace('@s.whatsapp.net', '', $jid);

        $consent = WhatsAppPresenceConsent::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'social_account_id' => $validated['social_account_id'],
                'jid' => $jid,
            ],
            [
                'phone' => $phone,
                'display_name' => $validated['display_name'] ?? null,
                'consent_method' => $validated['consent_method'],
                'consent_given_at' => now(),
                'is_active' => true,
                'consent_expires_at' => $validated['consent_expires_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]
        );

        // Trigger an immediate presence check so user sees data fast
        CheckWhatsAppPresence::dispatch($consent->id);

        return redirect()->route('whatsapp-presence.index')
            ->with('message', "Consent added for {$consent->display_name}. First presence check dispatched.");
    }

    /**
     * Revoke consent (soft delete — keeps sample history but stops further checks).
     */
    public function destroy(Request $request, int $id)
    {
        $consent = WhatsAppPresenceConsent::where('user_id', $request->user()->id)->findOrFail($id);
        $consent->update(['is_active' => false]);

        return back()->with('message', "Consent revoked for {$consent->display_name}. No further presence checks will run.");
    }

    /**
     * Permanently delete consent + all samples (GDPR-style erasure).
     */
    public function forceDelete(Request $request, int $id)
    {
        $consent = WhatsAppPresenceConsent::where('user_id', $request->user()->id)->findOrFail($id);
        $name = $consent->display_name ?? $consent->jid;
        $consent->delete(); // cascades to samples via foreign key

        return back()->with('message', "Consent and all sample data permanently deleted for {$name}.");
    }

    /**
     * Trigger an immediate presence check for a single consent (manual refresh button).
     */
    public function checkNow(Request $request, int $id)
    {
        $consent = WhatsAppPresenceConsent::where('user_id', $request->user()->id)->findOrFail($id);
        if (!$consent->is_active) {
            return back()->with('error', 'Cannot check — consent is revoked.');
        }
        CheckWhatsAppPresence::dispatch($consent->id);
        return back()->with('message', 'Presence check dispatched. Refresh in ~30s.');
    }

    /**
     * Get heatmap data as JSON (for calendar overlay + Create Post suggestion).
     * Returns array of 24 elements: [{ hour: 0, online: N, recent: N, total: N }, ...]
     */
    public function heatmap(Request $request)
    {
        return response()->json($this->buildHeatmap($request->user()->id));
    }

    /**
     * Fetch available WhatsApp contacts from a connected Evolution API
     * instance, EXCLUDING contacts that already have an active consent.
     *
     * Used by the Add Consent form to show a pickable list of contacts
     * (with profile pictures + pushName) instead of asking the user to
     * type a phone number manually.
     *
     * POST /whatsapp-presence/available-contacts
     * Body: { social_account_id: int }
     * Returns: { contacts: [{ jid, phone, name, picture, last_active_at }] }
     */
    public function availableContacts(Request $request)
    {
        $validated = $request->validate([
            'social_account_id' => 'required|exists:social_accounts,id',
        ]);

        $account = SocialAccount::findOrFail($validated['social_account_id']);
        if ($account->user_id !== $request->user()->id || $account->provider !== 'whatsapp') {
            return response()->json(['error' => 'Invalid WhatsApp account.'], 400);
        }

        $metadata = is_string($account->metadata) ? json_decode($account->metadata, true) : ($account->metadata ?? []);
        $evoUrl = rtrim($metadata['evo_url'] ?? '', '/');
        $instance = $metadata['instance'] ?? '';
        $apiKey = $account->access_token;

        if (!$evoUrl || !$instance || !$apiKey) {
            return response()->json(['error' => 'WhatsApp Evolution API config missing. Re-connect the account.'], 400);
        }

        // Fetch contacts via POST /chat/findContacts/{instance}
        // (Evolution API v2.3+ — returns proper pushName + isGroup + type fields,
        // unlike /chat/findChats which stores pushName inside lastMessage only
        // and is null at the chat level for most contacts.)
        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'apikey' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post("{$evoUrl}/chat/findContacts/{$instance}", []);

            if (!$response->ok()) {
                return response()->json([
                    'error' => "Evolution API error (HTTP {$response->status()}): " . substr($response->body(), 0, 200),
                ], 502);
            }

            $data = $response->json();
            $contactsRaw = is_array($data) && isset($data[0]) ? $data : ($data['contacts'] ?? []);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch contacts: ' . $e->getMessage()], 500);
        }

        // Get JIDs that already have a consent row for this account
        // (any state — active or revoked — so user doesn't re-add revoked ones
        // unless they explicitly force-delete first).
        $existingJids = WhatsAppPresenceConsent::where('social_account_id', $account->id)
            ->pluck('jid')
            ->toArray();

        // Build contact list — only 1:1 chats not already consented
        $contacts = [];
        foreach ($contactsRaw as $c) {
            $jid = $c['remoteJid'] ?? null;
            if (!$jid || !str_ends_with($jid, '@s.whatsapp.net')) continue;
            if ($jid === '0@s.whatsapp.net') continue; // skip WhatsApp system
            if (in_array($jid, $existingJids)) continue; // skip already consented
            // Skip groups (some contacts are tagged isGroup=true even with @s.whatsapp.net JID)
            if (!empty($c['isGroup'])) continue;

            $name = $c['pushName'] ?? null;
            // Use phone number as name fallback if pushName is null/empty
            if (!$name || trim($name) === '') {
                $name = str_replace('@s.whatsapp.net', '', $jid);
            }

            // Format last active time from updatedAt field
            $lastActive = null;
            if (!empty($c['updatedAt'])) {
                try {
                    $lastActive = \Carbon\Carbon::parse($c['updatedAt'])->toIso8601String();
                } catch (\Exception $e) {
                    // leave null
                }
            }

            $contacts[] = [
                'jid' => $jid,
                'phone' => str_replace('@s.whatsapp.net', '', $jid),
                'name' => $name,
                'picture' => $c['profilePicUrl'] ?? null,
                'last_active_at' => $lastActive,
                'is_saved' => !empty($c['isSaved']),
            ];
        }

        // Sort: saved contacts first, then by name alphabetically
        usort($contacts, function ($a, $b) {
            if ($a['is_saved'] !== $b['is_saved']) {
                return $b['is_saved'] ? 1 : -1;
            }
            return strcmp($a['name'], $b['name']);
        });

        return response()->json([
            'contacts' => $contacts,
            'total' => count($contacts),
            'excluded_already_consent' => count($existingJids),
        ]);
    }

    /**
     * Get top 3 recommended posting hours based on presence data.
     * Returns [{ hour: 9, score: 0.85, online_count: 12 }, ...]
     */
    public function recommend(Request $request)
    {
        $heatmap = $this->buildHeatmap($request->user()->id);
        $totalOnline = array_sum(array_column($heatmap, 'online'));
        $totalRecent = array_sum(array_column($heatmap, 'recent'));

        if ($totalOnline === 0 && $totalRecent === 0) {
            return response()->json([
                'recommendations' => [],
                'reason' => 'Belum ada data presence. Tambahkan consent kontak dan tunggu beberapa jam untuk sample terkumpul.',
            ]);
        }

        // Score each hour: weight online=1.0, recent=0.5
        $scored = [];
        foreach ($heatmap as $h) {
            $score = $h['online'] * 1.0 + $h['recent'] * 0.5;
            $scored[] = [
                'hour' => $h['hour'],
                'score' => $score,
                'online_count' => $h['online'],
                'recent_count' => $h['recent'],
                'total' => $h['total'],
            ];
        }

        // Sort by score desc, take top 3
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $top3 = array_slice($scored, 0, 3);

        // Normalize scores to 0-1
        $maxScore = max(array_column($top3, 'score')) ?: 1;
        foreach ($top3 as &$rec) {
            $rec['score_normalized'] = round($rec['score'] / $maxScore, 2);
        }

        return response()->json([
            'recommendations' => $top3,
            'total_samples' => $totalOnline + $totalRecent,
            'best_hour' => $top3[0]['hour'] ?? null,
        ]);
    }

    /**
     * Build the 24-hour heatmap of contact activity.
     *
     * For each hour 0-23, count samples grouped by last_seen_at hour.
     * Only counts samples from the last 30 days (rolling window).
     *
     * @return array  24 elements: [{ hour, online, recent, offline, total }]
     */
    private function buildHeatmap(int $userId): array
    {
        $consentIds = WhatsAppPresenceConsent::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('id');

        if ($consentIds->isEmpty()) {
            return array_map(fn($h) => [
                'hour' => $h, 'online' => 0, 'recent' => 0, 'offline' => 0, 'total' => 0,
            ], range(0, 23));
        }

        // Group samples by HOUR(last_seen_at). Only count samples that have a
        // last_seen_at (status='online' or 'recent' or 'offline' with timestamp).
        $rows = DB::table('whatsapp_presence_samples')
            ->select(
                DB::raw('HOUR(last_seen_at) as hour'),
                'status',
                DB::raw('COUNT(*) as count')
            )
            ->whereIn('consent_id', $consentIds)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subDays(30))
            ->groupBy(DB::raw('HOUR(last_seen_at)'), 'status')
            ->get();

        $heatmap = [];
        for ($h = 0; $h < 24; $h++) {
            $heatmap[$h] = [
                'hour' => $h,
                'online' => 0,
                'recent' => 0,
                'offline' => 0,
                'total' => 0,
            ];
        }
        foreach ($rows as $r) {
            $hour = (int) $r->hour;
            if ($hour >= 0 && $hour < 24) {
                $heatmap[$hour][$r->status] = (int) $r->count;
                $heatmap[$hour]['total'] += (int) $r->count;
            }
        }

        return $heatmap;
    }
}
