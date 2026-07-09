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
            $targetType = $metadata['target_type'] ?? 'user';
            $target = $metadata['target'] ?? '';

            if (!$evoUrl || !$instance || !$apiKey) {
                return [
                    'success' => false,
                    'error' => 'WhatsApp Evolution API config missing. Re-connect the account.',
                ];
            }

            $headers = [
                'apikey' => $apiKey,
                'Content-Type' => 'application/json',
            ];

            // Build request based on target type
            if ($targetType === 'story') {
                // Story/status — only media supported
                if (empty($mediaUrls)) {
                    return [
                        'success' => false,
                        'error' => 'WhatsApp story requires at least one image or video.',
                    ];
                }
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
     * Send story/status (only media supported).
     */
    private function sendStory(string $evoUrl, string $instance, array $headers, string $caption, array $mediaUrls): array
    {
        $mediaUrl = $mediaUrls[0];
        $mediaType = MediaType::fromUrl($mediaUrl);

        $evoMediaType = match ($mediaType) {
            'image' => 'image',
            'video' => 'video',
            default => 'image',
        };

        $payload = [
            'type' => $evoMediaType,
            'content' => $mediaUrl,
            'caption' => $caption,
        ];

        $response = $this->httpClient->post("{$evoUrl}/message/sendStatusMessage/{$instance}", [
            'headers' => $headers,
            'json' => $payload,
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        $messageId = $body['key']['id'] ?? ($body['messageId'] ?? null);

        if (!$messageId) {
            return ['success' => false, 'error' => 'Evolution API did not return message ID', 'response' => $body];
        }

        return [
            'success' => true,
            'external_id' => $messageId,
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
