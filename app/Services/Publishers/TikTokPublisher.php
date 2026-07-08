<?php

namespace App\Services\Publishers;

use Exception;
use GuzzleHttp\Client;

/**
 * TikTok Publisher — uploads videos via Content Posting API.
 *
 * Authentication: OAuth 2.0 with access_token. Scope: video.publish.
 *
 * API endpoints:
 *   - POST https://open.tiktokapis.com/v2/post/publish/video/init/ (init upload)
 *   - PUT {upload_url} (upload video data)
 *   - POST https://open.tiktokapis.com/v2/post/publish/status/fetch/ (check status)
 *
 * Reference: https://developers.tiktok.com/doc/content-posting-api-direct-post-video
 *
 * Note: TikTok Content Posting API requires app approval for production use.
 * In development mode, only test accounts can receive posts.
 *
 * @license Apache-2.0 (implemented from official API docs, not derived from third-party code)
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

            // TikTok requires a video file
            $videoUrl = null;
            foreach ($mediaUrls as $url) {
                if ($this->isVideoUrl($url)) {
                    $videoUrl = $url;
                    break;
                }
            }

            if (!$videoUrl) {
                return [
                    'success' => false,
                    'error' => 'TikTok requires at least one video file in the post.',
                ];
            }

            // Step 1: Initialize upload — get upload URL
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
                            'video_size' => $this->getRemoteFileSize($videoUrl),
                            'chunk_size' => $this->getRemoteFileSize($videoUrl),
                            'total_chunk_count' => 1,
                        ],
                    ],
                ]
            );

            $initBody = json_decode($initResponse->getBody()->getContents(), true);

            if (!isset($initBody['data']['publish_id']) || !isset($initBody['data']['upload_url'])) {
                return [
                    'success' => false,
                    'error' => 'TikTok init failed: ' . ($initBody['error']['message'] ?? 'Unknown'),
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
                'note' => 'Video uploaded. TikTok processes async — check status via publish_id.',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function isVideoUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, ['mp4', 'mov', 'avi', 'webm', 'mkv', 'flv']);
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
