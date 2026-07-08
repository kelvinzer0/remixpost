<?php

namespace App\Services\Publishers;

use Exception;
use GuzzleHttp\Client;

/**
 * YouTube Publisher — uploads videos to YouTube via Data API v3.
 *
 * Authentication: Google OAuth 2.0 (user context). Scope: youtube.upload.
 * Uses the same Google OAuth credentials as other Google services.
 *
 * API endpoints:
 *   - POST https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable
 *     (initiate resumable upload session, returns upload URL)
 *   - PUT {upload_url} (upload video data in chunks)
 *
 * Reference: https://developers.google.com/youtube/v3/docs/videos/insert
 *
 * Note: YouTube only supports video uploads (no image-only posts).
 * If post has no video, we skip YouTube or convert text to a video (future).
 *
 * @license Apache-2.0 (implemented from official API docs, not derived from third-party code)
 */
class YouTubePublisher implements PublisherInterface
{
    private Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 300, // 5 min for large video uploads
            'connect_timeout' => 10,
        ]);
    }

    public function publish(array $post, array $account): array
    {
        try {
            $accessToken = $account['access_token'];
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];

            // YouTube requires a video file
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
                    'error' => 'YouTube requires at least one video file in the post.',
                ];
            }

            // Step 1: Initiate resumable upload
            $metadata = [
                'snippet' => [
                    'title' => mb_substr($content, 0, 100),
                    'description' => mb_substr($content, 0, 5000),
                    'tags' => [],
                    'categoryId' => '22', // People & Blogs
                ],
                'status' => [
                    'privacyStatus' => 'public',
                    'selfDeclaredMadeForKids' => false,
                ],
            ];

            $initResponse = $this->httpClient->post(
                'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type' => 'application/json',
                        'X-Upload-Content-Type' => 'video/*',
                    ],
                    'json' => $metadata,
                ]
            );

            $uploadUrl = $initResponse->getHeaderLine('Location');
            if (empty($uploadUrl)) {
                return ['success' => false, 'error' => 'YouTube did not return upload URL'];
            }

            // Step 2: Download video and upload to YouTube
            $videoResponse = $this->httpClient->get($videoUrl);
            $videoData = $videoResponse->getBody()->getContents();
            $videoMime = $videoResponse->getHeaderLine('Content-Type') ?: 'video/mp4';

            $uploadResponse = $this->httpClient->put($uploadUrl, [
                'headers' => [
                    'Content-Type' => $videoMime,
                    'Content-Length' => strlen($videoData),
                ],
                'body' => $videoData,
            ]);

            $body = json_decode($uploadResponse->getBody()->getContents(), true);

            if (!isset($body['id'])) {
                return ['success' => false, 'error' => 'YouTube did not return video ID'];
            }

            return [
                'success' => true,
                'external_id' => $body['id'],
                'url' => 'https://www.youtube.com/watch?v=' . $body['id'],
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
}
