<?php

namespace App\Services\Publishers;

use Exception;
use GuzzleHttp\Client;

/**
 * Instagram Business Publisher — posts to Instagram Business accounts via Graph API.
 *
 * Authentication: Instagram uses the same OAuth as Facebook. After connecting a
 * Facebook account with instagram_content_publish scope, fetch the IG business
 * account ID via /me/accounts → {page-id}?fields=instagram_business_account.
 *
 * Publishing flow (2-step):
 *   1. POST /{ig-user-id}/media — create media container (returns creation_id)
 *   2. POST /{ig-user-id}/media_publish — publish the container (returns media_id)
 *
 * Image posts: image_url required (must be public URL)
 * Video posts: video_url required (must be public URL)
 * Carousel posts: create multiple containers, then publish as carousel
 *
 * Reference: https://developers.facebook.com/docs/instagram-api/guides/content-publishing
 *
 * @license Apache-2.0 (implemented from official API docs, not derived from third-party code)
 */
class InstagramPublisher implements PublisherInterface
{
    private Client $httpClient;
    private string $apiVersion = 'v18.0';

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 60, // longer for video processing
            'connect_timeout' => 10,
        ]);
    }

    public function publish(array $post, array $account): array
    {
        try {
            $accessToken = $account['access_token'];
            $igUserId = $account['provider_id'];
            $caption = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];
            $tags = $post['tags'] ?? [];
            $firstComment = $post['first_comment'] ?? null;

            // Append tags as #hashtags to caption
            if (!empty($tags)) {
                $tagStr = implode(' ', array_map(fn($t) => '#' . preg_replace('/[^a-zA-Z0-9_]/', '', $t), $tags));
                $caption = rtrim($caption) . "\n\n" . $tagStr;
            }

            // Instagram requires at least one media item
            if (empty($mediaUrls)) {
                return [
                    'success' => false,
                    'error' => 'Instagram requires at least one image or video in the post.',
                ];
            }

            // Single media (image or video)
            if (count($mediaUrls) === 1) {
                return $this->publishSingleMedia($igUserId, $caption, $mediaUrls[0], $accessToken, $firstComment);
            }

            // Carousel (multiple images/videos) — up to 10 items
            $carouselUrls = array_slice($mediaUrls, 0, 10);
            return $this->publishCarousel($igUserId, $caption, $carouselUrls, $accessToken, $firstComment);
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function publishSingleMedia(string $igUserId, string $caption, string $mediaUrl, string $accessToken, ?string $firstComment = null): array
    {
        // Detect media type from URL extension
        $mediaType = $this->detectMediaType($mediaUrl);

        $params = [
            'caption' => $caption,
            'access_token' => $accessToken,
        ];

        if ($mediaType === 'video') {
            $params['media_type'] = 'REELS';
            $params['video_url'] = $mediaUrl;
        } else {
            $params['image_url'] = $mediaUrl;
        }

        // Step 1: Create media container
        $createResponse = $this->httpClient->post(
            "https://graph.facebook.com/{$this->apiVersion}/{$igUserId}/media",
            ['form_params' => $params]
        );

        $createBody = json_decode($createResponse->getBody()->getContents(), true);

        if (!isset($createBody['id'])) {
            return ['success' => false, 'error' => 'Instagram did not return container ID'];
        }

        $containerId = $createBody['id'];

        // Step 2: Publish the container
        return $this->publishContainer($igUserId, $containerId, $accessToken, $firstComment);
    }

    private function publishCarousel(string $igUserId, string $caption, array $mediaUrls, string $accessToken, ?string $firstComment = null): array
    {
        // Step 1: Create child media containers
        $childrenIds = [];
        foreach ($mediaUrls as $url) {
            $mediaType = $this->detectMediaType($url);
            $params = [
                'access_token' => $accessToken,
                'is_carousel_item' => 'true',
            ];

            if ($mediaType === 'video') {
                $params['media_type'] = 'VIDEO';
                $params['video_url'] = $url;
            } else {
                $params['image_url'] = $url;
            }

            $createResponse = $this->httpClient->post(
                "https://graph.facebook.com/{$this->apiVersion}/{$igUserId}/media",
                ['form_params' => $params]
            );

            $createBody = json_decode($createResponse->getBody()->getContents(), true);
            if (isset($createBody['id'])) {
                $childrenIds[] = $createBody['id'];
            }
        }

        if (empty($childrenIds)) {
            return ['success' => false, 'error' => 'Failed to create any carousel children'];
        }

        // Step 2: Create carousel container
        $carouselResponse = $this->httpClient->post(
            "https://graph.facebook.com/{$this->apiVersion}/{$igUserId}/media",
            [
                'form_params' => [
                    'media_type' => 'CAROUSEL',
                    'caption' => $caption,
                    'children' => implode(',', $childrenIds),
                    'access_token' => $accessToken,
                ],
            ]
        );

        $carouselBody = json_decode($carouselResponse->getBody()->getContents(), true);

        if (!isset($carouselBody['id'])) {
            return ['success' => false, 'error' => 'Failed to create carousel container'];
        }

        // Step 3: Publish carousel
        return $this->publishContainer($igUserId, $carouselBody['id'], $accessToken, $firstComment);
    }

    private function publishContainer(string $igUserId, string $containerId, string $accessToken, ?string $firstComment = null): array
    {
        $publishResponse = $this->httpClient->post(
            "https://graph.facebook.com/{$this->apiVersion}/{$igUserId}/media_publish",
            [
                'form_params' => [
                    'creation_id' => $containerId,
                    'access_token' => $accessToken,
                ],
            ]
        );

        $publishBody = json_decode($publishResponse->getBody()->getContents(), true);

        if (!isset($publishBody['id'])) {
            return ['success' => false, 'error' => 'Instagram did not return published media ID'];
        }

        $mediaId = $publishBody['id'];
        $info = null;

        // Post first comment if provided (IG supports comments via Graph API)
        if ($firstComment) {
            try {
                sleep(5); // wait for media to be fully published
                $commentResponse = $this->httpClient->post(
                    "https://graph.facebook.com/v18.0/{$mediaId}/comments",
                    [
                        'form_params' => [
                            'message' => $firstComment,
                            'access_token' => $accessToken,
                        ],
                    ]
                );
                $info = 'First comment posted';
            } catch (\Exception $e) {
                $info = 'First comment failed (non-critical): ' . $e->getMessage();
            }
        }

        return [
            'success' => true,
            'external_id' => $mediaId,
            'info' => $info,
        ];
    }

    private function detectMediaType(string $url): string
    {
        // Use shared MediaType helper for consistency across publishers.
        // Instagram Content Publishing API only accepts:
        //   - Images: jpg, jpeg, png
        //   - Videos: mp4 (mov may work in some cases)
        // Other formats will be rejected by the API with a clear error.
        return \App\Services\MediaType::fromUrl($url);
    }
}
