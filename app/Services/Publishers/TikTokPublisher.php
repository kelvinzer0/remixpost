<?php

namespace App\Services\Publishers;

use App\Services\MediaType;
use Exception;
use GuzzleHttp\Client;

/**
 * TikTok Publisher — uploads videos + photo carousels via Content Posting API.
 *
 * Authentication: OAuth 2.0 with access_token. Scope: video.publish.
 *
 * API endpoints:
 *   Video:
 *     - POST https://open.tiktokapis.com/v2/post/publish/video/init/ (init upload)
 *     - PUT {upload_url} (upload video data)
 *     - POST https://open.tiktokapis.com/v2/post/publish/status/fetch/ (check status)
 *
 *   Photo (image carousel):
 *     - POST https://open.tiktokapis.com/v2/post/publish/content/init/ (init photo post)
 *       with post_mode: DIRECT_POST, media_type: IMAGE
 *       + source_info.post_image_urls: [public image URLs]
 *
 * Reference:
 *   Video: https://developers.tiktok.com/doc/content-posting-api-direct-post-video
 *   Photo: https://developers.tiktok.com/doc/content-posting-api-direct-post-photo
 *
 * Note: TikTok Content Posting API requires app approval for production use.
 *
 * @license Apache-2.0
 */
class TikTokPublisher implements PublisherInterface
{
    private Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 300,
            'connect_timeout' => 10,
        ]);
    }

    public function publish(array $post, array $account): array
    {
        try {
            $accessToken = $account['access_token'];
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];
            $tags = $post['tags'] ?? [];

            // Append tags to caption
            if (!empty($tags)) {
                $tagStr = implode(' ', array_map(fn($t) => '#' . preg_replace('/[^a-zA-Z0-9_]/', '', $t), $tags));
                $content = rtrim($content) . "\n\n" . $tagStr;
            }

            // Categorize media
            $imageUrls = [];
            $videoUrls = [];
            foreach ($mediaUrls as $url) {
                if (MediaType::fromUrl($url) === 'video') {
                    $videoUrls[] = $url;
                } else {
                    $imageUrls[] = $url;
                }
            }

            // Route to appropriate method
            if (!empty($videoUrls)) {
                // Video post — single video (TikTok API limitation: 1 video per post)
                return $this->publishVideo($accessToken, $content, $videoUrls[0]);
            }

            if (!empty($imageUrls)) {
                // Photo carousel — up to 35 images
                $images = array_slice($imageUrls, 0, 35);
                return $this->publishPhotos($accessToken, $content, $images);
            }

            return [
                'success' => false,
                'error' => 'TikTok requires at least one image or video in the post.',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Publish a video post via Content Posting API (direct upload).
     */
    private function publishVideo(string $accessToken, string $content, string $videoUrl): array
    {
        // Step 1: Initialize upload — get upload URL
        $videoSize = $this->getRemoteFileSize($videoUrl);
        $initResponse = $this->httpClient->post(
            'https://open.tiktokapis.com/v2/post/publish/video/init/',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json; charset=UTF-8',
                ],
                'json' => [
                    'post_info' => [
                        'title' => mb_substr($content, 0, 150),
                        'privacy_level' => 'PUBLIC_TO_EVERYONE',
                        'disable_duet' => false,
                        'disable_comment' => false,
                        'disable_stitch' => false,
                        'video_cover_timestamp_ms' => 1000,
                    ],
                    'source_info' => [
                        'source' => 'FILE_UPLOAD',
                        'video_size' => $videoSize,
                        'chunk_size' => $videoSize,
                        'total_chunk_count' => 1,
                    ],
                ],
            ]
        );

        $initBody = json_decode($initResponse->getBody()->getContents(), true);

        if (!isset($initBody['data']['publish_id']) || !isset($initBody['data']['upload_url'])) {
            return [
                'success' => false,
                'error' => 'TikTok video init failed: ' . ($initBody['error']['message'] ?? 'Unknown'),
            ];
        }

        $publishId = $initBody['data']['publish_id'];
        $uploadUrl = $initBody['data']['upload_url'];

        // Step 2: Upload video data (single chunk)
        $videoResponse = $this->httpClient->get($videoUrl);
        $videoData = $videoResponse->getBody()->getContents();
        $contentLength = strlen($videoData);

        $this->httpClient->put($uploadUrl, [
            'headers' => [
                'Content-Range' => "bytes 0-{$contentLength}-1/{$contentLength}",
                'Content-Length' => $contentLength,
            ],
            'body' => $videoData,
        ]);

        return [
            'success' => true,
            'external_id' => $publishId,
            'info' => 'Video uploaded. TikTok processes async.',
        ];
    }

    /**
     * Publish a photo carousel post via Content Posting API.
     *
     * TikTok photo posts use PUBLIC image URLs (no upload needed — TikTok
     * fetches the images from the URLs we provide). Up to 35 images per post.
     *
     * API: POST /v2/post/publish/content/init/
     *   post_info: { title, privacy_level, ... }
     *   source_info: { source: PULL_FROM_URL, post_image_urls: [...] }
     */
    private function publishPhotos(string $accessToken, string $content, array $imageUrls): array
    {
        $initResponse = $this->httpClient->post(
            'https://open.tiktokapis.com/v2/post/publish/content/init/',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json; charset=UTF-8',
                ],
                'json' => [
                    'post_info' => [
                        'title' => mb_substr($content, 0, 150),
                        'privacy_level' => 'PUBLIC_TO_EVERYONE',
                        'disable_duet' => false,
                        'disable_comment' => false,
                        'disable_stitch' => false,
                    ],
                    'source_info' => [
                        'source' => 'PULL_FROM_URL',
                        'post_image_urls' => $imageUrls,
                    ],
                ],
            ]
        );

        $body = json_decode($initResponse->getBody()->getContents(), true);

        if (!isset($body['data']['publish_id'])) {
            $errMsg = $body['error']['message'] ?? 'Unknown TikTok error';
            $errCode = $body['error']['code'] ?? 'UNKNOWN';
            return [
                'success' => false,
                'error' => "TikTok photo post failed ({$errCode}): {$errMsg}",
            ];
        }

        $publishId = $body['data']['publish_id'];
        $imgCount = count($imageUrls);

        return [
            'success' => true,
            'external_id' => $publishId,
            'info' => "Photo carousel with {$imgCount} image(s) uploaded. TikTok processes async.",
        ];
    }

    private function getRemoteFileSize(string $url): int
    {
        try {
            $response = $this->httpClient->head($url);
            return (int) $response->getHeaderLine('Content-Length');
        } catch (Exception $e) {
            return 0;
        }
    }
}
