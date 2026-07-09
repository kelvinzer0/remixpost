<?php

namespace App\Services\Publishers;

use App\Services\MediaType;
use Exception;
use GuzzleHttp\Client;

/**
 * Mastodon Publisher — posts toots to any Mastodon instance.
 *
 * Authentication: OAuth 2.0 with access_token. Each Mastodon instance (e.g.
 * mastodon.social, mas.to) has its own OAuth app — we auto-register one via
 * POST /api/v1/apps when user connects (see SocialAccountController::connectMastodon).
 *
 * The instance URL is stored per-account in metadata.instance_url. This allows
 * a user to connect multiple Mastodon accounts on different instances.
 *
 * API endpoints (per instance):
 *   - POST {instance}/api/v1/media       (upload v1 media, returns id)
 *   - POST {instance}/api/v1/statuses    (publish status with optional media_ids)
 *   - POST {instance}/api/v2/media       (upload v2 media, async processing)
 *
 * Reference: https://docs.joinmastodon.org/api/
 *
 * @license Apache-2.0 (implemented from official API docs)
 */
class MastodonPublisher implements PublisherInterface
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
            $accessToken = $account['access_token'];

            $metadata = is_string($account['metadata'] ?? null)
                ? json_decode($account['metadata'], true) ?? []
                : ($account['metadata'] ?? []);
            $instance = $metadata['instance_url']
                ?? $account['instance_url']
                ?? config('services.mastodon.url', 'https://mastodon.social');

            $instance = rtrim($instance, '/');
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];
            $tags = $post['tags'] ?? [];

            // Append tags as #hashtags to content
            if (!empty($tags)) {
                $tagStr = implode(' ', array_map(fn($t) => '#' . preg_replace('/[^a-zA-Z0-9_]/', '', $t), $tags));
                $content = rtrim($content) . "\n\n" . $tagStr;
            }

            // Mastodon toot limit is 500 chars by default (instance-dependent).
            // Truncate to 500 to be safe across instances.
            if (mb_strlen($content) > 500) {
                $content = mb_substr($content, 0, 497) . '...';
            }

            // Upload media if any
            $mediaIds = [];
            foreach ($mediaUrls as $url) {
                $mediaId = $this->uploadMedia($url, $instance, $accessToken);
                if ($mediaId) {
                    $mediaIds[] = $mediaId;
                }
            }

            // Post status. Mastodon API expects media_ids as a JSON array,
            // NOT form_params with media_ids[] key (that's PHP-specific syntax).
            $payload = [
                'status' => $content,
                'visibility' => 'public',
            ];
            if (!empty($mediaIds)) {
                $payload['media_ids'] = $mediaIds;
            }

            $response = $this->httpClient->post("{$instance}/api/v1/statuses", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!isset($body['id'])) {
                $err = $body['error'] ?? 'Mastodon did not return status ID';
                return ['success' => false, 'error' => $err, 'response' => $body];
            }

            return [
                'success' => true,
                'external_id' => $body['id'],
                'url' => $body['url'] ?? null,
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $resp = $e->getResponse();
            $body = $resp ? json_decode($resp->getBody()->getContents(), true) : null;
            $status = $resp ? $resp->getStatusCode() : null;
            $err = $body['error'] ?? $e->getMessage();

            if ($status === 401) {
                $err .= ' — Mastodon token is invalid or revoked. Disconnect and re-connect the account.';
            } elseif ($status === 413) {
                $err .= ' — Media file is too large for this Mastodon instance.';
            } elseif ($status === 422 && stripos($err, 'media') !== false) {
                $err .= ' — Media type not supported by this Mastodon instance.';
            }

            return [
                'success' => false,
                'error' => "Mastodon API {$status} error: {$err}",
                'status' => $status,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Upload media to Mastodon instance.
     * Uses v1 media endpoint (synchronous — returns id ready to attach).
     * For videos > 40MB, instances may require v2 (async) — fall back automatically.
     */
    private function uploadMedia(string $url, string $instance, string $accessToken): ?string
    {
        try {
            $mediaResponse = $this->httpClient->get($url);
            $mediaData = $mediaResponse->getBody()->getContents();
            $mimeType = $mediaResponse->getHeaderLine('Content-Type') ?: 'application/octet-stream';

            // Determine file extension from mime or URL
            $extension = $this->getExtensionFromMime($mimeType);
            if ($extension === 'bin') {
                $extension = MediaType::extension($url) ?: 'bin';
            }

            // Try v1 first (synchronous, simpler)
            $uploadResponse = $this->httpClient->post("{$instance}/api/v1/media", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => $mediaData,
                        'filename' => 'media.' . $extension,
                    ],
                ],
            ]);

            $body = json_decode($uploadResponse->getBody()->getContents(), true);
            $mediaId = $body['id'] ?? null;

            if (!$mediaId) {
                return null;
            }

            // For v1 media, we should wait for processing to complete (especially for videos).
            // Poll GET /api/v1/media/{id} until 'processing' status is 'completed'.
            // v1 endpoint auto-processes synchronously, so this is usually instant.
            $this->waitForMediaProcessing($instance, $accessToken, $mediaId);

            return $mediaId;
        } catch (Exception $e) {
            // Fall back to v2 endpoint for large media
            try {
                return $this->uploadMediaV2($url, $instance, $accessToken);
            } catch (Exception $e2) {
                return null;
            }
        }
    }

    /**
     * Upload media via v2 endpoint (async processing, supports larger files).
     * After upload, poll GET /api/v1/media/{id} until processing is complete.
     */
    private function uploadMediaV2(string $url, string $instance, string $accessToken): ?string
    {
        $mediaResponse = $this->httpClient->get($url);
        $mediaData = $mediaResponse->getBody()->getContents();
        $mimeType = $mediaResponse->getHeaderLine('Content-Type') ?: 'application/octet-stream';
        $extension = $this->getExtensionFromMime($mimeType);
        if ($extension === 'bin') {
            $extension = MediaType::extension($url) ?: 'bin';
        }

        $uploadResponse = $this->httpClient->post("{$instance}/api/v2/media", [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => $mediaData,
                    'filename' => 'media.' . $extension,
                ],
            ],
        ]);

        $body = json_decode($uploadResponse->getBody()->getContents(), true);
        $mediaId = $body['id'] ?? null;

        if (!$mediaId) {
            return null;
        }

        // v2 is async — must poll until processing completes
        $this->waitForMediaProcessing($instance, $accessToken, $mediaId);

        return $mediaId;
    }

    /**
     * Poll GET /api/v1/media/{id} until processing completes (max 60s).
     * For v1 uploads this returns immediately; for v2 it may take a few seconds.
     */
    private function waitForMediaProcessing(string $instance, string $accessToken, string $mediaId): void
    {
        $maxAttempts = 12; // 12 × 5s = 60s
        for ($i = 0; $i < $maxAttempts; $i++) {
            try {
                $resp = $this->httpClient->get("{$instance}/api/v1/media/{$mediaId}", [
                    'headers' => ['Authorization' => 'Bearer ' . $accessToken],
                ]);
                $body = json_decode($resp->getBody()->getContents(), true);
                $processing = $body['processing'] ?? 'unknown';

                // v1 returns no 'processing' field (synchronous — already done)
                if (!isset($body['processing'])) {
                    return;
                }
                if ($processing === 'completed') {
                    return;
                }
                if ($processing === 'failed') {
                    return; // can't recover, just continue — status post may still succeed
                }
                sleep(5);
            } catch (Exception $e) {
                // Network error — wait and retry
                sleep(5);
            }
        }
    }

    private function getExtensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            default => 'bin',
        };
    }
}
