<?php

namespace App\Services\Publishers;

use Exception;
use GuzzleHttp\Client;

/**
 * Facebook Pages Publisher — posts to Facebook Pages via Graph API.
 *
 * Authentication: User OAuth → exchange for Page access token via /me/accounts.
 * The Page access token (stored in SocialAccount.access_token) is used to publish.
 *
 * API endpoints used:
 *   - POST https://graph.facebook.com/v18.0/{page-id}/feed (text post)
 *   - POST https://graph.facebook.com/v18.0/{page-id}/photos (image post)
 *
 * Reference: https://developers.facebook.com/docs/pages-api
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
            'timeout' => 30,
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

            // Single image: use /photos with published=true (creates a post)
            if (count($mediaUrls) === 1) {
                return $this->publishSinglePhoto($baseUrl, $content, $mediaUrls[0], $pageAccessToken);
            }

            // Multiple images: use /feed with attached_media
            if (count($mediaUrls) > 1) {
                return $this->publishMultiPhotos($baseUrl, $content, $mediaUrls, $pageAccessToken);
            }

            // Text only: use /feed
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
}
