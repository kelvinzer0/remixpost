<?php

namespace App\Services\Publishers;

use App\Services\MediaType;
use Exception;
use GuzzleHttp\Client;

/**
 * Facebook Pages Publisher — posts to Facebook Pages via Graph API.
 *
 * Authentication: User OAuth → exchange for Page access token via /me/accounts.
 * The Page access token (stored in SocialAccount.access_token) is used to publish.
 *
 * API endpoints used:
 *   - POST https://graph.facebook.com/v18.0/{page-id}/feed   (text post + multi-photo post)
 *   - POST https://graph.facebook.com/v18.0/{page-id}/photos (image staging or published photo)
 *   - POST https://graph.facebook.com/v18.0/{page-id}/videos (video upload, separate endpoint)
 *
 * Reference: https://developers.facebook.com/docs/pages-api
 *            https://developers.facebook.com/docs/videos
 *
 * Media handling:
 *   - Text only           → /feed with message only
 *   - Single image        → /photos with published=true
 *   - Single video        → /videos with file_url + description
 *   - Multiple images     → stage all via /photos, then /feed with attached_media
 *   - Mixed image+video   → publish first video via /videos, then images via separate /photos
 *                           (Facebook doesn't support mixed media in one post)
 *
 * @license Apache-2.0 (implemented from official API docs, not derived from third-party code)
 */
class FacebookPublisher implements PublisherInterface
{
    private Client $httpClient;
    private string $apiVersion = 'v18.0';

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
            $pageAccessToken = $account['access_token'];
            $pageId = $account['provider_id']; // Facebook Page ID
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];

            $baseUrl = "https://graph.facebook.com/{$this->apiVersion}/{$pageId}";

            // No media → text-only post
            if (empty($mediaUrls)) {
                return $this->publishTextOnly($baseUrl, $content, $pageAccessToken);
            }

            // Categorize media
            $categorized = MediaType::categorize($mediaUrls);
            $images = $categorized['images'];
            $videos = $categorized['videos'];

            // Single video → /videos endpoint
            if (count($videos) === 1 && empty($images)) {
                return $this->publishSingleVideo($baseUrl, $content, $videos[0], $pageAccessToken);
            }

            // Single image → /photos with published=true
            if (count($images) === 1 && empty($videos)) {
                return $this->publishSinglePhoto($baseUrl, $content, $images[0], $pageAccessToken);
            }

            // Multiple videos → publish first as /videos (Facebook doesn't support multi-video post)
            // Subsequent videos are skipped with warning (FB API limitation)
            if (count($videos) > 1 && empty($images)) {
                $result = $this->publishSingleVideo($baseUrl, $content, $videos[0], $pageAccessToken);
                if ($result['success']) {
                    $result['warning'] = 'Facebook only supports one video per post. Only the first video was published; ' . (count($videos) - 1) . ' video(s) skipped.';
                }
                return $result;
            }

            // Multiple images (no video) → stage + /feed with attached_media
            if (count($images) > 1 && empty($videos)) {
                return $this->publishMultiPhotos($baseUrl, $content, $images, $pageAccessToken);
            }

            // Mixed images + videos → publish first video via /videos, images as separate /photos
            // (Facebook has no API for mixed-media posts)
            if (!empty($videos) && !empty($images)) {
                return $this->publishMixedMedia($baseUrl, $content, $videos, $images, $pageAccessToken);
            }

            // Fallback (should not reach here)
            return $this->publishTextOnly($baseUrl, $content, $pageAccessToken);

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function publishTextOnly(string $baseUrl, string $message, string $accessToken): array
    {
        $response = $this->httpClient->post("{$baseUrl}/feed", [
            'form_params' => [
                'message' => $message,
                'access_token' => $accessToken,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        if (!isset($body['id'])) {
            return ['success' => false, 'error' => 'Facebook did not return post ID'];
        }

        return [
            'success' => true,
            'external_id' => $body['id'],
        ];
    }

    private function publishSinglePhoto(string $baseUrl, string $message, string $imageUrl, string $accessToken): array
    {
        $response = $this->httpClient->post("{$baseUrl}/photos", [
            'form_params' => [
                'message' => $message,
                'url' => $imageUrl,
                'published' => 'true',
                'access_token' => $accessToken,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        if (!isset($body['id'])) {
            return ['success' => false, 'error' => 'Facebook did not return photo post ID'];
        }

        return [
            'success' => true,
            'external_id' => $body['id'],
        ];
    }

    /**
     * Publish a single video to Facebook Page via /videos endpoint.
     * Note: 'description' is used for video (not 'message').
     */
    private function publishSingleVideo(string $baseUrl, string $description, string $videoUrl, string $accessToken): array
    {
        $response = $this->httpClient->post("{$baseUrl}/videos", [
            'form_params' => [
                'description' => $description,
                'file_url' => $videoUrl,
                'access_token' => $accessToken,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        if (!isset($body['id'])) {
            return ['success' => false, 'error' => 'Facebook did not return video post ID', 'response' => $body];
        }

        return [
            'success' => true,
            'external_id' => $body['id'],
        ];
    }

    private function publishMultiPhotos(string $baseUrl, string $message, array $imageUrls, string $accessToken): array
    {
        // Step 1: Upload each photo as unpublished (staged)
        $attachedMediaIds = [];
        foreach ($imageUrls as $url) {
            $uploadResponse = $this->httpClient->post("{$baseUrl}/photos", [
                'form_params' => [
                    'url' => $url,
                    'published' => 'false',
                    'access_token' => $accessToken,
                ],
            ]);
            $uploadBody = json_decode($uploadResponse->getBody()->getContents(), true);
            if (isset($uploadBody['id'])) {
                $attachedMediaIds[] = ['media_fbid' => $uploadBody['id']];
            }
        }

        if (empty($attachedMediaIds)) {
            return ['success' => false, 'error' => 'Failed to stage any photos'];
        }

        // Step 2: Create feed post with attached media
        $response = $this->httpClient->post("{$baseUrl}/feed", [
            'form_params' => [
                'message' => $message,
                'attached_media' => json_encode($attachedMediaIds),
                'access_token' => $accessToken,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        if (!isset($body['id'])) {
            return ['success' => false, 'error' => 'Facebook did not return multi-photo post ID'];
        }

        return [
            'success' => true,
            'external_id' => $body['id'],
        ];
    }

    /**
     * Publish mixed media: first video via /videos, then images as separate /photos.
     * Facebook doesn't support mixed image+video in a single post, so we create
     * one video post + one image post (with text on the video, no text on images).
     */
    private function publishMixedMedia(string $baseUrl, string $content, array $videoUrls, array $imageUrls, string $accessToken): array
    {
        // Publish first video with the caption
        $videoResult = $this->publishSingleVideo($baseUrl, $content, $videoUrls[0], $accessToken);
        if (!$videoResult['success']) {
            return $videoResult;
        }

        // Publish remaining images as a separate multi-photo post (no caption since it's already on video)
        if (count($imageUrls) === 1) {
            // Single image: publish as separate photo with empty message
            $imageResult = $this->publishSinglePhoto($baseUrl, '', $imageUrls[0], $accessToken);
        } else {
            $imageResult = $this->publishMultiPhotos($baseUrl, '', $imageUrls, $accessToken);
        }

        if (!$imageResult['success']) {
            // Video was posted, but images failed — partial success
            return [
                'success' => true,
                'external_id' => $videoResult['external_id'],
                'warning' => 'Video posted successfully, but image(s) failed: ' . ($imageResult['error'] ?? 'Unknown'),
            ];
        }

        return [
            'success' => true,
            'external_id' => $videoResult['external_id'],
            'warning' => 'Facebook does not support mixed media in one post. Created 2 separate posts: 1 video + ' . count($imageUrls) . ' image(s).',
        ];
    }
}
