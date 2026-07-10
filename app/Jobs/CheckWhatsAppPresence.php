<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\WhatsAppPresenceConsent;
use App\Models\WhatsAppPresenceSample;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Check WhatsApp presence for one consented contact.
 *
 * Approach: poll Evolution API /chat/findChats/{instance} (already used by
 * WhatsAppPublisher), find the chat matching consent.jid, extract
 * lastMessage.messageTimestamp. Store as a WhatsAppPresenceSample.
 *
 * Sample.status:
 *   - 'online' if last message within 5 minutes (likely actively using WA)
 *   - 'recent' if within 1 hour (recently active)
 *   - 'offline' otherwise
 *
 * We DO NOT use RTT probing (sending fake delete/reaction requests to
 * measure response time) because:
 *   1. Evolution API v2.3.7 has no endpoint for that
 *   2. RTT probing sends probe messages to the target without their
 *      knowledge per check — even with consent, that's spammy
 *   3. Last-message-timestamp gives a good enough approximation: if
 *      the contact messaged at 9:05 AM, they were definitely active
 *      at 9:05 AM. Less granular than RTT but more ethical.
 *
 * Sampling rate is controlled by the scheduler (default: every 30 min).
 * Higher frequency = more accurate heatmap but more API calls.
 */
class CheckWhatsAppPresence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;
    public int $backoff = 30;

    public function __construct(
        public int $consentId
    ) {}

    public function handle(): void
    {
        $consent = WhatsAppPresenceConsent::with('socialAccount')->find($this->consentId);
        if (!$consent) {
            Log::warning("CheckWhatsAppPresence: consent not found", ['consent_id' => $this->consentId]);
            return;
        }

        if (!$consent->is_active) {
            Log::info("CheckWhatsAppPresence: consent revoked, skipping", ['jid' => $consent->jid]);
            return;
        }

        if ($consent->consent_expires_at && $consent->consent_expires_at->isPast()) {
            Log::info("CheckWhatsAppPresence: consent expired, skipping", ['jid' => $consent->jid]);
            return;
        }

        $account = $consent->socialAccount;
        if (!$account || $account->provider !== 'whatsapp') {
            Log::warning("CheckWhatsAppPresence: account invalid", ['consent_id' => $consent->id]);
            return;
        }

        $metadata = is_string($account->metadata) ? json_decode($account->metadata, true) : ($account->metadata ?? []);
        $evoUrl = rtrim($metadata['evo_url'] ?? '', '/');
        $instance = $metadata['instance'] ?? '';
        $apiKey = $account->access_token;

        if (!$evoUrl || !$instance || !$apiKey) {
            Log::warning("CheckWhatsAppPresence: missing evo config", ['consent_id' => $consent->id]);
            return;
        }

        // Fetch all chats (we know this endpoint works on v2.3.7)
        try {
            $response = Http::withHeaders([
                'apikey' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post("{$evoUrl}/chat/findChats/{$instance}", []);

            if (!$response->ok()) {
                Log::warning("CheckWhatsAppPresence: API error", [
                    'consent_id' => $consent->id,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 200),
                ]);
                return;
            }

            $data = $response->json();
            $chats = is_array($data) && isset($data[0]) ? $data : ($data['chats'] ?? []);

            // Find the chat matching our consented JID
            $targetChat = null;
            foreach ($chats as $chat) {
                if (($chat['remoteJid'] ?? null) === $consent->jid) {
                    $targetChat = $chat;
                    break;
                }
            }

            if (!$targetChat) {
                // No chat history with this JID — record as 'unknown'
                WhatsAppPresenceSample::create([
                    'consent_id' => $consent->id,
                    'social_account_id' => $account->id,
                    'jid' => $consent->jid,
                    'status' => 'unknown',
                    'last_seen_at' => null,
                    'sampled_at' => now(),
                ]);
                return;
            }

            // Extract lastMessage timestamp
            $lastMsg = $targetChat['lastMessage'] ?? null;
            $lastSeenAt = null;
            if ($lastMsg) {
                // lastMessage.messageTimestamp is Unix seconds (string or int)
                $ts = $lastMsg['messageTimestamp'] ?? ($lastMsg['timestamp'] ?? null);
                if ($ts) {
                    try {
                        $lastSeenAt = \Carbon\Carbon::createFromTimestamp((int) $ts, config('app.timezone'));
                    } catch (\Exception $e) {
                        // Try as ISO string
                        try {
                            $lastSeenAt = \Carbon\Carbon::parse($ts, config('app.timezone'));
                        } catch (\Exception $e2) {
                            $lastSeenAt = null;
                        }
                    }
                }
            }

            // Determine status based on how recent the last message is
            $status = 'offline';
            if ($lastSeenAt) {
                $minutesAgo = $lastSeenAt->diffInMinutes(now());
                if ($minutesAgo <= 5) {
                    $status = 'online';
                } elseif ($minutesAgo <= 60) {
                    $status = 'recent';
                } else {
                    $status = 'offline';
                }
            }

            WhatsAppPresenceSample::create([
                'consent_id' => $consent->id,
                'social_account_id' => $account->id,
                'jid' => $consent->jid,
                'status' => $status,
                'last_seen_at' => $lastSeenAt,
                'sampled_at' => now(),
            ]);

            // Update display_name from chat — try chat-level pushName first,
            // then lastMessage.pushName (which is where Evolution API v2.3.7
            // actually stores it for most chats), then phone as last resort.
            if (!$consent->display_name) {
                $name = $targetChat['pushName']
                    ?? ($targetChat['lastMessage']['pushName'] ?? null);
                // pushName 'Você' (Portuguese for 'You') is what WhatsApp
                // returns for messages sent BY the user — useless as contact
                // name. Skip it and fall back to phone.
                if ($name && strtolower($name) !== 'você' && trim($name) !== '') {
                    $consent->update(['display_name' => $name]);
                }
            }

            Log::info("CheckWhatsAppPresence: sample stored", [
                'consent_id' => $consent->id,
                'jid' => $consent->jid,
                'status' => $status,
                'last_seen_at' => $lastSeenAt?->toIso8601String(),
            ]);

        } catch (\Exception $e) {
            Log::error("CheckWhatsAppPresence: exception", [
                'consent_id' => $consent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
