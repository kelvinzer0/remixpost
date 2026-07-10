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

        $response = $this->httpClient->post("{$evoUrl}/message/sendText/{$instance}", [
            'headers' => $headers,
            'json' => $payload,
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        $messageId = $body['key']['id'] ?? null;

        if (!$messageId) {
            return ['success' => false, 'error' => 'Evolution API did not return message ID', 'response' => $body];
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
            'media' => [
                'url' => $mediaUrl,
            ],
            'caption' => $caption,
            'options' => [
                'delay' => 1000,
            ],
        ];

        $response = $this->httpClient->post("{$evoUrl}/message/sendMedia/{$instance}", [
            'headers' => $headers,
            'json' => $payload,
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        $messageId = $body['key']['id'] ?? null;

        if (!$messageId) {
            return ['success' => false, 'error' => 'Evolution API did not return message ID', 'response' => $body];
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
     * (NOT /message/sendStatusMessage/{instance} — that route doesn't exist)
     *
     * Body schema (camelCase, NOT PascalCase — `StatusJidList` fails with
     * "StatusJidList is required" because the validator only recognizes
     * `statusJidList`):
     *   - type: 'text' | 'image' | 'video' | 'audio' | 'document'
     *   - content: text content (for text) OR media URL (for media)
     *   - caption: optional caption for media
     *   - statusJidList: array of JIDs (required if allContacts is not true)
     *   - allContacts: bool (true = broadcast to all contacts)
     *   - For text type ONLY: backgroundColor (hex), fontColor (hex), font (int 1+)
     *
     * We default to `allContacts: true` for story broadcasts — that's the
     * typical use case (post to all my contacts' story feed).
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
                'allContacts' => true,
            ];
        } else {
            // Text-only story (requires backgroundColor, fontColor, font)
            $payload = [
                'type' => 'text',
                'content' => mb_substr($caption, 0, 500), // text content
                'allContacts' => true,
                'backgroundColor' => '#008069', // WhatsApp green
                'fontColor' => '#FFFFFF',
                'font' => 1, // 1=Arial (font index, must be ≥1 — 0 fails @IsNotEmpty)
            ];
        }

        try {
            $response = $this->httpClient->post("{$evoUrl}/message/sendStatus/{$instance}", [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => 60, // story sends can take a while (image upload)
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // Guzzle throws on non-2xx. Capture the response body if present.
            $resp = $e->getResponse();
            $body = $resp ? $resp->getBody()->getContents() : '';
            return [
                'success' => false,
                'error' => 'Evolution API sendStatus failed: ' . $e->getMessage() . " | body: {$body}",
            ];
        }

        $body = json_decode($response->getBody()->getContents(), true);

        // Success response shape: {"key":{"id":"...","remoteJid":"status@broadcast",...},"status":"PENDING",...}
        $messageId = $body['key']['id'] ?? null;

        if (!$messageId) {
            return ['success' => false, 'error' => 'Evolution API did not return message ID', 'response' => $body];
        }

        return [
            'success' => true,
            'external_id' => $messageId,
            'info' => 'Story broadcast to all contacts',
        ];
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
