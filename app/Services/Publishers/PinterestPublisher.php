<?php

namespace App\Services\Publishers;

use App\Services\MediaType;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Pinterest Publisher — creates pins on Pinterest boards via API v5.
 *
 * Authentication: OAuth 2.0 with access_token.
 * Required scopes: boards:read, boards:write, pins:read, pins:write, user_accounts:read
 *
 * API endpoints:
 *   - GET  https://api.pinterest.com/v5/boards (list boards — for board picker)
 *   - POST https://api.pinterest.com/v5/media  (register media upload for video)
 *   - POST {upload_url returned by /v5/media} (multipart upload — NOT api.pinterest.com)
 *   - GET  https://api.pinterest.com/v5/media/{media_id} (poll until status='succeeded')
 *   - POST https://api.pinterest.com/v5/pins (create pin)
 *
 * Reference:
 *   https://developers.pinterest.com/docs/api/v5/
 *   Pattern adapted from Postiz (github.com/gitroomhq/postiz-app) — verified working.
 *
 * Supported media:
 *   - Image (single):  media_source.source_type=image_url
 *   - Image (multiple): media_source.source_type=multiple_image_urls
 *   - Video:            media_source.source_type=video_id with media_id from /v5/media flow
 *                      + optional cover_image_url (Pinterest fetches it server-side)
 *
 * Note: Pinterest requires a board_id for each pin. The numeric board_id is stored
 * in SocialAccount.provider_id when user connects via the SelectPinterestBoard flow.
 *
 * @license Apache-2.0 (pattern adapted from Postiz open-source project)
 */
class PinterestPublisher implements PublisherInterface
{
    private Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 600, // 10 min for video upload + status polling
            'connect_timeout' => 10,
            'base_uri' => 'https://api.pinterest.com',
        ]);
    }

    public function publish(array $post, array $account): array
    {
        try {
            $accessToken = $account['access_token'];
            $boardId = $account['provider_id']; // numeric board ID
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

            // Categorize media into videos + images
            $videoUrl = null;
            $imageUrls = [];
            foreach ($mediaUrls as $url) {
                if (MediaType::fromUrl($url) === 'video') {
                    if ($videoUrl === null) {
                        $videoUrl = $url;
                    }
                    // Pinterest only supports one video per pin — extra videos ignored
                } else {
                    $imageUrls[] = $url;
                }
            }

            $pinData = [
                'board_id' => $boardId,
                'title' => $title,
                'description' => $description,
            ];

            if ($videoUrl !== null) {
                // Video pin: register media upload → multipart upload → poll → publish
                // The cover image (first image URL, if any) becomes the pin thumbnail.
                $coverImageUrl = !empty($imageUrls) ? $imageUrls[0] : null;
                $mediaId = $this->uploadVideo($videoUrl, $accessToken);

                if (!$mediaId) {
                    return [
                        'success' => false,
                        'error' => 'Pinterest video upload failed. Make sure your Pinterest token has pins:write scope (disconnect and re-connect to refresh scopes if needed).',
                    ];
                }

                $pinData['media_source'] = [
                    'source_type' => 'video_id',
                    'media_id' => $mediaId,
                ];
                // Pinterest requires a cover image for video pins — they fetch it server-side.
                // If user didn't attach an image, we omit cover_image_url and Pinterest
                // will auto-generate one from the video's first frame.
                if ($coverImageUrl) {
                    $pinData['media_source']['cover_image_url'] = $coverImageUrl;
                }
            } elseif (count($imageUrls) === 1) {
                // Single image pin
                $pinData['media_source'] = [
                    'source_type' => 'image_url',
                    'url' => $imageUrls[0],
                ];
            } else {
                // Multi-image pin (carousel)
                $pinData['media_source'] = [
                    'source_type' => 'multiple_image_urls',
                    'items' => array_map(fn($url) => ['url' => $url], $imageUrls),
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
            $status = $resp ? $resp->getStatusCode() : null;
            $err = $body['message'] ?? $body['error'] ?? $e->getMessage();

            // Map common API errors to user-friendly messages
            if ($status === 401 || stripos($err, 'permission') !== false) {
                $err .= ' — Disconnect this Pinterest account and re-connect to refresh scopes (pins:write is required for video upload).';
            } elseif (stripos($err, 'cover_image_url') !== false) {
                $err = 'Pinterest video pin requires a cover image. Attach an image alongside the video.';
            } elseif (stripos($err, 'Board not found') !== false) {
                $err = 'Pinterest board not found. The board may have been deleted — disconnect and re-connect to pick a new board.';
            }

            return [
                'success' => false,
                'error' => $err,
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
     * Register + multipart upload video to Pinterest media API.
     *
     * Flow (adapted from Postiz pattern):
     *   1. POST /v5/media { media_type: 'video' } → returns { media_id, upload_url, upload_parameters }
     *   2. POST {upload_url} multipart/form-data with upload_parameters fields + file=video bytes
     *      (CRITICAL: upload goes to upload_url, NOT api.pinterest.com)
     *   3. Poll GET /v5/media/{media_id} every 30s until status='succeeded' (max ~9 min)
     *
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
        $uploadParameters = $registerBody['upload_parameters'] ?? [];

        if (!$mediaId || !$uploadUrl) {
            Log::error('Pinterest media register failed', ['response' => $registerBody]);
            return null;
        }

        // Step 2: Download video bytes
        $videoResponse = $this->httpClient->get($videoUrl);
        $videoData = $videoResponse->getBody()->getContents();

        if (empty($videoData)) {
            Log::error('Pinterest video download failed', ['url' => $videoUrl]);
            return null;
        }

        // Step 3: Upload to upload_url (NOT api.pinterest.com) as multipart/form-data
        // Build multipart body with all upload_parameters + file field
        $multipart = [];
        foreach ($uploadParameters as $key => $value) {
            if ($value !== null && $value !== '') {
                $multipart[] = ['name' => $key, 'contents' => (string)$value];
            }
        }
        $multipart[] = [
            'name' => 'file',
            'contents' => $videoData,
            'filename' => 'video.mp4',
            'headers' => ['Content-Type' => 'video/mp4'],
        ];

        // Upload to upload_url (different host — typically Pinterest's S3 bucket)
        $uploadClient = new Client([
            'timeout' => 600,
            'connect_timeout' => 10,
        ]);
        $uploadResp = $uploadClient->post($uploadUrl, [
            'multipart' => $multipart,
        ]);

        if ($uploadResp->getStatusCode() !== 200 && $uploadResp->getStatusCode() !== 201 && $uploadResp->getStatusCode() !== 204) {
            Log::error('Pinterest upload failed', [
                'status' => $uploadResp->getStatusCode(),
                'body' => $uploadResp->getBody()->getContents(),
            ]);
            return null;
        }

        // Step 4: Poll processing status every 30s, max 18 attempts (~9 min)
        for ($i = 0; $i < 18; $i++) {
            sleep(30);
            $statusResp = $this->httpClient->get("/v5/media/{$mediaId}", [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            ]);
            $statusBody = json_decode($statusResp->getBody()->getContents(), true);
            $status = $statusBody['status'] ?? 'unknown';

            if ($status === 'succeeded') {
                return $mediaId;
            }
            if ($status === 'failed') {
                Log::error('Pinterest video processing failed', ['media_id' => $mediaId, 'response' => $statusBody]);
                return null;
            }
            // else: 'processing', 'registered', etc. — keep polling
        }

        // Timeout — return media_id anyway, Pinterest may finish processing async.
        // If pin creation fails because of unfinished processing, the user will see
        // the error and can retry.
        Log::warning('Pinterest video processing timed out', ['media_id' => $mediaId]);
        return $mediaId;
    }
}
