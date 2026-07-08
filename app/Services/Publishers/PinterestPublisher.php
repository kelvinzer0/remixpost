<?php

namespace App\Services\Publishers;

use Exception;
use GuzzleHttp\Client;

/**
 * Pinterest Publisher — creates pins on Pinterest boards via API v5.
 *
 * Authentication: OAuth 2.0 with access_token. Scope: boards:read, pins:write.
 *
 * API endpoints:
 *   - GET  https://api.pinterest.com/v5/boards (list boards — for board selection)
 *   - POST https://api.pinterest.com/v5/pins (create pin with image_url or video_id)
 *   - POST https://api.pinterest.com/v5/media (register media upload — for video)
 *
 * Reference: https://developers.pinterest.com/docs/api/v5/
 *
 * Supported media:
 *   - Image: single image per pin (standard pin) via media_source.source_type=image_url
 *   - Video: video pin via media upload flow (register → upload → poll → publish)
 *
 * Note: Pinterest requires a board_id for each pin. The board_id is stored
 * in SocialAccount.provider_id when user connects a Pinterest account.
 *
 * @license Apache-2.0 (implemented from official API docs, not derived from third-party code)
 */
class PinterestPublisher implements PublisherInterface
{
    private Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 120, // longer for video upload
            'connect_timeout' => 10,
            'base_uri' => 'https://api.pinterest.com',
        ]);
    }

    public function publish(array $post, array $account): array
    {
        try {
            $accessToken = $account['access_token'];
            $boardId = $account['provider_id']; // Pinterest board ID (numeric string)
            $title = mb_substr($post['content'], 0, 100);
            $description = mb_substr($post['content'], 0, 800);
            $mediaUrls = $post['media_urls'] ?? [];

            // Validate board_id — must be numeric string (Pinterest board IDs are numeric)
            if (empty($boardId) || !preg_match('/^\d+$/', $boardId)) {
                return [
                    'success' => false,
                    'error' => "Pinterest board_id is invalid (got: '{$boardId}'). Disconnect the account and re-connect it — older connections stored username instead of board ID.",
                ];
            }

            // Pinterest requires at least one media item (image OR video)
            if (empty($mediaUrls)) {
                return [
                    'success' => false,
                    'error' => 'Pinterest requires at least one image or video in the post.',
                ];
            }

            // Pick first media URL — Pinterest supports single media per pin (standard pin)
            $mediaUrl = $mediaUrls[0];
            $isVideo = \App\Services\MediaType::fromUrl($mediaUrl) === 'video';

            $pinData = [
                'board_id' => $boardId,
                'title' => $title,
                'description' => $description,
            ];

            if ($isVideo) {
                // Video pin: register media upload → upload → poll for processing → publish
                $mediaId = $this->uploadVideo($mediaUrl, $accessToken);
                if (!$mediaId) {
                    return ['success' => false, 'error' => 'Pinterest video upload failed. Make sure your Pinterest account has the media:write scope (re-connect to refresh scopes if needed).'];
                }
                $pinData['media_source'] = [
                    'source_type' => 'video_id',
                    'media_id' => $mediaId,
                ];
            } else {
                // Image pin (standard)
                $pinData['media_source'] = [
                    'source_type' => 'image_url',
                    'url' => $mediaUrl,
                ];
            }

            $response = $this->httpClient->post('/v5/pins', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $pinData,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!isset($body['id'])) {
                $err = $body['message'] ?? $body['error'] ?? 'Pinterest did not return pin ID';
                return ['success' => false, 'error' => $err, 'response' => $body];
            }

            return [
                'success' => true,
                'external_id' => $body['id'],
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // 4xx errors include API error details
            $resp = $e->getResponse();
            $body = $resp ? json_decode($resp->getBody()->getContents(), true) : null;
            return [
                'success' => false,
                'error' => $body['message'] ?? $body['error'] ?? $e->getMessage(),
                'status' => $resp ? $resp->getStatusCode() : null,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Register + upload video to Pinterest media API.
     * Returns media_id (string) or null on failure.
     */
    private function uploadVideo(string $videoUrl, string $accessToken): ?string
    {
        // Step 1: Register media upload
        $registerResp = $this->httpClient->post('/v5/media', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'media_type' => 'video',
            ],
        ]);

        $registerBody = json_decode($registerResp->getBody()->getContents(), true);
        $mediaId = $registerBody['media_id'] ?? null;
        $uploadUrl = $registerBody['upload_url'] ?? null;

        if (!$mediaId || !$uploadUrl) {
            return null;
        }

        // Step 2: Download video and upload to Pinterest's upload URL
        $videoResponse = $this->httpClient->get($videoUrl);
        $videoData = $videoResponse->getBody()->getContents();
        $videoMime = $videoResponse->getHeaderLine('Content-Type') ?: 'video/mp4';

        $this->httpClient->put($uploadUrl, [
            'headers' => [
                'Content-Type' => $videoMime,
                'Content-Length' => strlen($videoData),
            ],
            'body' => $videoData,
        ]);

        // Step 3: Poll for processing status (max 60s)
        for ($i = 0; $i < 30; $i++) {
            sleep(2);
            $statusResp = $this->httpClient->get("/v5/media/{$mediaId}", [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            ]);
            $statusBody = json_decode($statusResp->getBody()->getContents(), true);
            $status = $statusBody['status'] ?? 'unknown';

            if ($status === 'succeeded') {
                return $mediaId;
            }
            if (in_array($status, ['failed', 'cancelled'])) {
                return null;
            }
            // else: 'processing' or 'registered' — keep polling
        }

        // Timeout — return media_id anyway, Pinterest may finish processing async
        return $mediaId;
    }

    private function isVideoUrl(string $url): bool
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']);
    }
}
