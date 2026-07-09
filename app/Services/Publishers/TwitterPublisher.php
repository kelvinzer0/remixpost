<?php

namespace App\Services\Publishers;

use Abraham\TwitterOAuth\TwitterOAuth;
use Exception;
use GuzzleHttp\Client;

/**
 * Twitter/X Publisher — implements posting via Twitter API v2.
 *
 * Authentication: OAuth 2.0 PKCE (user context) with access_token + refresh_token.
 *
 * API endpoints used:
 *   - POST https://api.twitter.com/2/tweets (create tweet)
 *   - POST https://upload.twitter.com/1.1/media/upload.json (upload media)
 *
 * Reference: https://developer.twitter.com/en/docs/twitter-api
 *
 * @license Apache-2.0 (implemented from official API docs, not derived from third-party code)
 */
class TwitterPublisher implements PublisherInterface
{
    private Client $httpClient;

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
            $accessToken = $account['access_token'];
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];
            $tags = $post['tags'] ?? [];

            // Append tags as #hashtags to content
            if (!empty($tags)) {
                $tagStr = implode(' ', array_map(fn($t) => '#' . preg_replace('/[^a-zA-Z0-9_]/', '', $t), $tags));
                $content = rtrim($content) . ' ' . $tagStr;
            }

            // Upload media if any (Twitter API v1.1 media endpoint)
            $mediaIds = [];
            foreach ($mediaUrls as $url) {
                $mediaId = $this->uploadMedia($url, $accessToken);
                if ($mediaId) {
                    $mediaIds[] = $mediaId;
                }
            }

            // Create tweet via API v2
            $tweetData = ['text' => $content];
            if (!empty($mediaIds)) {
                $tweetData['media'] = ['media_ids' => $mediaIds];
            }

            $response = $this->httpClient->post('https://api.twitter.com/2/tweets', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $tweetData,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!isset($body['data']['id'])) {
                return [
                    'success' => false,
                    'error' => 'Twitter API did not return tweet ID',
                    'response' => $body,
                ];
            }

            return [
                'success' => true,
                'external_id' => $body['data']['id'],
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Upload media to Twitter using v1.1 media/upload endpoint.
     * The v2 API does not yet support media upload — must use v1.1 with OAuth 2.0 Bearer token.
     */
    private function uploadMedia(string $url, string $accessToken): ?string
    {
        try {
            // Download image
            $imageResponse = $this->httpClient->get($url);
            $imageData = $imageResponse->getBody()->getContents();
            $mimeType = $imageResponse->getHeaderLine('Content-Type');

            // Determine media category
            $mediaCategory = str_starts_with($mimeType, 'video/') ? 'tweet_video' : 'tweet_image';

            // Upload via multipart form
            $uploadResponse = $this->httpClient->post(
                'https://upload.twitter.com/1.1/media/upload.json',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                    'multipart' => [
                        [
                            'name' => 'media_category',
                            'contents' => $mediaCategory,
                        ],
                        [
                            'name' => 'media',
                            'contents' => $imageData,
                            'filename' => 'media',
                        ],
                    ],
                ]
            );

            $body = json_decode($uploadResponse->getBody()->getContents(), true);

            return $body['media_id_string'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
}
