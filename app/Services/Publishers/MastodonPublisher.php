<?php

namespace App\Services\Publishers;

use Exception;
use GuzzleHttp\Client;

/**
 * Mastodon Publisher — posts toots to any Mastodon instance.
 *
 * Authentication: OAuth 2.0 with access_token. Each Mastodon instance (e.g.
 * mastodon.social, mas.to) has its own app registration — user must register
 * an app on their instance and provide MASTODON_URL + CLIENT_ID + CLIENT_SECRET.
 *
 * API endpoints (per instance):
 *   - POST {instance}/api/v1/media (upload media, returns id)
 *   - POST {instance}/api/v1/statuses (publish status with optional media_ids[])
 *
 * Reference: https://docs.joinmastodon.org/api/
 *
 * @license Apache-2.0 (implemented from official API docs, not derived from third-party code)
 */
class MastodonPublisher implements PublisherInterface
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
            $instance = $account['instance_url'] ?? config('services.mastodon.url', 'https://mastodon.social');
            $content = $post['content'];
            $mediaUrls = $post['media_urls'] ?? [];

            // Upload media if any
            $mediaIds = [];
            foreach ($mediaUrls as $url) {
                $mediaId = $this->uploadMedia($url, $instance, $accessToken);
                if ($mediaId) {
                    $mediaIds[] = $mediaId;
                }
            }

            // Post status
            $params = [
                'status' => $content,
                'visibility' => 'public',
            ];
            if (!empty($mediaIds)) {
                $params['media_ids[]'] = $mediaIds;
            }

            $response = $this->httpClient->post("{$instance}/api/v1/statuses", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'form_params' => $params,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!isset($body['id'])) {
                return ['success' => false, 'error' => 'Mastodon did not return status ID'];
            }

            return [
                'success' => true,
                'external_id' => $body['id'],
                'url' => $body['url'] ?? null,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function uploadMedia(string $url, string $instance, string $accessToken): ?string
    {
        try {
            $imageResponse = $this->httpClient->get($url);
            $imageData = $imageResponse->getBody()->getContents();
            $mimeType = $imageResponse->getHeaderLine('Content-Type');
            $extension = $this->getExtensionFromMime($mimeType);

            $uploadResponse = $this->httpClient->post("{$instance}/api/v1/media", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => $imageData,
                        'filename' => 'media.' . $extension,
                    ],
                ],
            ]);

            $body = json_decode($uploadResponse->getBody()->getContents(), true);

            return $body['id'] ?? null;
        } catch (Exception $e) {
            return null;
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
            default => 'bin',
        };
    }
}
