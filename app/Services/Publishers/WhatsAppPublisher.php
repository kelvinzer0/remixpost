<?php

namespace App\Services\Publishers;

use App\Services\MediaType;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Publisher — sends messages via Evolution API (Baileys-based).
 *
 * Evolution API is a REST wrapper around the Baileys WhatsApp Web library.
 * It runs as a separate service (Docker container) and exposes REST endpoints
 * for sending messages to WhatsApp users, groups, channels, and stories.
 *
 * Authentication: API key (configured in Evolution API instance settings).
 *
 * API endpoints (Evolution API v2):
 *   - POST {base_url}/message/sendText/{instance}        — text message
 *   - POST {base_url}/message/sendMedia/{instance}        — image/video/document
 *   - POST {base_url}/message/sendStatusMessage/{instance} — story/status
 *
 * Target types stored in SocialAccount.metadata:
 *   - target_type: 'user' | 'group' | 'channel' | 'story'
 *   - target: phone number (user), JID (group/channel), or null (story)
 *
 * @license Apache-2.0
 */
class WhatsAppPublisher implements PublisherInterface
{
    private Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);
    }

    public function publish(array $post, array $account): array
    {
        try {
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];
            $tags = $post['tags'] ?? [];

            // Append tags as hashtags
            if (!empty($tags)) {
                $tagStr = implode(' ', array_map(fn($t) => '#' . preg_replace('/[^a-zA-Z0-9_]/', '', $t), $tags));
                $content = rtrim($content) . "\n\n" . $tagStr;
            }

            // Read Evolution API config from metadata
            $metadata = is_string($account['metadata'] ?? null)
                ? json_decode($account['metadata'], true) ?? []
                : ($account['metadata'] ?? []);

            $evoUrl = rtrim($metadata['evo_url'] ?? '', '/');
            $instance = $metadata['instance'] ?? '';
            $apiKey = $account['access_token']; // API key stored as access_token

            // Per-post target selection (chosen in Create Post page).
            // Overrides are stored as { "accountId": { "target_type": "...", "target": "..." } }.
            $accountId = (string) ($post['account_id'] ?? $account['id'] ?? '');
            $overrides = $post['account_overrides'] ?? [];
            $perPost = $overrides[$accountId] ?? [];

            // Fallback to legacy account metadata (for any WhatsApp accounts
            // connected before the per-post picker shipped)
            $targetType = $perPost['target_type']
                ?? $metadata['target_type']
                ?? null;
            // Coerce null target to empty string — frontend sometimes sends
            // `target: null` when the user picks Story (no target needed),
            // and PHP's ?? operator passes null through, which would later
            // fail strict empty() checks.
            $target = (string) ($perPost['target']
                ?? $metadata['target']
                ?? '');

            if (!$evoUrl || !$instance || !$apiKey) {
                return [
                    'success' => false,
                    'error' => 'WhatsApp Evolution API config missing. Re-connect the account.',
                ];
            }

            if (!$targetType || !in_array($targetType, ['user', 'group', 'channel', 'story'])) {
                return [
                    'success' => false,
                    'error' => 'Pilih target (User / Group / Channel / Story) untuk akun WhatsApp ini di form post.',
                ];
            }

            if ($targetType !== 'story' && empty($target)) {
                return [
                    'success' => false,
                    'error' => "Target kosong untuk tipe '{$targetType}'. Pilih dari list atau ketik manual.",
                ];
            }

            $headers = [
                'apikey' => $apiKey,
                'Content-Type' => 'application/json',
            ];

            // Build request based on target type
            if ($targetType === 'story') {
                // Story/status — media supported (image/video). Text-only story
                // also works (uses caption as text content). Either way, post to
                // status@broadcast via allContacts=true.
                return $this->sendStory($evoUrl, $instance, $headers, $content, $mediaUrls);
            }

            // For user/group/channel — determine if text-only or with media
            if (empty($mediaUrls)) {
                return $this->sendText($evoUrl, $instance, $headers, $target, $targetType, $content);
            } else {
                return $this->sendMedia($evoUrl, $instance, $headers, $target, $targetType, $content, $mediaUrls);
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $resp = $e->getResponse();
            $body = $resp ? $resp->getBody()->getContents() : '';
            return [
                'success' => false,
                'error' => "Evolution API error {$resp->getStatusCode()}: {$body}",
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send text message to user/group/channel.
     */
    private function sendText(string $evoUrl, string $instance, array $headers, string $target, string $targetType, string $content): array
    {
        $number = $this->resolveTarget($target, $targetType);

        $payload = [
            'number' => $number,
            'text' => $content,
            'options' => [
                'delay' => 1000,
            ],
        ];

        [$ok, $body, $errMsg] = $this->callEvolution(
            'POST',
            "{$evoUrl}/message/sendText/{$instance}",
            $headers,
            $payload,
            'text message'
        );

        if (!$ok) {
            return $errMsg; // either real error or success-with-warning
        }

        $decoded = json_decode($body, true);
        $messageId = $decoded['key']['id'] ?? null;

        if (!$messageId) {
            return ['success' => false, 'error' => 'Evolution API did not return message ID', 'response' => $decoded];
        }

        return [
            'success' => true,
            'external_id' => $messageId,
        ];
    }

    /**
     * Send media (image/video/document) with caption.
     */
    private function sendMedia(string $evoUrl, string $instance, array $headers, string $target, string $targetType, string $caption, array $mediaUrls): array
    {
        $number = $this->resolveTarget($target, $targetType);
        $mediaUrl = $mediaUrls[0]; // WhatsApp sends one media at a time
        $mediaType = MediaType::fromUrl($mediaUrl);

        // Determine mediatype for Evolution API
        $evoMediaType = match ($mediaType) {
            'image' => 'image',
            'video' => 'video',
            'document' => 'document',
            default => 'document',
        };

        $payload = [
            'number' => $number,
            'mediatype' => $evoMediaType,
            // Evolution API v2.3.7 expects `media` as a STRING (URL directly),
            // NOT an object. Sending {url: '...'} returns 400:
            //   "media is not of a type(s) string"
            'media' => $mediaUrl,
            'caption' => $caption,
            'options' => [
                'delay' => 1000,
            ],
        ];

        [$ok, $body, $errMsg] = $this->callEvolution(
            'POST',
            "{$evoUrl}/message/sendMedia/{$instance}",
            $headers,
            $payload,
            'media message'
        );

        if (!$ok) {
            return $errMsg;
        }

        $decoded = json_decode($body, true);
        $messageId = $decoded['key']['id'] ?? null;

        if (!$messageId) {
            return ['success' => false, 'error' => 'Evolution API did not return message ID', 'response' => $decoded];
        }

        return [
            'success' => true,
            'external_id' => $messageId,
        ];
    }

    /**
     * Send story/status.
     *
     * Evolution API v2 endpoint: POST /message/sendStatus/{instance}
     *
     * Body schema (camelCase, NOT PascalCase):
     *   - type: 'text' | 'image' | 'video' | 'audio' | 'document'
     *   - content: text content (for text) OR media URL (for media)
     *   - caption: optional caption for media
     *   - statusJidList: array of JIDs to broadcast to (PREFERRED)
     *   - allContacts: bool (FALLBACK — see below)
     *   - For text type ONLY: backgroundColor (hex), fontColor (hex), font (int 1+)
     *
     * BROADCAST STRATEGY:
     * We fetch all contacts first via /contact/fetchAllContacts/{instance},
     * then send with explicit `statusJidList`. This is more reliable than
     * `allContacts: true`, which empirically uploads the status to the
     * sender's phone but often skips the broadcast step — contacts never
     * see the status even though the sender sees it on their own WhatsApp.
     *
     * If contact fetch fails, we fall back to `allContacts: true` so at
     * least the status uploads (better than nothing).
     */
    private function sendStory(string $evoUrl, string $instance, array $headers, string $caption, array $mediaUrls): array
    {
        if (!empty($mediaUrls)) {
            // Media story (image/video)
            $mediaUrl = $mediaUrls[0];
            $mediaType = MediaType::fromUrl($mediaUrl);

            $evoMediaType = match ($mediaType) {
                'image' => 'image',
                'video' => 'video',
                default => 'image', // fallback for unknown types
            };

            $payload = [
                'type' => $evoMediaType,
                'content' => $mediaUrl,
                'caption' => $caption,
            ];
        } else {
            // Text-only story (requires backgroundColor, fontColor, font)
            $payload = [
                'type' => 'text',
                'content' => mb_substr($caption, 0, 500), // text content
                'backgroundColor' => '#008069', // WhatsApp green
                'fontColor' => '#FFFFFF',
                'font' => 1, // 1=Arial (font index, must be ≥1 — 0 fails @IsNotEmpty)
            ];
        }

        // Fetch all contacts from Evolution API to build an explicit
        // statusJidList. This is more reliable than `allContacts: true`
        // which empirically uploads the status to the sender's phone but
        // often skips the broadcast-to-contacts step (status shows on
        // sender's WhatsApp but contacts never see it).
        //
        // With explicit statusJidList, Evolution API is forced to iterate
        // each JID and broadcast the status — much higher success rate.
        $contactCount = 0;
        $contacts = $this->fetchAllContacts($evoUrl, $instance, $headers);
        if (!empty($contacts)) {
            // Limit to 256 JIDs (WhatsApp's broadcast limit per status)
            $jidList = array_slice($contacts, 0, 256);
            $payload['statusJidList'] = $jidList;
            $contactCount = count($jidList);
        } else {
            // Fallback: allContacts=true if we couldn't fetch the contact
            // list (better than nothing — at least the status uploads).
            $payload['allContacts'] = true;
        }

        [$ok, $body, $errMsg] = $this->callEvolution(
            'POST',
            "{$evoUrl}/message/sendStatus/{$instance}",
            $headers,
            $payload,
            'story',
            120 // longer timeout: image upload + per-contact broadcast
        );

        if (!$ok) {
            return $errMsg;
        }

        $decoded = json_decode($body, true);

        // Success response shape: {"key":{"id":"...","remoteJid":"status@broadcast",...},"status":"PENDING",...}
        $messageId = $decoded['key']['id'] ?? null;

        if (!$messageId) {
            return ['success' => false, 'error' => 'Evolution API did not return message ID', 'response' => $decoded];
        }

        $info = $contactCount > 0
            ? "Story broadcast to {$contactCount} contacts (explicit statusJidList)"
            : 'Story uploaded with allContacts=true (could not fetch contact list)';

        return [
            'success' => true,
            'external_id' => $messageId,
            'info' => $info,
        ];
    }

    /**
     * Fetch all 1:1 chat contacts from a connected Evolution API instance.
     *
     * Returns an array of JID strings (e.g. ["6281234567890@s.whatsapp.net", ...]).
     * Returns empty array on any failure — caller should fall back to allContacts=true.
     *
     * Endpoint: POST /chat/findChats/{instance} with empty JSON body
     *   (Evolution API v2.3+ uses POST for find endpoints; GET returns 404.)
     *
     * Response: array of chat objects, each with `remoteJid` field that holds
     *   the JID (e.g. "6281234567890@s.whatsapp.net" for 1:1 chats,
     *   "120363xxx@g.us" for groups, "status@broadcast" for status).
     *   We filter to only @s.whatsapp.net (skip groups, channels, broadcast,
     *   and lid-only contacts which can't receive status broadcasts).
     */
    private function fetchAllContacts(string $evoUrl, string $instance, array $headers): array
    {
        [$ok, $body, $errMsg] = $this->callEvolution(
            'POST',
            "{$evoUrl}/chat/findChats/{$instance}",
            $headers,
            [],
            'fetch contacts',
            30
        );

        if (!$ok) {
            // Don't fail the whole publish — just return empty so caller
            // falls back to allContacts=true.
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Response shape: array of chat objects (not nested under 'chats').
        $chats = isset($decoded[0]) ? $decoded : [];

        $jids = [];
        foreach ($chats as $c) {
            // Chat objects use `remoteJid` for the JID (NOT `id` — that's the
            // database row ID, often null).
            $jid = $c['remoteJid'] ?? null;
            // Only include 1:1 chat JIDs (skip groups @g.us, channels @newsletter,
            // status@broadcast, and @lid contacts which can't receive status).
            if ($jid && str_ends_with($jid, '@s.whatsapp.net')) {
                // Skip WhatsApp system contacts like "0@s.whatsapp.net"
                if ($jid === '0@s.whatsapp.net') {
                    continue;
                }
                $jids[] = $jid;
            }
        }

        return $jids;
    }

    /**
     * Centralized Evolution API caller.
     *
     * Returns a tuple: [$ok, $body, $errorResult]
     *   - On success: $ok=true, $body=response body string, $errorResult=null
     *   - On real HTTP error (4xx/5xx): $ok=false, $body='', $errorResult=['success'=>false,'error'=>...]
     *   - On timeout / connection error: $ok=false, $body='', $errorResult=[
     *        'success'=>true,
     *        'external_id'=>null,
     *        'warning'=>'Evolution API did not respond — message likely sent to WhatsApp, but not confirmed.'
     *     ]
     *
     * The timeout-as-success behavior is intentional: empirically, when the
     * WhatsApp session behind Evolution API is in a degraded state (e.g.
     * recently logged out, retrying connection, etc.), the API accepts the
     * send request, pushes the message to the WhatsApp network, but then
     * never returns a response — the HTTP request hangs forever. The
     * message DOES get delivered to recipients (verified by the user
     * seeing the story on their WhatsApp), so treating the timeout as a
     * failure is misleading. Marking it as a warning-success lets the
     * post show as "published" in the UI while still flagging that
     * confirmation was missing.
     *
     * @param string $method  HTTP method (always POST for send endpoints)
     * @param string $url     Full URL to call
     * @param array  $headers Headers including apikey
     * @param array  $payload JSON body
     * @param string $label   Human-readable description for error messages
     * @param int    $timeout Per-request timeout in seconds (default 60)
     */
    private function callEvolution(string $method, string $url, array $headers, array $payload, string $label, int $timeout = 60): array
    {
        try {
            $response = $this->httpClient->request($method, $url, [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => $timeout,
                'connect_timeout' => 10,
            ]);
            return [true, $response->getBody()->getContents(), null];
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            // Connection error or timeout (no HTTP response received).
            // Treat as success-with-warning — the message was likely sent.
            return [false, '', [
                'success' => true,
                'external_id' => null,
                'warning' => "Evolution API tidak merespons dalam {$timeout}s ({$label}). Pesan kemungkinan terkirim ke WhatsApp, tapi tidak dikonfirmasi oleh Evolution API.",
            ]];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $resp = $e->getResponse();
            if (!$resp) {
                // No response = connection-level failure (timeout, DNS, etc.)
                return [false, '', [
                    'success' => true,
                    'external_id' => null,
                    'warning' => "Evolution API tidak merespons ({$label}). Pesan kemungkinan terkirim ke WhatsApp, tapi tidak dikonfirmasi.",
                ]];
            }
            // Real HTTP error (4xx/5xx) — return as failure.
            $body = $resp->getBody()->getContents();
            return [false, '', [
                'success' => false,
                'error' => "Evolution API error {$resp->getStatusCode()} ({$label}): {$body}",
            ]];
        }
    }

    /**
     * Resolve target to WhatsApp JID format.
     * - User: phone number → add @s.whatsapp.net
     * - Group: JID already has @g.us
     * - Channel: JID already has @newsletter
     */
    private function resolveTarget(string $target, string $targetType): string
    {
        $target = trim($target);

        // If already a JID, return as-is
        if (str_contains($target, '@')) {
            return $target;
        }

        // User: phone number → add @s.whatsapp.net
        if ($targetType === 'user') {
            // Remove non-numeric characters
            $phone = preg_replace('/[^0-9]/', '', $target);
            return $phone . '@s.whatsapp.net';
        }

        // Group/channel: assume JID already complete
        return $target;
    }
}
