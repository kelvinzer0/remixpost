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
 *   - GET https://api.pinterest.com/v5/boards (list boards — for board selection)
 *   - POST https://api.pinterest.com/v5/pins (create pin with image_url)
 *
 * Reference: https://developers.pinterest.com/docs/api/v5/
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
            'timeout' => 30,
            'connect_timeout' => 10,
            'base_uri' => 'https://api.pinterest.com',
        ]);
    }

    public function publish(array $post, array $account): array
    {
        try {
            $accessToken = $account['access_token'];
            $boardId = $account['provider_id']; // Pinterest board ID
            $title = mb_substr($post['content'], 0, 100); // Pinterest title max 100 chars
            $description = mb_substr($post['content'], 0, 800); // Pinterest description max 800 chars
            $mediaUrls = $post['media_urls'] ?? [];

            // Pinterest requires at least one image
            if (empty($mediaUrls)) {
                return [
                    'success' => false,
                    'error' => 'Pinterest requires at least one image in the post.',
                ];
            }

            // Pinterest supports only single image per pin (standard pin)
            // Video pins use a different flow (requires upload via media API)
            $imageUrl = $mediaUrls[0];

            $pinData = [
                'board_id' => $boardId,
                'title' => $title,
                'description' => $description,
                'media_source' => [
                    'source_type' => 'image_url',
                    'url' => $imageUrl,
                ],
            ];

            $response = $this->httpClient->post('/v5/pins', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $pinData,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!isset($body['id'])) {
                return ['success' => false, 'error' => 'Pinterest did not return pin ID'];
            }

            return [
                'success' => true,
                'external_id' => $body['id'],
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
